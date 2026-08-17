<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Looks up Freelancer users by id (api/users/0.1).
 *
 * This exists because projects/active does NOT return the project owner's name
 * or avatar — only their reputation, country and verification badges. Those are
 * on the users endpoint, which is the only place to get display_name, username
 * and avatar for a client we are talking to.
 */
class FreelancerUserClient
{
    /** Freelancer rejects very long query strings; keep each call modest. */
    private const CHUNK = 50;

    private function base(): string
    {
        return rtrim(config('variables.flBase'), '/').'/api/users/0.1';
    }

    private function client(): PendingRequest
    {
        return Http::timeout(30)->withHeaders([
            'Freelancer-OAuth-V1' => config('variables.flKey'),
        ]);
    }

    /**
     * Fetch identities for the given Freelancer user ids.
     *
     * @param  array<int, int|string>  $userIds
     * @return array<int, array{name: ?string, username: ?string, avatar: ?string}>
     *                                                                              keyed by user id; ids the API did not return are simply absent
     */
    public function fetch(array $userIds): array
    {
        $ids = collect($userIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $query = $chunk->map(fn ($id) => "users[]={$id}")->implode('&')
                .'&avatar=true&display_info=true&country_details=true&status=true&employer_reputation=true';

            try {
                $response = $this->client()->get($this->base().'/users/?'.$query);

                if (! $response->successful()) {
                    Log::warning('FreelancerUserClient: HTTP '.$response->status());

                    continue; // a bad chunk must not lose the good ones
                }

                foreach ($response->json('result.users') ?? [] as $id => $user) {
                    if (! is_array($user)) {
                        continue;
                    }

                    $out[(int) $id] = [
                        // display_name is what Freelancer shows on the project;
                        // public_name and username are progressively rougher
                        // fallbacks for accounts that hide it.
                        'name' => $user['display_name'] ?? $user['public_name'] ?? $user['username'] ?? null,
                        'username' => $user['username'] ?? null,
                        'avatar' => $this->avatar($user),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('FreelancerUserClient exception: '.$e->getMessage());
            }
        }

        return $out;
    }

    /**
     * Freelancer returns avatars protocol-relative (//cdn2.f-cdn.com/...).
     * Normalise to https so the value can go straight into an <img src>.
     */
    private function avatar(array $user): ?string
    {
        $url = $user['avatar_large_cdn'] ?? $user['avatar_cdn'] ?? $user['avatar'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return str_starts_with($url, '//') ? 'https:'.$url : $url;
    }
}
