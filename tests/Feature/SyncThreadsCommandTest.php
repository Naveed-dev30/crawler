<?php

namespace Tests\Feature;

use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncThreadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_once_flag_runs_a_single_sync_pass(): void
    {
        $syncer = $this->mock(ThreadSyncer::class);
        $syncer->shouldReceive('run')->once();

        $this->artisan('threads:sync --once')->assertExitCode(0);
    }
}
