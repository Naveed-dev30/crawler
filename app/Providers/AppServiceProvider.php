<?php

namespace App\Providers;

use App\Jobs\BidNowJob;
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
                \App\Services\FreelancerMessenger::class,
                \App\Services\Fake\FakeFreelancerMessenger::class
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
