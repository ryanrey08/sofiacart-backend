<?php

namespace App\Http\Controllers\Api;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMerchantRequest;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MerchantController extends Controller
{
    public function register(RegisterMerchantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $basePath = 'merchants/'.$validated['store_slug'];

        $merchant = DB::transaction(function () use ($request, $validated, $basePath): Merchant {
            $user = User::create([
                'role' => UserRole::Merchant,
                'phone' => $validated['phone'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            return Merchant::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'],
                'business_type' => $validated['business_type'],
                'business_permit_number' => $validated['business_permit_number'],
                'tin' => $validated['tin'],
                'business_category' => $validated['business_category'],
                'business_address' => $validated['business_address'],
                'city' => $validated['city'],
                'province' => $validated['province'],
                'zip_code' => $validated['zip_code'],
                'business_permit_path' => $request->file('business_permit')->store($basePath, 'public'),
                'store_name' => $validated['store_name'],
                'store_slug' => $validated['store_slug'],
                'store_category' => $validated['store_category'],
                'store_description' => $validated['store_description'] ?? null,
                'store_address' => $validated['store_address'],
                'contact_phone' => $validated['contact_phone'],
                'contact_email' => $validated['contact_email'],
                'store_logo_path' => $request->file('store_logo')->store($basePath, 'public'),
                'store_banner_path' => $request->file('store_banner')?->store($basePath, 'public'),
                'social_links' => $validated['social_links'] ?? null,
                'owner_name' => $validated['owner_name'],
                'owner_position' => $validated['owner_position'],
                'owner_email' => $validated['owner_email'],
                'owner_phone' => $validated['owner_phone'],
                'owner_birth_date' => $validated['owner_birth_date'],
                'government_id_type' => $validated['government_id_type'],
                'government_id_number' => $validated['government_id_number'],
                'government_id_expiry_date' => $validated['government_id_expiry_date'],
                'government_id_path' => $request->file('government_id')->store($basePath, 'public'),
                'status' => MerchantStatus::Pending,
            ]);
        });

        return response()->json([
            'message' => 'Merchant registered successfully.',
            'user' => UserResource::make($merchant->user()->with('merchant')->first()),
            'merchant' => MerchantResource::make($merchant),
        ], 201);
    }
}
