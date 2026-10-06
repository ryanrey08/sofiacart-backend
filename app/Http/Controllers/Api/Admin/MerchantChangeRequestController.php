<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MerchantChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMerchantChangeRequestsRequest;
use App\Http\Requests\Admin\ReviewMerchantChangeRequest;
use App\Http\Resources\Admin\AdminMerchantResource;
use App\Http\Resources\MerchantChangeRequestResource;
use App\Models\MerchantChangeRequest;
use App\Services\MerchantProfileChangeService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin review of merchant profile change requests.
 */
class MerchantChangeRequestController extends Controller
{
    public function __construct(
        protected MerchantProfileChangeService $changes,
    ) {}

    public function index(ListMerchantChangeRequestsRequest $request)
    {
        Gate::authorize('viewAny', MerchantChangeRequest::class);

        $query = MerchantChangeRequest::query()->with(['merchant', 'submitter', 'reviewer']);

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($merchantId = $request->validated('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->whereHas('merchant', function ($merchant) use ($search): void {
                $merchant->where('store_name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('store_slug', 'like', "%{$search}%");
            });
        }

        $request->validated('sort') === 'oldest'
            ? $query->oldest('id')
            : $query->latest('id');

        return MerchantChangeRequestResource::collection(
            $query->paginate($this->pageSize($request))->withQueryString()
        );
    }

    public function show(MerchantChangeRequest $changeRequest): MerchantChangeRequestResource
    {
        Gate::authorize('view', $changeRequest);

        return MerchantChangeRequestResource::make($changeRequest->load(['merchant', 'submitter', 'reviewer']));
    }

    /**
     * Preview of a replacement document uploaded with a pending request.
     */
    public function file(MerchantChangeRequest $changeRequest, string $document): StreamedResponse
    {
        Gate::authorize('view', $changeRequest);

        return $this->changes->pendingFileResponse($changeRequest, $document);
    }

    /**
     * Approves (applies the requested values to the live merchant record) or rejects the request.
     * Both decisions are logged by the service, so this route is excluded from the generic audit.
     */
    public function updateStatus(ReviewMerchantChangeRequest $request, MerchantChangeRequest $changeRequest)
    {
        $reviewed = $request->validated('status') === MerchantChangeRequestStatus::Approved->value
            ? $this->changes->approve($changeRequest, $request->user(), $request)
            : $this->changes->reject($changeRequest, $request->user(), (string) $request->validated('reason'), $request);

        $merchant = $reviewed->merchant()->firstOrFail()->load(['user', 'pendingChangeRequest']);

        return MerchantChangeRequestResource::make($reviewed->fresh()->load(['merchant', 'submitter', 'reviewer']))
            ->additional(['meta' => ['merchant' => AdminMerchantResource::make($merchant)]]);
    }
}
