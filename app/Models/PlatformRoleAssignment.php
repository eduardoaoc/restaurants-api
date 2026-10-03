<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'platform_role_id'])]
class PlatformRoleAssignment extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PlatformRole, $this>
     */
    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(PlatformRole::class);
    }
}
