<?php

namespace App\Services;

use App\Admin\AdminPermissionRegistry;
use App\Enums\ProductChangeRequestStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductChangeRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\ProductReviewed;
use App\Notifications\ProductSubmittedForReview;
use App\Services\Concerns\SyncsProductMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Product approval workflow. A product is public on the storefront only while it is `active` and
 * approved (`approved_at` set); the only ways to reach that are approve() and approveChanges().
 *
 * - Not yet approved (draft / pending_approval / rejected / archived): the merchant edits the row
 *   directly (it is not public) and submits it for review; it is locked while pending.
 * - Approved (active / archived): stock, cost and active↔archived apply immediately. Customer-facing
 *   edits are stored as a pending ProductChangeRequest; the approved values stay live until a
 *   Super Admin approves the request.
 */
class ProductApprovalService
{
    use SyncsProductMedia;

    /**
     * Customer-facing fields: changing them on an approved product requires review.
     */
    public const CONTENT_FIELDS = [
        'name',
        'slug',
        'description',
        'short_description',
        'full_description',
        'category_id',
        'price',
        'regular_price',
        'sale_price',
        'brand',
        'condition',
        'weight',
        'tags',
        'length',
        'width',
        'height',
    ];

    /**
     * Operational fields a merchant may change on an approved product without review.
     */
    public const DIRECT_FIELDS = [
        'merchant_id',
        'status',
        'sku',
        'cost_price',
        'track_inventory',
        'stock_quantity',
        'low_stock_threshold',
    ];

    /**
     * Audit actions that make up a product's review history.
     */
    public const HISTORY_ACTIONS = [
        'product.submitted_for_approval',
        'product.approval_withdrawn',
        'product.change_requested',
        'product.change_withdrawn',
        'admin.products.approved',
        'admin.products.rejected',
        'admin.products.change_approved',
        'admin.products.change_rejected',
    ];

    protected const DECIMALS = [
        'price' => 2,
        'regular_price' => 2,
        'sale_price' => 2,
        'weight' => 3,
        'length' => 2,
        'width' => 2,
        'height' => 2,
    ];

