<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ThreadResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'freelancer_thread_id' => $this->freelancer_thread_id,
            'project_id' => $this->project_id,
            'status' => $this->status,
            'blocked' => (bool) $this->blocked,
            'block_reason' => $this->block_reason,
            'assigned_user_id' => $this->assigned_user_id,
            'last_client_message_at' => $this->last_client_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'proposal' => $this->whenLoaded('proposal', function () {
                return [
                    'id' => $this->proposal->id,
                    'title' => $this->proposal->title,
                    'description' => $this->proposal->description,
                    'seo_url' => $this->proposal->seo_url,
                    'type' => $this->proposal->type,
                    'min_budget' => $this->proposal->min_budget,
                    'max_budget' => $this->proposal->max_budget,
                    'currency_symbol' => $this->proposal->currency_symbol,
                    'country' => $this->proposal->country,
                    'skills' => ! empty($this->proposal->skills)
                        ? $this->proposal->skills
                        : self::staticSkills(),
                    'bid' => $this->proposal->relationLoaded('bid') && $this->proposal->bid ? [
                        'id' => $this->proposal->bid->id,
                        'price' => $this->proposal->bid->price,
                        'cover_letter' => $this->proposal->bid->cover_letter,
                        'awarded' => (bool) $this->proposal->bid->awarded,
                    ] : null,
                ];
            }),
            'client' => $this->resource->client_insight ? [
                'name' => $this->resource->client_insight->client_name,
                'avatar' => $this->resource->client_insight->client_avatar,
                'country' => $this->resource->client_insight->client_country,
                'country_flag' => $this->resource->client_insight->client_country_flag,
                'rating' => $this->resource->client_insight->client_rating,
                'reviews' => $this->resource->client_insight->client_reviews,
                'member_since' => $this->resource->client_insight->client_member_since?->toIso8601String(),
                'verification' => $this->resource->client_insight->client_verification,
                'engagement' => $this->resource->client_insight->client_engagement,
            ] : self::staticClient($this->project_id),
        ];
    }

    /**
     * Static placeholder client shown while real client insights are unavailable.
     */
    private static function staticClient($seed): array
    {
        return [
            'name' => 'Sarah Mitchell',
            'avatar' => 'https://i.pravatar.cc/150?u=client'.$seed,
            'country' => 'United States',
            'country_flag' => 'https://flagcdn.com/w80/us.png',
            'rating' => 4.8,
            'reviews' => 27,
            'member_since' => '2019-03-14T00:00:00+00:00',
            'verification' => [
                'email' => true,
                'payment' => true,
                'phone' => true,
                'identity' => true,
            ],
            'engagement' => 'Frequently hires',
        ];
    }

    /**
     * Static placeholder skills shown while real proposal skills are unavailable.
     */
    private static function staticSkills(): array
    {
        return [
            ['id' => 32, 'name' => 'Video Editing'],
            ['id' => 30, 'name' => 'After Effects'],
            ['id' => 45, 'name' => 'Adobe Premiere Pro'],
        ];
    }
}
