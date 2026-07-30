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
            FreelancerProfile::updateOrCreate(
                ['id' => $profile['id']],
                ['title' => $profile['title']],
            );
        }

        $this->info('Synced '.count($profiles).' freelancer profiles.');

        return self::SUCCESS;
    }
}
