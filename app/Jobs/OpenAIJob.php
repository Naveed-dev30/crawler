<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class OpenAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $proposal;

    public function __construct(Proposal $proposal)
    {
        $this->proposal = $proposal;
    }

    public function handle(): void
    {
        $bearer = 'Bearer ' . env('OPENAI_API_KEY');
        $url = 'https://api.openai.com/v1/chat/completions';


        $filter = Filter::find(1);

        if (!$filter->crawler_on) {
            return;
        }

        $prompt = $filter->prompt;

        $data = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt,
                ],
                [
                    'role' => 'user',
                    'content' => ' Description ' . $this->proposal->description,
                ],
            ],
        ];

        $response = Http::timeout(120)
            ->withHeaders(['Authorization' => $bearer])
            ->post($url, $data);

        \Log::critical($response);

        $coverLetter = $response['choices'][0]['message']['content'];

        $bid = new Bid();
        $bid->proposal_id = $this->proposal->id;
        $bid->price = $this->proposal->max_budget * 0.9;
        $bid->cover_letter = $coverLetter;
        $bid->save();
        $bid->get();

        FineTuneBidJob::dispatch($bid);
    }
}
