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
                $isSecret = $existing?->is_secret ?? ($setting['is_secret'] ?? false);
                $hasIncomingValue = array_key_exists('value', $setting);

                AdminSetting::query()->updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $hasIncomingValue
                            ? $setting['value']
                            : $existing?->value,
                        'description' => array_key_exists('description', $setting)
                            ? $setting['description']
                            : $existing?->description,
                        'is_secret' => $isSecret,
                    ],
                );
            }
        });

        return AdminSettingResource::collection(AdminSetting::query()->orderBy('key')->get());
    }
}
