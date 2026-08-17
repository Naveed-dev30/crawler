<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncFreelancerProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProfiles(array $profiles): void
    {
        Http::fake([
            '*/api/users/0.1/profiles*' => Http::response([
                'status' => 'success',
                'result' => ['profiles' => $profiles],
            ], 200),
        ]);
    }

    public function test_inserts_new_profiles(): void
    {
        $this->fakeProfiles([['id' => 11, 'title' => 'Web'], ['id' => 22, 'title' => 'Mobile']]);

        $this->artisan('profiles:sync')->assertExitCode(0);

        $this->assertSame(2, FreelancerProfile::count());
        $this->assertSame('Web', FreelancerProfile::find(11)->title);
    }

    public function test_updates_title_and_is_idempotent(): void
    {
        FreelancerProfile::create(['id' => 11, 'title' => 'Old']);
        $this->fakeProfiles([['id' => 11, 'title' => 'New Title']]);

        $this->artisan('profiles:sync')->assertExitCode(0);
        $this->artisan('profiles:sync')->assertExitCode(0);

        $this->assertSame(1, FreelancerProfile::count());
        $this->assertSame('New Title', FreelancerProfile::find(11)->title);
    }

    public function test_unchanged_profile_still_advances_the_last_synced_stamp(): void
    {
        $stale = FreelancerProfile::create(['id' => 11, 'title' => 'Web']);
        $stale->forceFill(['updated_at' => now()->subWeek()])->save();

        // Same title as what the API returns: updateOrCreate writes nothing, so
        // the settings page's "Last synced" (max updated_at) must still move.
        $this->fakeProfiles([['id' => 11, 'title' => 'Web']]);

        $this->artisan('profiles:sync')->assertExitCode(0);

        $this->assertTrue(
            FreelancerProfile::find(11)->updated_at->gt(now()->subMinute()),
            'updated_at should be refreshed even when the title did not change',
        );
    }

    public function test_never_deletes_missing_profiles(): void
    {
        FreelancerProfile::create(['id' => 99, 'title' => 'Keep me']);
        $this->fakeProfiles([['id' => 11, 'title' => 'Web']]);

        $this->artisan('profiles:sync')->assertExitCode(0);

        $this->assertNotNull(FreelancerProfile::find(99));
        $this->assertSame(2, FreelancerProfile::count());
    }

    public function test_api_failure_is_noop_exit_zero(): void
    {
        Http::fake(['*' => Http::response('err', 500)]);

        $this->artisan('profiles:sync')->assertExitCode(0);

        $this->assertSame(0, FreelancerProfile::count());
    }
}
