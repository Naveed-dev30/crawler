<?php

namespace App\Services\Fake;

use App\Services\FreelancerUserClient;

/**
 * Offline stand-in for the Freelancer users endpoint (FL_FAKE=true).
 *
 * Fabricates a stable identity per user id so the chat panel shows a named
 * client locally, without the network call the real client would make.
 */
class FakeFreelancerUserClient extends FreelancerUserClient
{
    private const NAMES = ['Ada Client', 'Bruno Vega', 'Chen Wei', 'Dara Okafor', 'Elif Kaya'];

    public function fetch(array $userIds): array
    {
        $out = [];

        foreach (array_unique(array_filter($userIds)) as $id) {
            $id = (int) $id;
            $name = self::NAMES[$id % count(self::NAMES)];

            $out[$id] = [
                'name' => $name,
                'username' => strtolower(str_replace(' ', '', $name)).$id,
                'avatar' => null,
            ];
        }

        return $out;
    }
}
