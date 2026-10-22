<?php

namespace App\Models;

use App\Enums\MerchantChangeRequestStatus;
use App\Enums\MerchantStatus;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /**
     * Store slugs that can't be registered: each store is served at <slug>.sofiacart.ph, and these
     * subdomains belong to the platform itself (keep in sync with sofiacart-website lib/store/host.ts).
     */
    public const RESERVED_STORE_SLUGS = [
        'www', 'api', 'store-api', 'api-admin', 'admin', 'seller', 'sellers', 'merchant', 'merchants',
        'app', 'mail', 'email', 'smtp', 'imap', 'pop', 'ftp', 'webmail', 'static', 'cdn', 'assets',
        'media', 'storage', 'help', 'support', 'status', 'blog', 'docs', 'dashboard', 'store', 'stores',
        'shop', 'sofiacart', 'dev', 'staging', 'test', 'demo', 'beta', 'ns1', 'ns2', 'localhost',
    ];

    protected $fillable = [
        'user_id',
        'business_name',
        'business_type',
        'business_permit_number',
        'tin',
        'business_category',
        'business_address',
        'city',
        'province',
        'zip_code',
        'business_permit_path',
        'store_name',
        'store_slug',
        'store_category',
        'store_description',
        'store_address',
        'contact_phone',
        'contact_email',
        'store_logo_path',
        'store_banner_path',
        'social_links',
        'owner_name',
        'owner_position',
        'owner_email',
        'owner_phone',
        'owner_birth_date',
        'government_id_type',
        'government_id_number',
        'government_id_expiry_date',
        'government_id_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'owner_birth_date' => 'date',
            'government_id_expiry_date' => 'date',
            'status' => MerchantStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(MerchantChangeRequest::class);
    }

    /**
     * The profile edit awaiting admin review. The service allows at most one at a time.
     */
    public function pendingChangeRequest(): HasOne
    {
        return $this->hasOne(MerchantChangeRequest::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', MerchantChangeRequestStatus::Pending->value),
        );
    }
}
