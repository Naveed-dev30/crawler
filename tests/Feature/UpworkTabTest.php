<?php

namespace Tests\Feature;

use App\Models\UpworkJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpworkTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/bids/upwork/data')->assertUnauthorized();
    }

    public function test_lists_jobs(): void
    {
        UpworkJob::factory()->create([
            'job_id' => '~0abc',
            'title' => 'Senior Laravel Engineer',
            'url' => 'https://www.upwork.com/jobs/~0abc',
            'job_type' => 'fixed',
            'budget_amount' => 750,
            'currency' => 'USD',
            'skills' => ['PHP', 'Laravel'],
            'client_country' => 'United States',
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/bids/upwork/data')->assertOk();

        $html = $res->json('rowsHtml');
        $this->assertStringContainsString('Senior Laravel Engineer', $html);
        $this->assertStringContainsString('https://www.upwork.com/jobs/~0abc', $html);
        $this->assertStringContainsString('Laravel', $html);
        $this->assertStringContainsString('United States', $html);
        $this->assertArrayHasKey('paginationHtml', $res->json());
    }

    public function test_empty_state(): void
    {
        $res = $this->actingAs(User::factory()->create())
            ->getJson('/bids/upwork/data')->assertOk();

        $this->assertStringContainsString('No Upwork jobs yet', $res->json('rowsHtml'));
    }
}
