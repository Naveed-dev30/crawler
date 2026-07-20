<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\Country;
use App\Models\Filter;
use App\Models\NegativeKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerNegativeKeywordRemovalTest extends TestCase
{
    use RefreshDatabase;

    /** Build a minimal valid Freelancer project payload. */
    private function project(int $id, string $title, string $description): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'seo_url' => 'project-' . $id,
            'type' => 'fixed',
            'language' => 'en',
            'owner_id' => 1,
            'time_submitted' => 1700000000,
            'budget' => ['minimum' => 250, 'maximum' => 750],
            'currency' => ['code' => 'USD', 'sign' => '$', 'country' => 'US', 'exchange_rate' => 1],
            'upgrades' => ['NDA' => false, 'sealed' => false],
            'jobs' => [['id' => 1, 'name' => 'PHP']],
        ];
    }

    public function test_project_with_negative_keyword_term_is_no_longer_skipped(): void
    {
        Queue::fake();

        $filter = Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 1,
        ]);
        $country = new Country();
        $country->country = 'US';
        $country->language = 'US';
        $country->save();
        $filter->countries()->attach($country->id);

        // Table still exists; entries must be inert now.
        $nk = new NegativeKeyword();
        $nk->name = 'gambling';
        $nk->save();

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [
                    $this->project(901, 'Build a gambling website', 'A gambling platform project.'),
                ]],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $this->assertDatabaseHas('proposals', ['project_id' => 901]);
    }
}
