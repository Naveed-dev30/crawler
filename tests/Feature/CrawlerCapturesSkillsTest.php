<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerCapturesSkillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawler_stores_exchange_rate_and_skills(): void
    {
        Queue::fake(); // prevent OpenAIJob from running

        Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 0,
        ]);

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => [
                    'projects' => [[
                        'id' => 555,
                        'title' => 'Build a Laravel API',
                        'description' => 'Nice clean project description',
                        'seo_url' => 'build-laravel-api',
                        'type' => 'fixed',
                        'language' => 'en',
                        'owner_id' => 42,
                        'time_submitted' => 1700000000,
                        'budget' => ['minimum' => 250, 'maximum' => 750],
                        'currency' => ['code' => 'EUR', 'sign' => '€', 'country' => 'Germany', 'exchange_rate' => 1.1],
                        'upgrades' => ['NDA' => false, 'sealed' => false],
                        'jobs' => [
                            ['id' => 1, 'name' => 'PHP'],
                            ['id' => 2, 'name' => 'Laravel'],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        (new ProposalController)->getProposals();

        $proposal = Proposal::where('project_id', 555)->first();
        $this->assertNotNull($proposal);
        $this->assertEquals(1.1, $proposal->exchange_rate);
        $this->assertSame(['PHP', 'Laravel'], $proposal->skills);
    }

    public function test_skills_extracted_from_real_job_object_shape(): void
    {
        // Real projects/active jobs carry nested category/seo_url/local — only
        // the top-level name is the skill. Guards against pluck breaking on the
        // real payload shape.
        Queue::fake();

        Filter::factory()->create([
            'id' => 1, 'crawler_on' => 1, 'useminfix' => 0,
            'useminhour' => 0, 'usekeywords' => 0, 'usecountries' => 0,
        ]);

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [[
                    'id' => 40607899,
                    'title' => 'Commercial Construction Estimator / PM',
                    'description' => 'desc',
                    'seo_url' => 'commercial-construction',
                    'type' => 'fixed',
                    'language' => 'en',
                    'time_submitted' => 1700000000,
                    'budget' => ['minimum' => 250, 'maximum' => 750],
                    'currency' => ['code' => 'AUD', 'sign' => '$', 'country' => 'AU', 'exchange_rate' => 0.7],
                    'upgrades' => ['NDA' => false, 'sealed' => false],
                    'jobs' => [
                        ['id' => 37, 'name' => 'Project Management', 'category' => ['id' => 7, 'name' => 'Business'], 'seo_url' => 'project_management', 'local' => false],
                        ['id' => 55, 'name' => 'Excel', 'category' => ['id' => 4, 'name' => 'Data Entry'], 'seo_url' => 'excel', 'local' => false],
                    ],
                ]]],
            ], 200),
        ]);

        (new ProposalController)->getProposals();

        $proposal = Proposal::where('project_id', 40607899)->first();
        $this->assertNotNull($proposal);
        $this->assertSame(['Project Management', 'Excel'], $proposal->skills);
    }

    public function test_crawler_requests_job_details_so_skills_are_returned(): void
    {
        // The Freelancer projects/active endpoint omits job names (used for
        // skills) unless job_details=true is sent. Guard the outgoing request.
        Queue::fake();

        Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 0,
        ]);

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => []],
            ], 200),
        ]);

        (new ProposalController)->getProposals();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'projects/active')
                && str_contains($request->url(), 'job_details=1');
        });
    }
}
