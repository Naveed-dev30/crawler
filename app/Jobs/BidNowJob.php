<?php

namespace App\Jobs;

use App\Models\Bid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class BidNowJob implements ShouldQueue
{

    protected $bid;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(Bid $bid)
    {
        $this->bid = $bid;
    }

    public function handle()
    {

        try {
            $url = "https://www.freelancer.com/api/projects/0.1/bids/?compact=";

            $data = [
                "project_id" => $this->bid->proposal->project_id,
                "bidder_id" => 14053397,
                "amount" => $this->bid->price,
                "period" => 5,
                "milestone_percentage" => 30,
                "description" => $this->bid->cover_letter,
            ];

            $headers = [];

            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post($url, $data);

            if ($response->status() == 200) {
                $this->bid->bid_status = "Completed";
            } else {
                $this->bid->bid_status = "Failed";
            }


            $this->bid->save();
        } catch (\Exception $e) {
            $this->bid->bid_status = "Failed";
            $this->bid->save();
        }

    }
}
