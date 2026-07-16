<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\Country;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerCountryFilterTest extends TestCase
{
    use RefreshDatabase;

    /** Build a minimal valid Freelancer project payload. */
    private function project(int $id, string $currencyCountry, string $currencyCode): array
    {
        return [
            'id' => $id,
            'title' => 'Some clean project ' . $id,
            'description' => 'A clean project description.',
            'seo_url' => 'project-' . $id,
            'type' => 'fixed',
            'language' => 'en',
            'owner_id' => 1,
            'time_submitted' => 1700000000,
            'budget' => ['minimum' => 250, 'maximum' => 750],
            'currency' => ['code' => $currencyCode, 'sign' => '$', 'country' => $currencyCountry, 'exchange_rate' => 1],
            'upgrades' => ['NDA' => false, 'sealed' => false],
            'jobs' => [['id' => 1, 'name' => 'PHP']],
        ];
    }

    private function makeFilter(array $allowedIsoCodes): void
    {
        $filter = Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 1,
        ]);

        foreach ($allowedIsoCodes as $code) {
            $country = new Country();
            $country->country = $code;
            $country->language = $code;
            $country->save();
            $filter->countries()->attach($country->id);
        }
    }

    public function test_skips_project_from_non_whitelisted_country(): void
    {
        Queue::fake();
        $this->makeFilter(['US']); // only United States allowed

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [
                    $this->project(111, 'US', 'USD'), // allowed
                    $this->project(222, 'IN', 'INR'), // NOT allowed -> must be skipped
                ]],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $this->assertDatabaseHas('proposals', ['project_id' => 111]);
        $this->assertDatabaseMissing('proposals', ['project_id' => 222]);
    }

    public function test_uk_currency_country_maps_to_gb_whitelist(): void
    {
        Queue::fake();
        $this->makeFilter(['GB']); // United Kingdom whitelisted as ISO GB

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [
                    $this->project(333, 'UK', 'GBP'), // currency country 'UK' must be accepted for GB
                ]],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $this->assertDatabaseHas('proposals', ['project_id' => 333]);
    }

    public function test_euro_project_allowed_when_a_eurozone_country_whitelisted(): void
    {
        Queue::fake();
        $this->makeFilter(['DE']); // Germany whitelisted -> EUR/EU projects acceptable

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [
                    $this->project(444, 'EU', 'EUR'),
                ]],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $this->assertDatabaseHas('proposals', ['project_id' => 444]);
    }
}
