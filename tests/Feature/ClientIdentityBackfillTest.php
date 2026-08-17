<?php

// tests/Feature/ClientIdentityBackfillTest.php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\Thread;
use App\Services\FreelancerUserClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientIdentityBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'k',
            'variables.flUserId' => '7032685',
        ]);
    }

    private function fakeUsers(array $users): void
    {
        Http::fake([
            '*users/0.1/users*' => Http::response(['result' => ['users' => $users]], 200),
        ]);
    }

    private function ownerPayload(int $id = 94080053): array
    {
        return [
            (string) $id => [
                'id' => $id,
                'username' => 'afk513',
                'display_name' => 'MisterJ',
                'public_name' => 'Jesse',
                'avatar_cdn' => '//cdn2.f-cdn.com/ppic/small.jpg',
                'avatar_large_cdn' => '//cdn2.f-cdn.com/ppic/large.png',
            ],
        ];
    }

    // ---- the client itself -------------------------------------------------

    public function test_user_client_reads_name_username_and_https_avatar(): void
    {
        $this->fakeUsers($this->ownerPayload());

        $out = app(FreelancerUserClient::class)->fetch([94080053]);

        $this->assertSame('MisterJ', $out[94080053]['name']);
        $this->assertSame('afk513', $out[94080053]['username']);
        // Prefers the large avatar and normalises Freelancer's protocol-relative url.
        $this->assertSame('https://cdn2.f-cdn.com/ppic/large.png', $out[94080053]['avatar']);
    }

    public function test_user_client_falls_back_through_the_name_fields(): void
    {
        $this->fakeUsers(['5' => ['id' => 5, 'username' => 'quietuser']]);

        $this->assertSame('quietuser', app(FreelancerUserClient::class)->fetch([5])[5]['name']);
    }

    public function test_user_client_returns_empty_on_failure_rather_than_throwing(): void
    {
        Http::fake(['*users/0.1/users*' => Http::response([], 500)]);

        $this->assertSame([], app(FreelancerUserClient::class)->fetch([1, 2, 3]));
    }

    public function test_user_client_skips_the_call_when_there_is_nothing_to_look_up(): void
    {
        Http::fake();

        $this->assertSame([], app(FreelancerUserClient::class)->fetch([]));
        Http::assertNothingSent();
    }

    // ---- the backfill command ---------------------------------------------

    public function test_backfill_uses_the_client_id_stored_on_the_thread(): void
    {
        $this->fakeUsers($this->ownerPayload());
        $proposal = Proposal::factory()->create(['project_id' => 40651285]);
        Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
            'client_user_id' => 94080053,
        ]);

        $this->artisan('clients:backfill --days=20')->assertExitCode(0);

        $insight = BidInsight::where('project_id', 40651285)->sole();
        $this->assertSame('MisterJ', $insight->client_name);
        $this->assertSame('afk513', $insight->client_username);
        $this->assertSame('https://cdn2.f-cdn.com/ppic/large.png', $insight->client_avatar);
    }

    public function test_backfill_recovers_the_client_id_from_the_messages_api(): void
    {
        Http::fake([
            // members: us + the client. Only the client should be looked up.
            '*messages/0.1/threads*' => Http::response([
                'result' => ['threads' => [[
                    'id' => 436558655,
                    'thread' => ['members' => [7032685, 94080053], 'owner' => 94080053],
                ]]],
            ], 200),
            '*users/0.1/users*' => Http::response(['result' => ['users' => $this->ownerPayload()]], 200),
        ]);

        $proposal = Proposal::factory()->create(['project_id' => 40651285]);
        $thread = Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
            'freelancer_thread_id' => 436558655,
            'client_user_id' => null,
        ]);

        $this->artisan('clients:backfill --days=20')->assertExitCode(0);

        // Recovered id is persisted so the next run needs no extra API call.
        $this->assertSame(94080053, (int) $thread->fresh()->client_user_id);
        $this->assertSame('MisterJ', BidInsight::where('project_id', 40651285)->sole()->client_name);
    }

    public function test_backfill_respects_the_days_window(): void
    {
        $this->fakeUsers($this->ownerPayload());
        $proposal = Proposal::factory()->create(['project_id' => 40651285]);
        $old = Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
            'client_user_id' => 94080053,
        ]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->artisan('clients:backfill --days=20')->assertExitCode(0);

        $this->assertSame(0, BidInsight::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeUsers($this->ownerPayload());
        $proposal = Proposal::factory()->create(['project_id' => 40651285]);
        Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
            'client_user_id' => 94080053,
        ]);

        $this->artisan('clients:backfill --days=20 --dry-run')->assertExitCode(0);

        $this->assertSame(0, BidInsight::count());
    }

    /**
     * The crawler already stores reputation, country and verification for a
     * project. Adding a name must not blank any of it.
     */
    public function test_backfill_preserves_existing_insight_fields(): void
    {
        $this->fakeUsers($this->ownerPayload());
        $proposal = Proposal::factory()->create(['project_id' => 40651285]);
        Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
            'client_user_id' => 94080053,
        ]);
        BidInsight::create([
            'project_id' => 40651285,
            'client_country' => 'Germany',
            'client_rating' => 4.8,
            'client_reviews' => 37,
            'last_scraped_at' => now()->subDay(),
        ]);

        $this->artisan('clients:backfill --days=20')->assertExitCode(0);

        $insight = BidInsight::where('project_id', 40651285)->sole();
        $this->assertSame('MisterJ', $insight->client_name);
        $this->assertSame('Germany', $insight->client_country);
        $this->assertEquals(4.8, (float) $insight->client_rating);
        $this->assertSame(37, (int) $insight->client_reviews);
    }

    public function test_backfill_also_covers_proposals_that_kept_an_owner_id(): void
    {
        $this->fakeUsers($this->ownerPayload(42));
        Proposal::factory()->create(['project_id' => 999001, 'project_owner' => 42]);

        $this->artisan('clients:backfill --days=20')->assertExitCode(0);

        $this->assertSame('MisterJ', BidInsight::where('project_id', 999001)->sole()->client_name);
    }
}
