<?php

namespace Tests\Unit;

use App\Models\UpworkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpworkJobModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_and_persists_a_job(): void
    {
        $job = UpworkJob::create([
            'job_id' => '~0123abc',
            'title' => 'Build a Laravel API',
            'description' => 'Clean project',
            'url' => 'https://www.upwork.com/jobs/~0123abc',
            'job_type' => 'fixed',
            'budget_amount' => 500,
            'currency' => 'USD',
            'posted_at' => '2026-08-10 09:00:00',
            'skills' => ['PHP', 'Laravel'],
            'client_country' => 'United States',
            'client_total_spent' => 12000.5,
            'client_payment_verified' => true,
        ]);

        $fresh = $job->fresh();
        $this->assertSame(['PHP', 'Laravel'], $fresh->skills);
        $this->assertTrue($fresh->client_payment_verified);
        $this->assertEquals('2026-08-10', $fresh->posted_at->format('Y-m-d'));
        $this->assertSame(500.0, $fresh->budget_amount);
    }

    public function test_factory_creates_a_job(): void
    {
        $job = UpworkJob::factory()->create();
        $this->assertNotNull($job->job_id);
        $this->assertIsArray($job->skills);
    }
}
