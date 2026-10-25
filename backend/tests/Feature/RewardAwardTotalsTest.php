<?php

namespace Tests\Feature;

use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardAward;
use App\Models\RewardPeriod;
use App\Models\RewardVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardAwardTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    public function test_totals_count_all_pages_by_snapshot_including_deleted_and_read_awards(): void
    {
        $parent = GroupMember::factory()->create(['role' => 'admin']);
        $child = GroupMember::factory()->create(['group_id' => $parent->group_id]);
        foreach (['gold', 'gold', 'silver', ...array_fill(0, 10, 'bronze')] as $index => $color) {
            $award = $this->award($child, $color);
            if ($index === 0) {
                Reward::whereKey($award->reward_id)->update(['status' => 'deleted']);
                $award->update(['read_at' => now()]);
            }
        }
        $this->award($parent, 'gold');
        $this->award(GroupMember::factory()->create(), 'silver');
        Sanctum::actingAs($parent->user);
        $path = "/v1/groups/{$parent->group_id}/rewards/awards?member_id={$child->id}";

        foreach ([1 => 10, 2 => 3] as $page => $size) {
            $this->getJson($path.'&page='.$page)->assertOk()->assertJsonCount($size, 'data')
                ->assertJsonPath('total', 13)->assertJsonPath('meta.trophy_totals', ['gold' => 2, 'silver' => 1, 'bronze' => 10]);
        }
        $this->getJson($path.'&page=3')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.trophy_totals', ['gold' => 2, 'silver' => 1, 'bronze' => 10]);
        $this->getJson("/v1/groups/{$parent->group_id}/rewards/awards")->assertOk()
            ->assertJsonPath('meta.trophy_totals', ['gold' => 1, 'silver' => 0, 'bronze' => 0]);
    }

    public function test_empty_inventory_returns_three_integer_zeros(): void
    {
        $member = GroupMember::factory()->create();
        Sanctum::actingAs($member->user);

        $this->getJson("/v1/groups/{$member->group_id}/rewards/awards")->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.trophy_totals', ['gold' => 0, 'silver' => 0, 'bronze' => 0]);
    }

    public function test_totals_share_inventory_authentication_and_member_boundaries(): void
    {
        $parent = GroupMember::factory()->create(['role' => 'admin']);
        $child = GroupMember::factory()->create(['group_id' => $parent->group_id]);
        $inactive = GroupMember::factory()->create(['group_id' => $parent->group_id, 'status' => 'inactive']);
        $foreign = GroupMember::factory()->create();
        $this->award($parent, 'silver');
        $this->award($child, 'bronze');
        $path = "/v1/groups/{$parent->group_id}/rewards/awards";
        $this->getJson($path)->assertUnauthorized()->assertJsonMissingPath('meta');
        Sanctum::actingAs($child->user);
        $this->getJson($path)->assertOk()->assertJsonPath('meta.trophy_totals', ['gold' => 0, 'silver' => 0, 'bronze' => 1]);
        $this->getJson($path.'?member_id='.$parent->id)->assertForbidden()->assertJsonMissingPath('meta');
        Sanctum::actingAs($parent->user);
        foreach ([$inactive->id, $foreign->id, 999999] as $id) {
            $this->getJson($path.'?member_id='.$id)->assertNotFound()->assertJsonMissingPath('meta');
        }
        $this->getJson("/v1/groups/{$foreign->group_id}/rewards/awards")->assertNotFound()->assertJsonMissingPath('meta');
        $this->getJson($path.'?page=0')->assertUnprocessable()->assertJsonMissingPath('meta');
    }

    private function award(GroupMember $member, string $color): RewardAward
    {
        $reward = Reward::factory()->create(['group_id' => $member->group_id, 'status' => 'stopped']);
        $version = RewardVersion::factory()->create(['reward_id' => $reward->id, 'group_id' => $member->group_id, 'color' => 'silver']);
        $period = RewardPeriod::query()->create(['group_id' => $member->group_id, 'reward_id' => $reward->id,
            'reward_version_id' => $version->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'timezone' => 'UTC']);

        return RewardAward::factory()->create(['reward_period_id' => $period->id, 'group_member_id' => $member->id,
            'snapshot' => ['name' => 'Past award', 'color' => $color], 'achieved_count' => 500]);
    }
}
