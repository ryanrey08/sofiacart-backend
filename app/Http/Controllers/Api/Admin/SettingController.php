<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminSettingsRequest;
use App\Http\Resources\Admin\AdminSettingResource;
use App\Models\AdminSetting;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function index()
    {
        return AdminSettingResource::collection(AdminSetting::query()->orderBy('key')->get());
    }

    public function update(UpdateAdminSettingsRequest $request)
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->validated('settings') as $setting) {
                $existing = AdminSetting::query()->firstWhere('key', $setting['key']);

                AdminSetting::query()->updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $setting['value'] ?? null,
                        'description' => $setting['description'] ?? null,
                        'is_secret' => $existing?->is_secret ?? false,
                    ],
                );
            }
        });

        return AdminSettingResource::collection(AdminSetting::query()->orderBy('key')->get());
    }
}
