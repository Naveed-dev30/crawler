<?php

namespace App\Providers;

use App\Jobs\BidNowJob;
use App\Services\AiReplyGenerator;
use App\Services\Fake\FakeAiReplyGenerator;
use App\Services\Fake\FakeFreelancerMessenger;
use App\Services\FreelancerMessenger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('bidnowjob', BidNowJob::class);

        // FL_FAKE=true (dev only): serve fabricated Freelancer threads and
        // swallow outbound messages — no network traffic either way.
        if (config('variables.flFake')) {
            $this->app->bind(
                FreelancerMessenger::class,
                FakeFreelancerMessenger::class
            );

            // Same offline switch: fabricate AI replies without an OpenAI key
            // so the auto-reply pipeline runs end-to-end locally.
            $this->app->bind(
                AiReplyGenerator::class,
                FakeAiReplyGenerator::class
            );
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
