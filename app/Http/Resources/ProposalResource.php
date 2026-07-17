<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProposalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'country' => $this->country,
            'min_budget' => $this->min_budget,
            'max_budget' => $this->max_budget,
            'currency_symbol' => $this->currency_symbol,
            'skills' => $this->skills ?? [],
            'seo_url' => $this->seo_url,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
