<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpworkClient
{
    /**
     * Fetch recent Upwork marketplace jobs and return them normalized to the
     * upwork_jobs column shape. Best-effort: returns [] on any failure.
     */
    public function fetchRecentJobs(int $limit = 50): array
    {
        if (! $this->accessToken() || ! config('variables.upworkRefreshToken')) {
            Log::warning('UpworkClient: missing credentials, skipping fetch.');

            return [];
        }

        try {
            $response = $this->post($this->searchQuery($limit));

            if ($response->status() === 401 && $this->refreshToken()) {
                $response = $this->post($this->searchQuery($limit));
            }

            if (! $response->successful()) {
                Log::warning('UpworkClient: fetch failed', ['status' => $response->status()]);

                return [];
            }

            $edges = data_get($response->json(), 'data.marketplaceJobPostingsSearch.edges', []);

            return array_map(fn ($edge) => $this->normalizeEdge($edge['node'] ?? []), $edges);
        } catch (\Throwable $e) {
            Log::warning('UpworkClient: exception during fetch', ['message' => $e->getMessage()]);

            return [];
        }
    }

    private function accessToken(): ?string
    {
        return Cache::get('upwork_access_token') ?: config('variables.upworkAccessToken');
    }

    private function post(string $query)
    {
        return Http::timeout(30)
            ->withToken($this->accessToken())
            ->withHeaders(['X-Upwork-API-TenantId' => config('variables.upworkTenantId')])
            ->post(config('variables.upworkBase'), ['query' => $query]);
    }

    private function refreshToken(): bool
    {
        $res = Http::asForm()->timeout(30)->post(config('variables.upworkTokenUrl'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => config('variables.upworkRefreshToken'),
            'client_id' => config('variables.upworkClientId'),
            'client_secret' => config('variables.upworkClientSecret'),
        ]);

        $token = data_get($res->json(), 'access_token');

        if ($res->successful() && $token) {
            Cache::forever('upwork_access_token', $token);

            return true;
        }

        Log::warning('UpworkClient: token refresh failed', ['status' => $res->status()]);

        return false;
    }

    private function searchQuery(int $limit): string
    {
        // No filter this iteration — list recent postings. Selection set is
        // finalized against the official Upwork GraphQL docs when live creds land.
        return <<<GQL
        query {
          marketplaceJobPostingsSearch(
            marketPlaceJobFilter: { pagination_eq: { first: {$limit} } }
            sortAttributes: [{ field: "RECENCY" }]
          ) {
            edges {
              node {
                id
                ciphertext
                title
                description
                amount { rawValue currency }
                hourlyBudgetMin { rawValue }
                hourlyBudgetMax { rawValue }
                createdDateTime
                skills { name }
                client {
                  location { country }
                  totalSpent { rawValue }
                  verificationStatus
                }
              }
            }
          }
        }
        GQL;
    }

    private function normalizeEdge(array $node): array
    {
        $cipher = data_get($node, 'ciphertext') ?: data_get($node, 'id');
        $budget = data_get($node, 'amount.rawValue');
        $hourlyMin = data_get($node, 'hourlyBudgetMin.rawValue');
        $hourlyMax = data_get($node, 'hourlyBudgetMax.rawValue');
        $isHourly = $hourlyMin !== null || $hourlyMax !== null;

        return [
            'job_id' => $cipher,
            'title' => data_get($node, 'title'),
            'description' => data_get($node, 'description'),
            'url' => $cipher ? 'https://www.upwork.com/jobs/'.$cipher : null,
            'job_type' => $isHourly ? 'hourly' : 'fixed',
            'budget_amount' => $isHourly ? null : ($budget !== null ? (float) $budget : null),
            'hourly_min' => $hourlyMin !== null ? (float) $hourlyMin : null,
            'hourly_max' => $hourlyMax !== null ? (float) $hourlyMax : null,
            'currency' => data_get($node, 'amount.currency'),
            'posted_at' => data_get($node, 'createdDateTime'),
            'skills' => collect(data_get($node, 'skills', []))->pluck('name')->filter()->values()->all(),
            'client_country' => data_get($node, 'client.location.country'),
            'client_total_spent' => ($s = data_get($node, 'client.totalSpent.rawValue')) !== null ? (float) $s : null,
            'client_payment_verified' => data_get($node, 'client.verificationStatus') === 'VERIFIED',
        ];
    }
}
