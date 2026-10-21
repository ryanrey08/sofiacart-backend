<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResendMerchantVerificationRequest;
use App\Http\Requests\VerifyMerchantEmailRequest;
use App\Services\MerchantEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

/**
 * Merchant email verification endpoints. Verifying an email never changes the merchant's
 * approval status; that remains an admin decision (MerchantManagementController).
 */
class MerchantEmailVerificationController extends Controller
{
    public function __construct(
        protected MerchantEmailVerificationService $verification,
    ) {}

    public function verify(VerifyMerchantEmailRequest $request): JsonResponse
    {
        $outcome = $this->verification->verify($request->validated('token'));

        return response()->json([
            'message' => $outcome->message(),
            'status' => $outcome->value,
        ], $outcome->httpStatus());
    }

    /**
     * The same response is returned whether or not the address belongs to an unverified
     * merchant, and the email is sent after the response so timing doesn't reveal it either.
     */
    public function resend(ResendMerchantVerificationRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        defer(fn () => $this->verification->resendToAddress($email));

        return response()->json([
            'message' => 'If an unverified merchant account uses this email address, a new verification link has been sent to it.',
        ]);
    }

    /**
     * Resend for the signed-in merchant, always to the account's current email address.
     */
    public function resendForCurrentUser(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== UserRole::Merchant) {
            return response()->json([
                'message' => 'Email verification links are only sent to merchant accounts.',
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is already verified.',
                'status' => 'already_verified',
            ]);
        }

        if (! $this->verification->sendVerificationLink($user)) {
            return response()->json([
                'message' => "We couldn't send the verification email right now. Please try again in a few minutes.",
                'status' => 'delivery_failed',
            ], 503);
        }

        return response()->json([
            'message' => 'A new verification link has been sent to your email address.',
            'status' => 'sent',
        ]);
    }
}
