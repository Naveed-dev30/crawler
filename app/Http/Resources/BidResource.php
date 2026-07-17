<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BidResource extends JsonResource
{
    protected bool $full = false;

    public function withFull(): self
    {
        $this->full = true;

        return $this;
    }

    public function toArray($request): array
    {
        $proposal = $this->proposal;

        $data = [
            'id' => $this->id,
            'status' => $this->bid_status,
            'price' => (float) $this->price,
            'currency' => $proposal?->currency_symbol,
            'awarded' => (bool) $this->awarded,
            'awarded_price' => $this->awarded_price,
            'check' => $this->check,
            'is_seen' => (bool) $this->is_seen,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'proposal' => $proposal ? [
                'id' => $proposal->id,
                'title' => $proposal->title,
                'project_id' => $proposal->project_id,
                'type' => $proposal->type,
                'country' => $proposal->country,
                'min_budget' => $proposal->min_budget,
                'max_budget' => $proposal->max_budget,
                'seo_url' => $proposal->seo_url,
                'skills' => $proposal->skills ?? [],
            ] : null,
        ];

        if ($this->full) {
            $data['cover_letter'] = $this->cover_letter;
            if ($proposal) {
                $data['proposal']['description'] = $proposal->description;
            }
        }

        return $data;
    }
}
