<?php

namespace App\Console\Commands;

use App\Models\UpworkJob;
use App\Services\UpworkClient;
use Illuminate\Console\Command;

class FetchUpworkJobs extends Command
{
    protected $signature = 'upwork:fetch';

    protected $description = 'Fetch recent Upwork marketplace jobs';

    public function handle(UpworkClient $client): int
    {
        $rows = $client->fetchRecentJobs();

        foreach ($rows as $row) {
            if (empty($row['job_id'])) {
                continue;
            }
            UpworkJob::updateOrCreate(['job_id' => $row['job_id']], $row);
        }

        $this->info('Upwork fetch complete: '.count($rows).' jobs.');

        return self::SUCCESS;
    }
}
