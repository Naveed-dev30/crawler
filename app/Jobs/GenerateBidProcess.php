<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\Filter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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

    $response = Http::timeout(60)
      ->withHeaders(['Authorization' => $bearer])
      ->post($url, $data);

    $coverLetter = $response['choices'][0]['message']['content'];

    $limit = 1450;

    if (strlen($coverLetter) > $limit) {
      $coverLetter = substr($coverLetter, 0, $limit) . "...";
    }

    $bid = new Bid();
    $bid->proposal_id = $this->proposal->id;
    $bid->price = ($this->proposal->max_budget) * 0.9;
    $bid->cover_letter = $coverLetter;
    $bid->save();
  }
}
