<?php

namespace App\Http\Resources\Api\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array produced by AuthContextBuilder — never the raw User
 * model — so the response contract stays exactly what AuthContextBuilder
 * computed: user identity, platform access, and the organizations/
 * restaurants/permissions the user can actually reach.
 *
 * @mixin User
 */
class AuthContextResource extends JsonResource
{
    /**
     * @param  array{
     *     user: array{id: int, name: string, email: string, status: string},
     *     platform: array{is_platform_admin: bool, roles: array<int, string>, permissions: array<int, string>},
     *     organizations: array<int, array<string, mixed>>,
     * }  $context
     */
    public function __construct(User $user, private readonly array $context)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->context;
    }
}
