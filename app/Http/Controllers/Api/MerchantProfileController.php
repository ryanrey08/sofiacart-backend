<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitMerchantProfileChangeRequest;
use App\Http\Resources\MerchantChangeRequestResource;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use App\Services\MerchantProfileChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The logged-in merchant's own store profile. `show` always returns the approved (live) record;
 * edits are submitted as change requests and only reach the live record after admin approval.
 */
class MerchantProfileController extends Controller
{
    public function __construct(
        protected MerchantProfileChangeService $changes,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $merchant = $this->ownMerchant($request);
        $latest = $merchant->changeRequests()->with(['submitter', 'reviewer'])->latest('id')->first();
        $pending = $latest?->isPending() ? $latest : null;

        return MerchantResource::make($merchant)
            ->additional(['meta' => [
                'editable_fields' => MerchantProfileChangeService::EDITABLE_FIELDS,
                'pending_change_request' => $pending
                    ? MerchantChangeRequestResource::make($pending->setRelation('merchant', $merchant))
                    : null,
                'latest_change_request' => $latest
                    ? MerchantChangeRequestResource::make($latest->setRelation('merchant', $merchant))
                    : null,
            ]])
            ->response();
    }

    public function changeRequests(Request $request)
    {
        $merchant = $this->ownMerchant($request);

        return MerchantChangeRequestResource::collection(
            $merchant->changeRequests()
                ->with(['submitter', 'reviewer'])
                ->latest('id')
                ->paginate($this->pageSize($request))
        );
    }

    public function showChangeRequest(Request $request, int $changeRequest): MerchantChangeRequestResource
    {
        $merchant = $this->ownMerchant($request);

        $model = $this->ownChangeRequest($merchant, $changeRequest);

        Gate::authorize('view', $model);

        return MerchantChangeRequestResource::make($model->load(['submitter', 'reviewer'])->setRelation('merchant', $merchant));
    }

    public function submit(SubmitMerchantProfileChangeRequest $request): JsonResponse
    {
        $merchant = $this->ownMerchant($request);

        $changeRequest = $this->changes->submit(
            $merchant,
            $request->user(),
            $request->profileFields(),
            $request->documentUploads(),
            $request,
        );

        return MerchantChangeRequestResource::make(
            $changeRequest->load(['submitter', 'reviewer'])->setRelation('merchant', $merchant->fresh())
        )->response()->setStatusCode(201);
    }

    /**
     * Cancels the merchant's own pending request; uploaded files are discarded.
     */
    public function withdraw(Request $request, int $changeRequest): MerchantChangeRequestResource
    {
        $merchant = $this->ownMerchant($request);
        $model = $this->ownChangeRequest($merchant, $changeRequest);

        Gate::authorize('withdraw', $model);

        $withdrawn = $this->changes->withdraw($model, $request->user(), $request);

        return MerchantChangeRequestResource::make(
            $withdrawn->load(['submitter', 'reviewer'])->setRelation('merchant', $merchant->fresh())
        );
    }

    /**
     * A document uploaded with the merchant's own pending request.
     */
    public function changeRequestFile(Request $request, int $changeRequest, string $document): StreamedResponse
    {
        $model = $this->ownChangeRequest($this->ownMerchant($request), $changeRequest);

        Gate::authorize('view', $model);

        return $this->changes->pendingFileResponse($model, $document);
    }

    /**
     * One of the merchant's own approved (live) documents: store_logo, store_banner, business_permit, government_id.
     */
    public function document(Request $request, string $document): StreamedResponse
    {
        return $this->changes->liveFileResponse($this->ownMerchant($request), $document);
    }

    /**
     * Scoped to the merchant, so another merchant's request is a 404 rather than a 403.
     */
    protected function ownChangeRequest(Merchant $merchant, int $changeRequest): MerchantChangeRequest
    {
        return MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->id)
            ->findOrFail($changeRequest);
    }

    /**
     * Only a merchant account linked to a store has a profile; admins use the admin API.
     */
    protected function ownMerchant(Request $request): Merchant
    {
        Gate::authorize('create', MerchantChangeRequest::class);

        return $request->user()->merchant;
    }
}
