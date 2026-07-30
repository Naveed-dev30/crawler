<?php

namespace App\Console\Commands;

use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Dev-only: fabricate a full proposal → bid → thread chain (with a client
 * insight) assigned to a user, so the mobile thread-detail response can be
 * exercised locally end-to-end. Never runs in production.
 */
class SeedTestThread extends Command
{
    protected $signature = 'test:seed-thread {user=26 : Assign the thread to this user id}';

    protected $description = 'Dev-only: seed a proposal + bid + thread (with client info) assigned to a user for local testing.';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $user = User::find($this->argument('user'));
        if (! $user) {
            $this->error("User {$this->argument('user')} not found.");

            return self::FAILURE;
        }

        $projectId = 900000000 + random_int(1, 8999999);

        $proposal = Proposal::factory()->create([
            'project_id' => $projectId,
            'title' => 'Boost Bubble Site Loading Speed',
            'description' => "My Bubble-built website is taking too long to display its pages. I'd like a full performance audit and fixes — caching, DB indexing, CDN/Cloudflare — to noticeably speed up load times.",
            'seo_url' => 'boost-bubble-site-loading-speed',
            'type' => 'fixed',
            'min_budget' => 10,
            'max_budget' => 30,
            'currency_symbol' => '$',
            'currency_name' => 'USD',
            'country' => 'Nigeria',
            'skills' => ['PHP', 'Web Services', 'Web Development', 'Bubble Developer', 'Cloudflare', 'Page Speed Optimization'],
        ]);

        Bid::factory()->create([
            'proposal_id' => $proposal->id,
            'price' => 20,
            'cover_letter' => 'Dear client, I can boost your Bubble site speed with caching, tuned DB searches and a Cloudflare CDN. Happy to start today.',
            'awarded' => false,
        ]);

        $thread = Thread::factory()->create([
            'project_id' => $projectId,
            'proposal_id' => $proposal->id,
            'assigned_user_id' => $user->id,
            'status' => 'answered',
            'blocked' => false,
            'last_client_message_at' => now(),
        ]);

        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'from_freelancer_user_id' => 111000,
            'sender_user_id' => null,
            'message' => 'Hi, I saw your bid on my project. Are you available to start this week?',
            'message_time' => now()->subMinutes(5),
            'is_read' => true,
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'sender_user_id' => $user->id,
            'from_freelancer_user_id' => null,
            'message' => 'Yes, I can start today and will share a short plan shortly.',
            'message_time' => now()->subMinutes(4),
            'sent_by_ai' => false,
        ]);

        BidInsight::updateOrCreate(['project_id' => $projectId], [
            'client_name' => 'Ado Client',
            'client_avatar' => 'https://cdn.f-cdn.com/avatars/sample.jpg',
            'client_country' => 'Nigeria',
            'client_country_flag' => '//cdn2.f-cdn.com/img/flags/png/ng.png',
            'client_rating' => 5.0,
            'client_reviews' => 1,
            'client_member_since' => now()->subYears(2),
            'client_verification' => [
                'identity_verified' => true, 'payment_verified' => true, 'email_verified' => true,
                'phone_verified' => true, 'profile_complete' => true, 'deposit_made' => true,
            ],
            'client_engagement' => ['contacted' => 0, 'invited' => 0, 'completed' => 1],
            'last_scraped_at' => now(),
        ]);

        $this->info("Seeded thread #{$thread->id} (project {$projectId}) assigned to {$user->name} (#{$user->id}).");
        $this->line("Now: GET /api/v1/mobile/threads/{$thread->id}  (with a mobile token for that user)");

        return self::SUCCESS;
    }
}
