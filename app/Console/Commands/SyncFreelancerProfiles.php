<?php

namespace App\Console\Commands;

use App\Models\FreelancerProfile;
use App\Services\FreelancerProfileClient;
use Illuminate\Console\Command;

class SyncFreelancerProfiles extends Command
{
    protected $signature = 'profiles:sync';

    protected $description = 'Sync the operator\'s Freelancer profiles into the local DB';

    public function handle(FreelancerProfileClient $client): int
    {
        $profiles = $client->fetch();

        foreach ($profiles as $profile) {
            $row = FreelancerProfile::updateOrCreate(
                ['id' => $profile['id']],
                ['title' => $profile['title']],
            );

            // updateOrCreate leaves updated_at alone when the title is
            // unchanged, so the settings page's "Last synced" stamp — which
            // reads max(updated_at) — would sit frozen at the first sync and
            // make a working sync look broken. Touch every row we saw.
            $row->touch();
        }

        $this->info('Synced '.count($profiles).' freelancer profiles.');

        return self::SUCCESS;
    }
}
