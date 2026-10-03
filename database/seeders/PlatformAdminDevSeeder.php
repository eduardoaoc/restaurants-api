<?php

namespace Database\Seeders;

use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminDevSeeder extends Seeder
{
    /**
     * DEV/TEST ONLY. Creates one known super_admin so a developer can log
     * into the Platform API locally without a manual DB write. Guarded by
     * an environment check in run() — this must never create/update a
     * user in production from a deploy running the full seeder chain.
     *
     * The credentials are intentionally unremarkable (mirrors the existing
     * test@example.com/password dev user in DatabaseSeeder) and must never
     * be reused as a production credential — see the Bloco 0 report.
     */
    public const EMAIL = 'superadmin@aforo.test';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'AFORO Super Admin',
                'password' => Hash::make(self::PASSWORD),
                'status' => User::STATUS_ACTIVE,
            ],
        );

        $role = PlatformRole::query()->where('slug', 'super_admin')->firstOrFail();

        PlatformRoleAssignment::query()->firstOrCreate([
            'user_id' => $user->id,
            'platform_role_id' => $role->id,
        ]);
    }
}
