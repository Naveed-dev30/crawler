<?php

namespace App\Http\Controllers;

use App\Models\PageCapture;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    private const SOURCES = ['insights_bids', 'insights'];

    public function ingest(Request $request)
    {
        $source = $request->input('source');
        $url = $request->input('url');
        $payload = $request->input('payload');

        if (! in_array($source, self::SOURCES, true) || ! is_string($url) || $url === '' || blank($payload)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $rawTs = $request->input('scraped_at');
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $payloadString = is_string($payload) ? $payload : json_encode($payload);
        $contentHash = hash('sha256', $payloadString);

        // Compare against the PREVIOUS capture for this source, not the same
        // (source, scraped_at) key -- that key is a fresh row on every daily
        // capture, which would make this flag always false.
        $previousHash = PageCapture::latestForSource($source)->value('content_hash');
        $unchanged = $previousHash === $contentHash;

        $capture = PageCapture::updateOrCreate(
            ['source' => $source, 'scraped_at' => $scrapedAt],
            ['url' => $url, 'payload' => $payloadString, 'content_hash' => $contentHash]
        );

        return response()->json([
            'success' => true,
            'id' => $capture->id,
            'unchanged' => $unchanged,
        ]);
    }
}
