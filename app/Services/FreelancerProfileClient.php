<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FreelancerProfileClient
{
    /**
     * Fetch the operator's Freelancer profiles.
     *
     * @return array<int, array{id: int, title: string}> empty on any failure
     */
    public function fetch(): array
    {
        $url = rtrim((string) config('variables.flBase'), '/').'/api/users/0.1/profiles';

        try {
            $response = Http::timeout(60)
                ->withHeaders(['freelancer-oauth-v1' => (string) config('variables.flKey')])
                ->get($url, [
                    'user_id' => config('variables.flUserId'),
                    'compact' => 'true',
                ]);

            if (! $response->successful() || $response->json('status') !== 'success') {
                Log::warning('FreelancerProfileClient: bad response', ['status' => $response->status()]);

                return [];
            }

            $body = $response->json();
            Log::info('FreelancerProfileClient: raw profiles response', ['body' => $body]);

            $profiles = $body['result']['profiles'] ?? $body['result'] ?? [];
            if (! is_array($profiles)) {
                return [];
            }

            $out = [];
            foreach ($profiles as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $id = $entry['id'] ?? null;
                if (! is_numeric($id)) {
                    continue;
                }
                // Freelancer's profiles payload names the title `profile_name`.
                $title = $entry['profile_name'] ?? $entry['title'] ?? $entry['name'] ?? $entry['headline'] ?? $entry['tagline'] ?? '';
                $out[] = ['id' => (int) $id, 'title' => (string) $title];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('FreelancerProfileClient: exception '.$e->getMessage());

            return [];
        }
    }
}
