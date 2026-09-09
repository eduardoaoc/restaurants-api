<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
class PlatformRole extends Model
{
    /**
     * The platform permissions granted to this role.
     *
     * @return BelongsToMany<PlatformPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PlatformPermission::class, 'platform_role_permissions')
            ->withTimestamps();
    }

    /**
     * The users holding this platform role.
     *
     * @return HasMany<PlatformRoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PlatformRoleAssignment::class);
    }
}
