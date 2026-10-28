<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Services\RegistrationOptionsService;
use Illuminate\Http\JsonResponse;

/**
 * Public lists for the merchant registration and Store Profile forms (active entries only).
 */
class RegistrationOptionsController extends Controller
{
    public function __construct(
        protected RegistrationOptionsService $options,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->options->catalog()]);
    }

    public function cities(Province $province): JsonResponse
    {
        return response()->json(['data' => $this->options->cities($province)]);
    }
}
