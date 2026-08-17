<?php

namespace App\Console\Commands;

use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\Thread;
use App\Services\FreelancerMessenger;
use App\Services\FreelancerUserClient;
use Illuminate\Console\Command;

/**
 * Fills in the client name / username / avatar that projects/active never
 * returned, so historical chats show who we are talking to.
 *
 * Two sources, in order of reliability:
 *   1. threads.client_user_id — captured from the thread's members list.
 *   2. proposals.project_owner — only present for projects crawled before the
 *      owner_id regression (fixed in ProposalController).
 *
 * Threads whose client id was never captured are re-fetched from the messages
 * API, which still returns members for closed projects.
 */
class BackfillClientIdentities extends Command
{
    protected $signature = 'clients:backfill
        {--days=20 : How far back to look, by thread/proposal creation date}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill client name, username and avatar on bid_insights';

    public function handle(FreelancerUserClient $users, FreelancerMessenger $messenger): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $since = now()->subDays($days);

        $this->info(($dry ? '[dry run] ' : '')."Backfilling client identities from the last {$days} days.");

        // project_id => client Freelancer user id
        $targets = [];

        foreach (Thread::where('created_at', '>=', $since)->get() as $thread) {
            $clientId = (int) $thread->client_user_id;

            if (! $clientId) {
                $clientId = $this->clientIdFromMessagesApi($messenger, $thread);

                if ($clientId && ! $dry) {
                    $thread->client_user_id = $clientId;
                    $thread->save();
                }
            }

            if ($clientId) {
                $targets[(int) $thread->project_id] = $clientId;
            }
        }

        // Older projects crawled while owner_id still came through.
        Proposal::where('created_at', '>=', $since)
            ->whereNotNull('project_owner')
            ->where('project_owner', '>', 0)
            ->each(function (Proposal $proposal) use (&$targets) {
                $targets[(int) $proposal->project_id] ??= (int) $proposal->project_owner;
            });

        if ($targets === []) {
            $this->warn('No projects with a resolvable client id in that window.');

            return self::SUCCESS;
        }

        $this->line('Resolved '.count($targets).' project(s) to a client id.');

        $identities = $users->fetch(array_values($targets));

        $this->line('Freelancer returned '.count($identities).' user profile(s).');

        $written = 0;

        foreach ($targets as $projectId => $clientId) {
            $identity = $identities[$clientId] ?? null;

            if (! $identity || ! $identity['name']) {
                continue;
            }

            if ($dry) {
                $this->line("  would set project {$projectId} → {$identity['name']}");
                $written++;

                continue;
            }

            // Only identity fields — never clobber the reputation/country data
            // the crawler already stored for this project.
            BidInsight::updateOrCreate(
                ['project_id' => $projectId],
                array_filter([
                    'client_name' => $identity['name'],
                    'client_username' => $identity['username'],
                    'client_avatar' => $identity['avatar'],
                    'last_scraped_at' => now(),
                ], fn ($v) => $v !== null),
            );

            $written++;
        }

        $this->info(($dry ? 'Would update ' : 'Updated ').$written.' project(s).');

        return self::SUCCESS;
    }

    /**
     * Ask the messages API who else is in this thread. Returns 0 when the call
     * fails or the payload has no usable member — the caller just skips it.
     */
    private function clientIdFromMessagesApi(FreelancerMessenger $messenger, Thread $thread): int
    {
        $payload = $messenger->fetchThread((int) $thread->freelancer_thread_id);

        if (! is_array($payload)) {
            return 0;
        }

        $ourId = (int) config('variables.flUserId');
        $members = $payload['thread']['members'] ?? $payload['members'] ?? [];

        return (int) collect($members)
            ->map(fn ($id) => (int) $id)
            ->first(fn ($id) => $id !== $ourId && $id > 0);
    }
}
