<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\Filter;
use App\Models\FreelancerProfile;
use App\Models\Proposal;
use App\Services\ProposalQualifier;
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
        $bearer = 'Bearer '.config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $filter = Filter::find(1);

        if (! $filter->crawler_on) {
            return;
        }

        $negative = trim((string) $filter->negative_prompt);
        $profiles = FreelancerProfile::all()
            ->map(fn ($p) => ['id' => (int) $p->id, 'title' => (string) $p->title])
            ->all();

        $chosenProfileId = null;

        if ($negative !== '' || ! empty($profiles)) {
            $verdict = app(ProposalQualifier::class)->qualify($negative, $profiles, $this->proposal->description);
            $chosenProfileId = $verdict['profile_id'];

            // Negative-prompt gating only applies when the operator set skip criteria.
            if ($negative !== '') {
                $this->proposal->qualified = $verdict['qualified'];
                $this->proposal->qualify_reason = $verdict['reason'];
                $this->proposal->save();

                $summaryPrompt = trim((string) ($filter->summary_prompt ?? ''));
                if ($summaryPrompt !== '' && $verdict['reason'] !== '') {
                    SummarizeReasonJob::dispatch($this->proposal);
                }

                if (! $verdict['qualified']) {
                    return;
                }
            }
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
                    'content' => ' Description '.$this->proposal->description,
                ],
            ],
        ];

        $response = Http::timeout(120)
            ->withHeaders(['Authorization' => $bearer])
            ->post($url, $data);

        $coverLetter = $response['choices'][0]['message']['content'];

        $bid = new Bid;
        $bid->proposal_id = $this->proposal->id;
        $bid->profile_id = $chosenProfileId;
        $bid->price = $this->proposal->max_budget * 0.9;
        $bid->cover_letter = $coverLetter;
        $bid->save();
        $bid->get();

        FineTuneBidJob::dispatch($bid);
    }
}
