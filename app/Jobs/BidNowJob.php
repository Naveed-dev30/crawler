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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bid;

    public function __construct(Bid $bid)
    {
        $this->bid = $bid;
    }

    public function handle()
    {
        $this->bid->get();
        $this->bid->bid_status = "Handle";
        $this->bid->save();
        // Generate the bid parameters
        $data = [
            "project_id" => $this->bid->proposal->project_id,
            "bidder_id" => 14053397, // Replace with the ID of the bidder (your user ID or freelancer ID).
            "amount" => $this->bid->price,
            "period" => 5,
            "milestone_percentage" => 30,
            "description" => $this->bid->cover_letter,
        ];

        // Set the headers for the request
        $headers = [
            "content-type" => "application/json",
            "freelancer-oauth-v1" => env('FL_ACCESS'),
        ];

        try {
            $this->bid->bid_status = "Started";

            // Make the HTTP POST request to place the bid
            $url = "https://www.freelancer.com/api/projects/0.1/bids/?compact=";
            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post($url, $data);

            // Check the response status
            if ($response->status() == 200) {
                $this->bid->bid_status = "Completed";
            } else {
                $this->bid->bid_status = "Failed";
            }

            $this->bid->save();
        } catch (\Exception $e) {
            // Handle any exceptions and mark the bid as failed
            $this->bid->bid_status = "Failed";
            $this->bid->save();
        }

    }

}
