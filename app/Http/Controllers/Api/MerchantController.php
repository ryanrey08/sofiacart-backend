<?php

namespace App\Http\Controllers\Api;

use App\Enums\LegalDocumentAudience;
use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMerchantRequest;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use App\Services\LegalDocumentService;
use App\Services\MerchantEmailVerificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MerchantController extends Controller
{
    /**
     * Creates the merchant user (email unverified) and the merchant application (pending admin
     * review), then emails a verification link to the address entered on the form. Email
     * verification and admin approval are separate: verifying never approves the store.
     * The accepted Terms / acknowledged Privacy Policy versions are recorded in the same transaction.
     */
    public function register(RegisterMerchantRequest $request, MerchantEmailVerificationService $verification): JsonResponse
    {
        $validated = $request->validated();
        $basePath = 'merchants/'.$validated['store_slug'];

        try {
            $merchant = $this->createMerchant($request, $validated, $basePath);
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration claimed the same email, TIN or store URL after validation.
            return response()->json([
                'message' => 'A registration with one or more of these details already exists.',
            ], 409);
        }

        $user = $merchant->user()->with('merchant')->first();
        $verificationEmailSent = $verification->sendVerificationLink($user);

        return response()->json([
            'message' => $verificationEmailSent
                ? 'Merchant registered successfully. Check your email to verify your address.'
                : "Merchant registered successfully, but we couldn't send the verification email. Request a new link to verify your address.",
            'verification_email_sent' => $verificationEmailSent,
            'verification_expires_in_minutes' => $verification->expiresInMinutes(),
            'user' => UserResource::make($user),
            'merchant' => MerchantResource::make($merchant),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function createMerchant(RegisterMerchantRequest $request, array $validated, string $basePath): Merchant
    {
        return DB::transaction(function () use ($request, $validated, $basePath): Merchant {
            $user = User::create([
                'role' => UserRole::Merchant,
                'phone' => $validated['phone'],
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            if ($request->requiresLegalAcceptance()) {
                app(LegalDocumentService::class)->recordRegistrationConsents(
                    $user,
                    LegalDocumentAudience::Merchant,
                    (int) $validated['terms_document_id'],
                    (int) $validated['privacy_document_id'],
                    $request,
                );
            }

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
    }
}
