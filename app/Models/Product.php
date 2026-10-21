<?php

namespace App\Models;

use App\Enums\MerchantStatus;
use App\Enums\ProductChangeRequestStatus;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'description',
        'short_description',
        'full_description',
        'status',
        'price',
        'regular_price',
        'sale_price',
        'cost_price',
        'brand',
        'condition',
        'weight',
        'tags',
        'track_inventory',
        'stock_quantity',
        'low_stock_threshold',
        'length',
        'width',
        'height',
        'images',
    ];

    /**
     * Approval bookkeeping is written only by ProductApprovalService, never mass-assigned from a request.
     *
     * @var list<string>
     */
    public const REVIEW_FIELDS = ['submitted_at', 'approved_at', 'reviewed_by', 'reviewed_at', 'rejection_reason'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'tags' => 'array',
            'track_inventory' => 'boolean',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'images' => 'array',
            'status' => ProductStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function imageRecords(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ProductChangeRequest::class);
    }

    public function pendingChangeRequest(): HasOne
    {
        return $this->hasOne(ProductChangeRequest::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('status', ProductChangeRequestStatus::Pending->value),
        );
    }

    public function latestChangeRequest(): HasOne
    {
        return $this->hasOne(ProductChangeRequest::class)->latestOfMany();
    }

    /**
     * A Super Admin approved this product at least once. Only approved products may be active.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * What a customer would see: the storefront shows only active, approved products of verified
     * merchants (sofiacart-webiste-backend Product::storefrontVisible()), and in-stock ones are buyable.
     */
    public function listingStatus(): string
    {
        if (! $this->isApproved()) {
            return match ($this->status) {
                ProductStatus::PendingApproval => 'pending_approval',
                ProductStatus::Rejected => 'rejected',
                ProductStatus::Archived => 'archived',
                default => 'draft',
            };
        }

        if ($this->status !== ProductStatus::Active) {
            return 'hidden';
        }

        $merchantStatus = $this->relationLoaded('merchant') ? $this->merchant?->status : $this->merchant()->value('status');
        $merchantStatus = $merchantStatus instanceof MerchantStatus ? $merchantStatus : MerchantStatus::tryFrom((string) $merchantStatus);

        if ($merchantStatus !== MerchantStatus::Verified) {
            return 'store_unavailable';
        }

        $variantInStock = $this->relationLoaded('variants')
            ? $this->variants->contains(fn (ProductVariant $variant) => $variant->stock > 0)
            : $this->variants()->where('stock', '>', 0)->exists();

        return $this->stock_quantity > 0 || $variantInStock
            ? 'published'
            : 'out_of_stock';
    }

    /**
     * Products a Super Admin must act on: new submissions and pending edits to approved products.
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where(function (Builder $builder): void {
            $builder->where('status', ProductStatus::PendingApproval->value)
                ->orWhereHas('changeRequests', fn (Builder $requests) => $requests->where('status', ProductChangeRequestStatus::Pending->value));
        });
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity <= 0) {
            return 'out_of_stock';
        }

        return $this->track_inventory && $this->stock_quantity <= $this->low_stock_threshold
            ? 'low_stock'
            : 'active';
    }
}
