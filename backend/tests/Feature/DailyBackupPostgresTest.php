<?php

namespace Tests\Feature;

use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\DailyBackup;
use App\Services\OperationState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PharData;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class DailyBackupPostgresTest extends TestCase
{
    use IsolatesOperationsStorage;

    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FAMIE_BACKUP_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires explicitly enabled isolated PostgreSQL backup schema and pg_dump/pg_restore.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertContains(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost']);
        $this->schema = 'famie_backup_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
        DB::statement('CREATE SCHEMA '.$this->schema);
        DB::select("select set_config('search_path', ?, false)", [$this->schema]);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::select("select set_config('search_path', 'public', false)");
            DB::statement('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    public function test_database_and_image_snapshot_restore_in_an_isolated_schema(): void
    {
        $path = $this->isolateOperationsStorage();
        $imageRoot = $path.'/fixtures';
        mkdir($imageRoot.'/activity-images', 0700, true);
        config(['filesystems.disks.local.root' => $imageRoot, 'famie.image_disk' => 'local']);
        $group = Group::factory()->create();
        $user = User::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin']);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Fixture', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'category_id' => $category->id, 'activity_date' => '2026-10-26', 'content' => 'Backup fixture']);
        file_put_contents($imageRoot.'/activity-images/fixture.webp', 'fixture-image-content');
        $image = ActivityImage::query()->create(['activity_log_id' => $log->id, 'group_id' => $group->id, 'disk' => 'local', 'path' => 'activity-images/fixture.webp', 'mime' => 'image/webp', 'bytes' => 21, 'width' => 1, 'height' => 1]);
        $otherGroup = Group::factory()->create();
        $otherMember = GroupMember::factory()->create(['group_id' => $otherGroup->id, 'role' => 'admin']);

        $id = app(DailyBackup::class)->run();
        $snapshot = app(OperationState::class)->directory().'/backups/'.$id;
        $manifest = json_decode(file_get_contents($snapshot.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', $snapshot.'/'.$file));
        }
        $connection = DB::connection()->getConfig();
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        $restored = Process::env(['PGPASSWORD' => (string) $connection['password']])->timeout(60)->run([
            'pg_restore', '--host='.(string) $connection['host'], '--port='.(string) $connection['port'],
            '--username='.(string) $connection['username'], '--dbname='.(string) $connection['database'],
            '--no-owner', '--no-privileges', '--exit-on-error', $snapshot.'/database.dump',
        ]);
        $this->assertTrue($restored->successful(), 'Isolated fixture restore failed.');
        DB::select("select set_config('search_path', ?, false)", [$this->schema]);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Backup fixture']);
        $this->assertDatabaseHas('activity_images', ['activity_log_id' => $log->id, 'path' => 'activity-images/fixture.webp']);
        $archive = new PharData($snapshot.'/images-local.tar');
        $archive->extractTo($path.'/restored-images');
        $this->assertSame('fixture-image-content', file_get_contents($path.'/restored-images/activity-images/fixture.webp'));
        config(['filesystems.disks.local.root' => $path.'/restored-images']);
        Storage::forgetDisk('local');
        config(['famie.ip_restriction_enabled' => false]);
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])->assertOk();
        Sanctum::actingAs($user->fresh());
        $this->getJson("/v1/groups/{$group->id}/images/{$image->id}")->assertOk()->assertStreamedContent('fixture-image-content');
        Sanctum::actingAs($otherMember->user);
        $this->getJson("/v1/groups/{$group->id}/images/{$image->id}")->assertNotFound();
    }
}
