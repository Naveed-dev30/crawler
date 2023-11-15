<?php

namespace App\Jobs;

use App\Models\Bid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class FineTuneBidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Bid $bid;

    /**
     * Create a new job instance.
     */
    public function __construct(Bid $bid)
    {
        $this->bid = $bid;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';


        $prompt = $this->bid->cover_letter;

        $data = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
                [
                    'role' => 'user',
                    'content' => 'Copy write above content and concise it.',
                ],
            ],
        ];

        $response = Http::timeout(120)
            ->withHeaders(['Authorization' => $bearer])
            ->post($url, $data);

        $coverLetter = $response['choices'][0]['message']['content'];

        $this->bid->cover_letter = $coverLetter;
        $this->bid->save();

        BidNowJob::dispatch($this->bid)->delay(now()->addSeconds(30));
    }
}
