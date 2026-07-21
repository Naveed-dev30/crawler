<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FcmPusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = []
    ) {
    }

    public function handle(FcmPusher $pusher): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $pusher->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
