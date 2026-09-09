<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug'])]
class PlatformPermission extends Model
{
    /**
     * The platform roles that grant this permission.
     *
     * @return BelongsToMany<PlatformRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_role_permissions')
            ->withTimestamps();
    }
}
