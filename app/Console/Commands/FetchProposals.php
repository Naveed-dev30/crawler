<?php

namespace App\Console\Commands;

use App\Http\Controllers\ProposalController;
use Illuminate\Console\Command;

class FetchProposals extends Command
{
    protected $signature = 'proposals:fetch';

    protected $description = 'Fetch active Freelancer projects and generate proposals';

    public function handle(): int
    {
        (new ProposalController())->getProposals();
        $this->info('Proposal fetch complete.');

        return self::SUCCESS;
    }
}