    public function __construct(
        protected AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Statuses a merchant (or the generic admin product API) may set. `active` is reachable only
     * through approval, and `rejected` only through an admin decision.
     *
     * @throws ValidationException
     */
    public function assertStatusChangeAllowed(?Product $product, ?ProductStatus $target): void
    {
        if ($target === null || $target === $product?->status) {
            return;
        }

        if ($product?->isApproved()) {
            if (! in_array($target, [ProductStatus::Active, ProductStatus::Archived], true)) {
                throw ValidationException::withMessages([
                    'status' => ['An approved product can only be set to Active or Archived. Edits are sent for review automatically.'],
                ]);
            }

            return;
        }

        $allowed = $product
            ? [ProductStatus::Draft, ProductStatus::PendingApproval, ProductStatus::Archived]
            : [ProductStatus::Draft, ProductStatus::PendingApproval];

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [$target === ProductStatus::Active
                    ? 'A product must be approved by a SofiaCart admin before it can be published. Submit it for approval instead.'
                    : 'This status can only be set by a SofiaCart admin.'],
            ]);
        }
    }

    /**
     * Records a submission (new product created as pending, or a draft/rejected/archived product
     * moved to pending). Call inside the transaction that changed the status.
     */
    public function markSubmitted(Product $product, User $actor, ?Request $request = null): void
    {
        $product->forceFill(['submitted_at' => now()])->save();

        $this->auditLogger->log(
            'product.submitted_for_approval',
            $actor,
            $product,
            $request,
            "Product #{$product->id} submitted for approval.",
            ['product_id' => $product->id, 'merchant_id' => $product->merchant_id],
        );

        DB::afterCommit(fn () => $this->notify(
            $this->reviewers(),
            new ProductSubmittedForReview($product->fresh(['merchant']) ?? $product),
        ));
    }

    /**
     * Records that a pending product went back to draft/archived before it was reviewed.
     */
    public function markWithdrawn(Product $product, User $actor, ?Request $request = null): void
    {
        $product->forceFill(['submitted_at' => null])->save();

        $this->auditLogger->log(
            'product.approval_withdrawn',
            $actor,
            $product,
            $request,
            "Product #{$product->id} withdrawn from review.",
            ['product_id' => $product->id],
        );
    }

    /**
     * Moves a draft, rejected or archived (never approved) product into the review queue.
     */
    public function submit(Product $product, User $actor, ?Request $request = null): Product
    {
        return DB::transaction(function () use ($product, $actor, $request): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => ['This product is already approved. Edits to it are sent for review automatically.'],
                ]);
            }

            if ($locked->status === ProductStatus::PendingApproval) {
                throw ValidationException::withMessages([
                    'status' => ['This product is already awaiting approval.'],
                ]);
            }

            $locked->update(['status' => ProductStatus::PendingApproval]);
            $this->markSubmitted($locked, $actor, $request);

            return $locked;
        });
    }

    /**
     * Takes a pending product out of the review queue so the merchant can edit it again.
     */
    public function withdraw(Product $product, User $actor, ?Request $request = null): Product
    {
        return DB::transaction(function () use ($product, $actor, $request): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->status !== ProductStatus::PendingApproval) {
                throw ValidationException::withMessages([
                    'status' => ['Only a product awaiting approval can be withdrawn.'],
                ]);
            }

            $locked->update(['status' => ProductStatus::Draft]);
            $this->markWithdrawn($locked, $actor, $request);

            return $locked;
        });
    }

    /**
     * Approves a pending product: it becomes active and therefore eligible for the storefront.
     *
     * @param  string|null  $expectedSubmittedAt  the submission the reviewer looked at; a newer
     *                                            resubmission must be reviewed again (409)
     */
    public function approve(Product $product, User $reviewer, ?string $expectedSubmittedAt = null, ?Request $request = null): Product
    {
        $approved = DB::transaction(function () use ($product, $reviewer, $expectedSubmittedAt, $request): Product {
            $locked = $this->lockPendingProduct($product, $expectedSubmittedAt);

            $locked->update(['status' => ProductStatus::Active]);
            $locked->forceFill([
                'approved_at' => $locked->approved_at ?? now(),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->auditLogger->log(
                'admin.products.approved',
                $reviewer,
                $locked,
                $request,
                "Product #{$locked->id} approved.",
                ['product_id' => $locked->id, 'merchant_id' => $locked->merchant_id],
            );

            return $locked;
        });

        $this->notifyMerchant($approved, new ProductReviewed($approved, true));

        return $approved;
    }

    public function reject(Product $product, User $reviewer, string $reason, ?string $expectedSubmittedAt = null, ?Request $request = null): Product
    {
        $rejected = DB::transaction(function () use ($product, $reviewer, $reason, $expectedSubmittedAt, $request): Product {
            $locked = $this->lockPendingProduct($product, $expectedSubmittedAt);

            $locked->update(['status' => ProductStatus::Rejected]);
            $locked->forceFill([
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->auditLogger->log(
                'admin.products.rejected',
                $reviewer,
                $locked,
                $request,
                "Product #{$locked->id} rejected.",
                ['product_id' => $locked->id, 'merchant_id' => $locked->merchant_id, 'reason' => $reason],
            );

            return $locked;
        });

        $this->notifyMerchant($rejected, new ProductReviewed($rejected, false));

        return $rejected;
    }

    /**
     * Customer-facing differences between the submitted edit and the product's current values.
     *
     * @param  array<string, mixed>  $data  validated, normalised product data
     * @param  array{paths: list<string>, main_index: int|null}|null  $uploads  newly stored gallery images
     * @param  array{ids: list<int>, main_id: int|null}|null  $gallery  requested order of existing images
     * @param  list<array<string, mixed>>|null  $variants
     * @return array{changes: array<string, mixed>, original: array<string, mixed>, images: array<string, mixed>|null}
     */
    public function contentChanges(Product $product, array $data, ?array $uploads, ?array $gallery, ?array $variants): array
    {
        $changes = [];
        $original = [];

        foreach (array_intersect_key($data, array_flip(self::CONTENT_FIELDS)) as $field => $value) {
            $requested = $this->normalize($field, $value);
            $current = $this->normalize($field, $product->getAttribute($field));

            if ($requested !== $current) {
                $changes[$field] = $requested;
                $original[$field] = $current;
            }
        }

        if ($uploads === null && $gallery !== null) {
            $current = $this->currentGallery($product);
            $requested = ['ids' => array_values(array_map('intval', $gallery['ids'])), 'main_id' => $gallery['main_id']];
            $requested['main_id'] ??= $requested['ids'][0] ?? null;

            if ($requested !== $current) {
                $changes['gallery'] = $requested;
                $original['gallery'] = $current;
            }
        }

        if ($variants !== null) {
            $current = $this->variantContent($product->variants()->get()->map->toArray()->all());
            $requested = $this->variantContent($variants);

            if ($requested !== $current) {
                $changes['variants'] = $this->normalizeVariants($variants);
                $original['variants'] = $this->normalizeVariants($product->variants()->get()->map->toArray()->all());
            }
        }

        return [
            'changes' => $changes,
            'original' => $original,
            'images' => $uploads,
        ];
    }

    /**
     * Stores a pending edit for an approved product. The caller holds the product row lock.
     *
     * @param  array{changes: array<string, mixed>, original: array<string, mixed>, images: array<string, mixed>|null}  $diff
     */
    public function stageChanges(Product $product, User $actor, array $diff, ?Request $request = null): ?ProductChangeRequest
    {
        if ($diff['changes'] === [] && $diff['images'] === null) {
            return null;
        }

        if ($product->changeRequests()->where('status', ProductChangeRequestStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages([
                'changes' => ['This product already has changes awaiting approval. Withdraw them or wait for the review before submitting new changes.'],
            ]);
        }

        if (isset($diff['changes']['variants'])) {
            $this->assertVariantSkusAvailable($product, $diff['changes']['variants']);
            $this->assertOrderedVariantsKept($product, $diff['changes']['variants']);
        }

        $changeRequest = $product->changeRequests()->create([
            'merchant_id' => $product->merchant_id,
            'status' => ProductChangeRequestStatus::Pending,
            'changes' => $diff['changes'],
            'original' => $diff['original'],
            'images' => $diff['images'],
            'submitted_by' => $actor->id,
        ]);

        $this->auditLogger->log(
            'product.change_requested',
            $actor,
            $product,
            $request,
            "Changes to product #{$product->id} submitted for approval.",
            ['product_id' => $product->id, 'change_request_id' => $changeRequest->id, 'fields' => $changeRequest->changedFields()],
        );

        DB::afterCommit(fn () => $this->notify(
            $this->reviewers(),
            new ProductSubmittedForReview($product->fresh(['merchant']) ?? $product, $changeRequest),
        ));

        return $changeRequest;
    }

    /**
     * Applies an approved edit to the live product, atomically. A request that is no longer
     * pending cannot be applied (again).
     */
    public function approveChanges(ProductChangeRequest $changeRequest, User $reviewer, ?Request $request = null): ProductChangeRequest
    {
        $replacedPaths = [];

        $approved = DB::transaction(function () use ($changeRequest, $reviewer, $request, &$replacedPaths): ProductChangeRequest {
            $locked = $this->lockPendingChangeRequest($changeRequest);
            $product = Product::query()->with(['imageRecords'])->lockForUpdate()->findOrFail($locked->product_id);
            $changes = $locked->getAttribute('changes') ?? [];

            $updates = array_intersect_key($changes, array_flip(self::CONTENT_FIELDS));
            $this->assertStillApplicable($product, $updates);
            $product->update($updates);

            if ($images = $locked->images) {
                $replacedPaths = $product->imageRecords->pluck('path')->merge($product->images ?? [])
                    ->diff($images['paths'])->values()->all();
                $product->imageRecords()->delete();
                $this->syncImages($product, $images['paths'], $images['main_index'] ?? null);
            } elseif (isset($changes['gallery'])) {
                $this->syncExistingImages($product, $changes['gallery']['ids'], $changes['gallery']['main_id']);
            }

            if (isset($changes['variants'])) {
                $this->assertVariantSkusAvailable($product, $changes['variants']);
                // Stock moved on (orders, adjustments) since submission: keep the live stock of existing variants.
                $liveStock = $product->variants()->pluck('stock', 'sku');
                $this->syncVariants($product, array_map(fn (array $variant) => [
                    ...$variant,
                    'stock' => $liveStock[$variant['sku']] ?? $variant['stock'],
                ], $changes['variants']));
            }

            $locked->update([
                'status' => ProductChangeRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
            $product->forceFill(['reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();

            $this->auditLogger->log(
                'admin.products.change_approved',
                $reviewer,
                $product,
                $request,
                "Changes to product #{$product->id} approved.",
                ['product_id' => $product->id, 'change_request_id' => $locked->id, 'fields' => $locked->changedFields()],
            );

            return $locked;
        });

        $this->discardStoredImages($replacedPaths);
        $this->notifyMerchant($approved->product, new ProductReviewed($approved->product, true, $approved));

        return $approved;
    }

    public function rejectChanges(ProductChangeRequest $changeRequest, User $reviewer, string $reason, ?Request $request = null): ProductChangeRequest
    {
        $rejected = DB::transaction(function () use ($changeRequest, $reviewer, $reason, $request): ProductChangeRequest {
            $locked = $this->lockPendingChangeRequest($changeRequest);

            $locked->update([
                'status' => ProductChangeRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->auditLogger->log(
                'admin.products.change_rejected',
                $reviewer,
                $locked->product,
                $request,
                "Changes to product #{$locked->product_id} rejected.",
                ['product_id' => $locked->product_id, 'change_request_id' => $locked->id, 'fields' => $locked->changedFields(), 'reason' => $reason],
            );

            return $locked;
        });

        $this->discardStoredImages($rejected->images['paths'] ?? []);
        $this->notifyMerchant($rejected->product, new ProductReviewed($rejected->product, false, $rejected));

        return $rejected;
    }

    public function withdrawChanges(ProductChangeRequest $changeRequest, User $actor, ?Request $request = null): ProductChangeRequest
    {
        $withdrawn = DB::transaction(function () use ($changeRequest, $actor, $request): ProductChangeRequest {
            $locked = $this->lockPendingChangeRequest($changeRequest);

            $locked->update([
                'status' => ProductChangeRequestStatus::Withdrawn,
                'withdrawn_at' => now(),
            ]);

            $this->auditLogger->log(
                'product.change_withdrawn',
                $actor,
                $locked->product,
                $request,
                "Changes to product #{$locked->product_id} withdrawn by the merchant.",
                ['product_id' => $locked->product_id, 'change_request_id' => $locked->id, 'fields' => $locked->changedFields()],
            );

            return $locked;
        });

        $this->discardStoredImages($withdrawn->images['paths'] ?? []);

        return $withdrawn;
    }

    /**
     * Field-by-field comparison of a pending edit against the product's current live values.
     *
     * @return list<array{field: string, current: mixed, original: mixed, requested: mixed, changed: bool}>
     */
    public function comparison(ProductChangeRequest $changeRequest, Product $product): array
    {
        $rows = [];

        foreach ($changeRequest->getAttribute('changes') ?? [] as $field => $requested) {
            $current = match ($field) {
                'gallery' => $this->currentGallery($product),
                'variants' => $this->normalizeVariants($product->variants()->get()->map->toArray()->all()),
                default => $this->normalize($field, $product->getAttribute($field)),
            };

            $rows[] = [
                'field' => $field,
                'current' => $current,
                'original' => $changeRequest->original[$field] ?? null,
                'requested' => $requested,
                'changed' => $field === 'variants'
                    ? $this->variantContent($current) !== $this->variantContent($requested)
                    : $current !== $requested,
            ];
        }

        if ($images = $changeRequest->images) {
            $rows[] = [
                'field' => 'images',
                'current' => $product->imageRecords()->pluck('path')->all(),
                'original' => null,
                'requested' => $images['paths'],
                'changed' => true,
            ];
        }

        return $rows;
    }

    /**
     * Approval decisions and submissions for one product, newest first.
     *
     * @return Collection<int, AdminAuditLog>
     */
    public function history(Product $product, int $limit = 50): Collection
    {
        return AdminAuditLog::query()
            ->with('actor:id,name,role')
            ->where('subject_type', $product->getMorphClass())
            ->where('subject_id', $product->id)
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    protected function lockPendingProduct(Product $product, ?string $expectedSubmittedAt): Product
    {
        $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

        if ($locked->status !== ProductStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'status' => ["This product is not awaiting approval (current status: {$locked->status->value})."],
            ]);
        }

        if ($expectedSubmittedAt !== null && ! $this->sameInstant($locked->submitted_at, $expectedSubmittedAt)) {
            throw new ConflictHttpException('The merchant resubmitted this product after you opened it. Reload it and review the latest version.');
        }

        return $locked;
    }

    protected function lockPendingChangeRequest(ProductChangeRequest $changeRequest): ProductChangeRequest
    {
        $locked = ProductChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);

        if (! $locked->isPending()) {
            throw ValidationException::withMessages([
                'status' => ["These changes have already been {$locked->status->value}."],
            ]);
        }

        return $locked;
    }

    protected function sameInstant(?Carbon $actual, string $expected): bool
    {
        try {
            return $actual !== null && $actual->equalTo(Carbon::parse($expected));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Unique slug and an owned category may have changed since the edit was submitted.
     *
     * @param  array<string, mixed>  $updates
     */
    protected function assertStillApplicable(Product $product, array $updates): void
    {
        if (isset($updates['slug']) && Product::query()->where('slug', $updates['slug'])->whereKeyNot($product->id)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The requested slug is now used by another product. Reject the changes and ask the merchant for a different slug.'],
            ]);
        }

        if (! empty($updates['category_id']) && ! Category::query()->where('merchant_id', $product->merchant_id)->whereKey($updates['category_id'])->exists()) {
            throw ValidationException::withMessages([
                'category_id' => ['The requested category no longer exists in this store.'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>|null  $variants
     */
    protected function assertVariantSkusAvailable(Product $product, ?array $variants): void
    {
        $skus = array_column($variants ?? [], 'sku');

        if ($skus !== [] && ProductVariant::query()->whereIn('sku', $skus)->where('product_id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'variants' => ['One or more variant SKUs are already used by another product.'],
            ]);
        }
    }

    /**
     * Fails at submission (not only at approval) when an edit would remove an ordered variant.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    protected function assertOrderedVariantsKept(Product $product, array $variants): void
    {
        $removed = $product->variants()->whereNotIn('sku', array_column($variants, 'sku'))->pluck('id');

        if ($removed->isNotEmpty() && OrderItem::query()->whereIn('product_variant_id', $removed)->exists()) {
            throw ValidationException::withMessages(['variants' => ['Ordered variants cannot be removed.']]);
        }
    }

    /**
     * @return array{ids: list<int>, main_id: int|null}
     */
    protected function currentGallery(Product $product): array
    {
        $images = $product->imageRecords()->get();

        return [
            'ids' => $images->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'main_id' => ($images->firstWhere('is_main', true) ?? $images->first())?->id,
        ];
    }

    /**
     * Variants in a canonical, stock-free form for comparison: stock changes never need review.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    protected function variantContent(array $variants): array
    {
        return array_map(function (array $variant): array {
            unset($variant['stock']);

            return $variant;
        }, $this->normalizeVariants($variants));
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    protected function normalizeVariants(array $variants): array
    {
        $normalized = [];

        foreach (array_values($variants) as $index => $variant) {
            $normalized[] = [
                'sku' => trim((string) $variant['sku']),
                'color' => $this->normalize('color', $variant['color'] ?? null),
                'size' => $this->normalize('size', $variant['size'] ?? null),
                'attributes' => ($variant['attributes'] ?? null) ?: null,
                'price' => $this->normalize('price', $variant['price'] ?? null),
                'stock' => (int) ($variant['stock'] ?? 0),
                'sort_order' => (int) ($variant['sort_order'] ?? $index),
            ];
        }

        usort($normalized, fn (array $a, array $b) => [$a['sort_order'], $a['sku']] <=> [$b['sort_order'], $b['sku']]);

        return $normalized;
    }

    /**
     * Canonical form for comparing and storing values: fixed-precision decimals, integer ids,
     * trimmed strings with "\n" line endings and blanks as null, and tag lists without blanks.
     */
    protected function normalize(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (isset(self::DECIMALS[$field])) {
            return number_format((float) $value, self::DECIMALS[$field], '.', '');
        }

        if ($field === 'category_id') {
            return (int) $value;
        }

        if ($field === 'tags') {
            $tags = array_values(array_filter(array_map(fn ($tag) => trim((string) $tag), (array) $value), fn ($tag) => $tag !== ''));

            return $tags === [] ? null : $tags;
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * Admins who can approve or reject products (with `products.approve`).
     *
     * @return Collection<int, User>
     */
    protected function reviewers(): Collection
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->with(['adminRoles.permissions', 'adminPermissions'])
            ->get()
            ->filter(fn (User $admin) => $admin->hasAdminPermission(AdminPermissionRegistry::PRODUCTS_APPROVE))
            ->values();
    }

    protected function notifyMerchant(?Product $product, object $notification): void
    {
        $owner = $product?->merchant?->user;

        if ($owner) {
            $this->notify(collect([$owner]), $notification);
        }
    }

    /**
     * Notifications are sent after the decision is committed; a mail failure is reported but never
     * undoes or blocks the approval workflow.
     */
    protected function notify(Collection $recipients, object $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        rescue(fn () => Notification::send($recipients, $notification));
    }
}
