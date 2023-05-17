<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\Filter;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class GenerateBidProcess implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  protected $proposal;

  /**
   * Create a new job instance.
   */
  public function __construct($proposal)
  {
    $this->proposal = $proposal;
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {
    try {
      $bearer = 'Bearer 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';
      $url = 'https://api.openai.com/v1/chat/completions';

      $filter = Filter::find(1);
      $prompt = $filter->prompt;

      $proposal = $this->proposal;

      $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
          [
            'role' => 'system',
            'content' => $prompt,
          ],
          [
            'role' => 'user',
            'content' => ' Description ' . $proposal->description,
          ],
        ],
      ];

      $response = Http::withHeaders(['Authorization' => $bearer])->post($url, $data);

      $coverLetter = $response['choices'][0]['message']['content'];

      $bid = new Bid();
      $bid->proposal_id = $proposal->id;
      $bid->price = $proposal->max_budget * 0.9;
      $bid->cover_letter = $coverLetter;
      $bid->save();
      return;
    } catch (RequestException $e) {
      //
    }
  }
}
