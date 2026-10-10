<?php

namespace Tests\Feature;

use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\ActivityImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_optimization_preserves_landscape_and_portrait_aspect_ratios(): void
    {
        if (! extension_loaded('imagick') || ! in_array('WEBP', Imagick::queryFormats('WEBP'), true)) {
            $this->markTestSkipped('Imagick with WebP support is required.');
        }

        Storage::fake('local');
        foreach ([[2400, 1200, 1600, 800], [1200, 2400, 800, 1600], [100, 50, 100, 50]] as [$width, $height, $expectedWidth, $expectedHeight]) {
            $source = new Imagick;
            $source->newImage($width, $height, 'red', 'PNG');
            $file = UploadedFile::fake()->createWithContent('photo.png', $source->getImageBlob());
            $source->clear();

            $prepared = app(ActivityImageService::class)->prepare($file);
            $this->assertSame($expectedWidth, $prepared['width']);
            $this->assertSame($expectedHeight, $prepared['height']);

            $stored = new Imagick;
            $stored->readImageBlob(Storage::disk('local')->get($prepared['path']));
            $this->assertSame('WEBP', $stored->getImageFormat());
            $this->assertSame($expectedWidth, $stored->getImageWidth());
            $this->assertSame($expectedHeight, $stored->getImageHeight());
            $stored->clear();
        }
    }

    public function test_image_configuration_is_off_by_default_and_cannot_be_changed_via_group_api(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $group = Group::factory()->create();
        $user = $this->member($group);
        Sanctum::actingAs($user);
        $this->getJson("/v1/groups/{$group->id}")
            ->assertOk()->assertJsonPath('data.images.enabled', false)
            ->assertJsonPath('data.images.quota_bytes', 1000000000);
        $this->patchJson("/v1/groups/{$group->id}", ['images_enabled' => true])->assertUnprocessable();
        $this->assertFalse($group->fresh()->images_enabled);
    }

    public function test_counts_and_gallery_obey_active_members_and_image_access(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        Storage::fake('local');
        $group = Group::factory()->create();
        $otherGroup = Group::factory()->create();
        $viewer = $this->member($group);
        $inactive = $this->member($group);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $viewer->id, 'category_id' => $category->id, 'activity_date' => '2026-10-10', 'content' => '写真付き']);
        $hiddenLog = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $inactive->id, 'category_id' => $category->id, 'activity_date' => '2026-10-10', 'content' => '非表示']);
        Storage::disk('local')->put('activity-images/test.webp', 'webp-bytes');
        $image = ActivityImage::query()->create(['group_id' => $group->id, 'activity_log_id' => $log->id, 'disk' => 'local', 'path' => 'activity-images/test.webp', 'bytes' => 10, 'width' => 10, 'height' => 10, 'mime' => 'image/webp']);
        ActivityImage::query()->create(['group_id' => $group->id, 'activity_log_id' => $hiddenLog->id, 'disk' => 'local', 'path' => 'activity-images/hidden.webp', 'bytes' => 10, 'width' => 10, 'height' => 10, 'mime' => 'image/webp']);
        GroupMember::query()->where('group_id', $group->id)->where('user_id', $inactive->id)->update(['status' => 'inactive']);

        Sanctum::actingAs($viewer);
        $this->getJson("/v1/groups/{$group->id}/logs/counts?from=2026-10-01&to=2026-10-31")
            ->assertOk()->assertJsonPath('data.2026-10-10', 1);
        $this->getJson("/v1/groups/{$group->id}/gallery?from=2026-10-01&to=2026-10-31")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.image.id', $image->id);
        $this->get("/v1/groups/{$group->id}/images/{$image->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get("/v1/groups/{$otherGroup->id}/images/{$image->id}")->assertNotFound();
    }

    public function test_gallery_uses_configured_page_size(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1'], 'famie.gallery_page_size' => 2]);
        $group = Group::factory()->create();
        $user = $this->member($group);
        Sanctum::actingAs($user);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        foreach (range(1, 3) as $index) {
            $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'category_id' => $category->id, 'activity_date' => '2026-10-10', 'content' => "写真{$index}"]);
            $log->image()->create(['group_id' => $group->id, 'disk' => 'local', 'path' => "activity-images/{$index}.webp", 'bytes' => 10, 'width' => 10, 'height' => 10, 'mime' => 'image/webp']);
        }

        $this->getJson("/v1/groups/{$group->id}/gallery?page=1")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('per_page', 2)->assertJsonPath('last_page', 2);
        $this->getJson("/v1/groups/{$group->id}/gallery?page=2")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_upload_is_rejected_when_images_are_disabled(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        $group = Group::factory()->create();
        Sanctum::actingAs($this->member($group));
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $this->postJson("/v1/groups/{$group->id}/logs", [
            'category_id' => $category->id, 'activity_date' => '2026-10-10', 'content' => '写真',
            'image' => UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg'),
        ])->assertStatus(422);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_image_upload_replace_quota_and_disable_lifecycle(): void
    {
        config(['famie.allowed_ips' => ['127.0.0.1']]);
        Storage::fake('local');
        $this->app->instance(ActivityImageService::class, new class extends ActivityImageService
        {
            public function prepare(UploadedFile $file): array
            {
                $path = 'activity-images/'.$file->hashName().'.webp';
                Storage::disk('local')->put($path, 'converted-webp');

                return ['disk' => 'local', 'path' => $path, 'bytes' => 14, 'width' => 10, 'height' => 10, 'mime' => 'image/webp'];
            }
        });
        $group = Group::factory()->create();
        $group->forceFill(['images_enabled' => true, 'image_quota_bytes' => 14])->save();
        Sanctum::actingAs($this->member($group));
        $category = Category::query()->create(['group_id' => $group->id, 'name' => '家事', 'color_code' => '#10B981', 'sort_order' => 1]);
        $payload = ['category_id' => $category->id, 'activity_date' => '2026-10-10', 'content' => '写真'];
        $requestId = '7d9d415c-ea2c-46c3-8fc3-76b05a6bdd21';
        $response = $this->postJson("/v1/groups/{$group->id}/logs", [...$payload, 'client_request_id' => $requestId, 'image' => UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg')])->assertCreated();
        $logId = $response->json('data.id');
        $this->assertDatabaseCount('activity_images', 1);
        $this->postJson("/v1/groups/{$group->id}/logs", [...$payload, 'client_request_id' => $requestId, 'image' => UploadedFile::fake()->create('photo.jpg', 1, 'image/jpeg')])
            ->assertOk()->assertJsonPath('data.id', $logId);
        $this->assertDatabaseCount('activity_logs', 1);
        $originalImageId = ActivityImage::query()->sole()->id;
        $this->post("/v1/groups/{$group->id}/logs/{$logId}", [
            '_method' => 'PUT',
            'revision' => '1',
            'image' => UploadedFile::fake()->create('replacement.jpg', 1, 'image/jpeg'),
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('activity_images', 1);
        $this->assertNotSame($originalImageId, ActivityImage::query()->sole()->id);
        $replacementImageId = ActivityImage::query()->sole()->id;
        $this->post("/v1/groups/{$group->id}/logs/{$logId}", [
            '_method' => 'PUT',
            'revision' => '1',
            'image' => UploadedFile::fake()->create('stale.jpg', 1, 'image/jpeg'),
        ])->assertStatus(409);
        $this->assertSame($replacementImageId, ActivityImage::query()->sole()->id);
        $this->postJson("/v1/groups/{$group->id}/logs", [...$payload, 'image' => UploadedFile::fake()->create('photo2.jpg', 1, 'image/jpeg')])->assertStatus(422);
        $this->assertDatabaseCount('activity_logs', 1);

        $this->putJson("/v1/groups/{$group->id}/logs/{$logId}", ['remove_image' => true])->assertOk();
        $this->assertDatabaseCount('activity_images', 0);
        $this->putJson("/v1/groups/{$group->id}/logs/{$logId}", ['revision' => 1, 'content' => '古い編集'])->assertStatus(409);
        $group->forceFill(['images_enabled' => false])->save();
        $this->putJson("/v1/groups/{$group->id}/logs/{$logId}", ['content' => '本文のみ'])->assertOk();
        $this->postJson("/v1/groups/{$group->id}/logs", [...$payload, 'image' => UploadedFile::fake()->create('photo3.jpg', 1, 'image/jpeg')])->assertStatus(422);
    }

    private function member(Group $group): User
    {
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);

        return $user;
    }
}
