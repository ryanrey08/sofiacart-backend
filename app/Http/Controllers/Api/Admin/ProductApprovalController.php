<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewProductRequest;
use App\Http\Resources\ProductChangeRequestResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Services\ProductApprovalService;

/**
 * Super Admin product review. Both decisions are logged by the service, so these routes are
 * excluded from the generic admin audit.
 */
class ProductApprovalController extends Controller
{
    public function __construct(
        protected ProductApprovalService $approvals,
    ) {}

    /**
     * Approves (publishes) or rejects a product awaiting approval.
     */
    public function review(ReviewProductRequest $request, Product $product): ProductResource
    {
        $expected = $request->validated('submitted_at');

        $reviewed = $request->validated('status') === 'approved'
            ? $this->approvals->approve($product, $request->user(), $expected, $request)
            : $this->approvals->reject($product, $request->user(), (string) $request->validated('reason'), $expected, $request);

        return ProductResource::make($reviewed->fresh()->load($this->relations()));
    }

    /**
     * Applies (approve) or discards (reject) a pending edit to an approved product.
     */
    public function reviewChanges(ReviewProductRequest $request, ProductChangeRequest $changeRequest): ProductChangeRequestResource
    {
        $reviewed = $request->validated('status') === 'approved'
            ? $this->approvals->approveChanges($changeRequest, $request->user(), $request)
            : $this->approvals->rejectChanges($changeRequest, $request->user(), (string) $request->validated('reason'), $request);

        $product = $reviewed->product()->firstOrFail()->load($this->relations());

        return ProductChangeRequestResource::make($reviewed->fresh()->load(['submitter:id,name', 'reviewer:id,name']))
            ->additional(['meta' => ['product' => ProductResource::make($product)]]);
    }

    /**
     * @return list<string>
     */
    protected function relations(): array
    {
        return [
            'category',
            'variants',
            'imageRecords',
            'merchant:id,store_name,store_slug,status',
            'reviewer:id,name',
            'pendingChangeRequest',
            'latestChangeRequest.reviewer:id,name',
        ];
    }
}
