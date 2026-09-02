<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentRecord
 */
class PaymentRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'note' => $this->note,
            'recorded_at' => $this->recorded_at,
            'recorded_by' => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null,
        ];
    }
}
