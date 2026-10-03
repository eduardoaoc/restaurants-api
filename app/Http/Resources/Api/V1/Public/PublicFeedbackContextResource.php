<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public projection of a feedback_token's context — deliberately minimal:
 * only what the feedback form needs to render (restaurant display name,
 * table label, whether a feedback was already submitted). Never exposes
 * internal ids, the table's public_token, financial data, or staff
 * identities (Passo 3.5 §10/§15).
 *
 * @mixin TableSession
 */
class PublicFeedbackContextResource extends JsonResource
{
    public function __construct(TableSession $session, private readonly bool $alreadySubmitted)
    {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'already_submitted' => $this->alreadySubmitted,
            'restaurant' => [
                'name' => $this->restaurant->name,
            ],
            'table' => [
                'name' => $this->table->name,
            ],
        ];
    }
}
