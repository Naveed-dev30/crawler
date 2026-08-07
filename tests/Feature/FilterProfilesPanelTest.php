<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FilterProfilesPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_config_page_lists_synced_profiles(): void
    {
        Filter::factory()->create(['id' => 1]);
        FreelancerProfile::create(['id' => 101, 'title' => 'Web Development']);

        $this->actingAs($this->admin())->get('/filters')
            ->assertOk()
            ->assertSee('Web Development')
            ->assertSee('#101');
    }

    public function test_sync_now_route_upserts_profiles(): void
    {
        Filter::factory()->create(['id' => 1]);
        Http::fake([
            '*/api/users/0.1/profiles*' => Http::response([
                'status' => 'success',
                'result' => ['profiles' => [['id' => 55, 'title' => 'SEO']]],
            ], 200),
        ]);

        $this->actingAs($this->admin())->post('/profiles/sync')->assertRedirect();

        $this->assertNotNull(FreelancerProfile::find(55));
    }
}
