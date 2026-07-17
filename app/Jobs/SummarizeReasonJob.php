<?php

namespace App\Jobs;

use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SummarizeReasonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Proposal $proposal;

    public function __construct(Proposal $proposal)
    {
        $this->proposal = $proposal;
    }

    public function handle(): void
    {
        $filter = Filter::find(1);
        $summaryPrompt = trim((string) ($filter?->summary_prompt ?? ''));
        if ($summaryPrompt === '') {
            return; // gated: no summary prompt configured
        }

        $reason = trim((string) $this->proposal->qualify_reason);
        if ($reason === '') {
            return; // nothing to summarize
        }

        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => 'gpt-3.5-turbo',
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $summaryPrompt],
                ['role' => 'user', 'content' => $reason],
            ],
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Authorization' => $bearer])
                ->post($url, $data);

            if ($response->successful()) {
                $summary = trim((string) $response->json('choices.0.message.content'));
                if ($summary !== '') {
                    $this->proposal->qualify_summary = $summary;
                    $this->proposal->save();
                }
            } else {
                Log::warning('SummarizeReasonJob: HTTP ' . $response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('SummarizeReasonJob: ' . $e->getMessage());
        }
    }
}
