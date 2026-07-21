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
                    'skills' => $this->proposal->skills,
                    'bid' => $this->proposal->relationLoaded('bid') && $this->proposal->bid ? [
                        'id' => $this->proposal->bid->id,
                        'price' => $this->proposal->bid->price,
                        'cover_letter' => $this->proposal->bid->cover_letter,
                        'awarded' => (bool) $this->proposal->bid->awarded,
                    ] : null,
                ];
            }),
        ];
    }
}
