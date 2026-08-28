<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_name' => $this->service_name,
            'category' => $this->category,
            'is_active' => $this->is_active,
            'current_price' => $this->when(
                $this->relationLoaded('prices'),
                fn() => $this->getActivePrice()?->base_price
            ),
            'prices' => LabServicePriceResource::collection(
                $this->whenLoaded('prices')
            ),
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
