<?php

namespace App\Services;

use App\Models\BidInsight;
use Carbon\Carbon;

/**
 * Maps a Freelancer project's owner ("About the client") into bid_insights,
 * keyed by project_id. Shared by the live crawl (ProposalController) and the
 * client:backfill command so the mapping lives in one place.
 *
 * Only non-null fields are written, so a later extension ingest (or an
 * earlier one) is never clobbered with blanks. Creates the row when absent —
 * otherwise the mobile thread's client block stays null for crawler projects.
 */
class ClientInsightWriter
{
    /**
     * @param  array  $project  a single project payload (with owner_info)
     * @param  array  $users  optional result.users map, used only as a fallback
     * @return bool  whether any client attributes were written
     */
    public function store(array $project, array $users = []): bool
    {
        // Prefer the owner object attached directly by owner_info=true; fall
        // back to the users map (owner_id) when only that projection is present.
        $owner = $project['owner_info'] ?? null;
        if (! is_array($owner)) {
            $ownerId = $project['owner_id'] ?? null;
            $owner = $ownerId !== null ? ($users[$ownerId] ?? $users[(string) $ownerId] ?? null) : null;
        }

        if (! is_array($owner)) {
            return false;
        }

        // Real owner_info shape (verified against a live projects/active call):
        // reputation.entire_history holds rating/reviews/complete; country is a
        // top-level object; status carries the verification badges. Name/avatar
        // are PII and only appear on token-authenticated requests.
        $history = $owner['reputation']['entire_history'] ?? [];
        $country = $owner['country'] ?? [];

        $engagement = is_array($project['client_engagement'] ?? null)
            ? $project['client_engagement']
            : array_filter([
                'completed' => $history['complete'] ?? null,
                'invited' => isset($project['invited_freelancers']) ? count($project['invited_freelancers']) : null,
            ], fn ($v) => $v !== null);

        $registered = $owner['registration_date'] ?? null;

        $attributes = array_filter([
            'client_name' => $owner['display_name'] ?? $owner['public_name'] ?? $owner['username'] ?? null,
            'client_avatar' => $owner['avatar_large_cdn'] ?? $owner['avatar_cdn'] ?? $owner['avatar'] ?? null,
            'client_country' => $country['name'] ?? null,
            'client_country_flag' => $country['flag_url_cdn'] ?? $country['flag_url'] ?? null,
            'client_rating' => $history['overall'] ?? null,
            'client_reviews' => $history['reviews'] ?? null,
            'client_member_since' => $registered ? Carbon::createFromTimestamp((int) $registered) : null,
            'client_verification' => is_array($owner['status'] ?? null) ? $owner['status'] : null,
            'client_engagement' => $engagement !== [] ? $engagement : null,
        ], fn ($v) => $v !== null);

        if ($attributes === []) {
            return false;
        }

        $attributes['last_scraped_at'] = now();

        BidInsight::updateOrCreate(['project_id' => $project['id']], $attributes);

        return true;
    }
}
