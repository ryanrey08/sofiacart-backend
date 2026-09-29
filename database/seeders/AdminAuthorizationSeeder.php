<?php

namespace Database\Seeders;

use App\Admin\AdminPermissionRegistry;
use App\Admin\AdminRoleRegistry;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use Illuminate\Database\Seeder;

class AdminAuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(AdminPermissionRegistry::definitions())
            ->mapWithKeys(function (array $definition): array {
                $permission = AdminPermission::query()->updateOrCreate(
                    ['name' => $definition['name']],
                    [
                        'group' => $definition['group'],
                        'label' => $definition['label'],
                        'description' => $definition['label'],
                        'is_system' => true,
                    ],
                );

                return [$definition['name'] => $permission->id];
            });

        foreach (AdminRoleRegistry::definitions() as $definition) {
            $role = AdminRole::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ],
            );

            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(fn (string $name) => $permissions[$name])
                    ->all(),
            );
        }
    }
}
