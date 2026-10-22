<?php

namespace App\Models;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentStatus;
use App\Enums\LegalDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded version of a Terms and Conditions or Data Privacy Policy file. Versions are kept
 * forever; at most one per audience and type is published (see LegalDocumentService).
 */
class LegalDocument extends Model
{
    protected $fillable = [
        'audience',
        'type',
        'version',
        'version_label',
        'effective_date',
        'status',
        'is_current',
        'original_filename',
        'file_path',
        'mime_type',
        'file_size',
        'checksum',
        'notes',
        'uploaded_by',
        'uploaded_at',
        'published_by',
        'published_at',
        'unpublished_by',
        'unpublished_at',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'audience' => LegalDocumentAudience::class,
            'type' => LegalDocumentType::class,
            'status' => LegalDocumentStatus::class,
            'version' => 'integer',
            'is_current' => 'boolean',
            'file_size' => 'integer',
            'effective_date' => 'date',
            'uploaded_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function scopeForSlot(Builder $query, LegalDocumentAudience $audience, LegalDocumentType $type): Builder
    {
        return $query->where('audience', $audience->value)->where('type', $type->value);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', LegalDocumentStatus::Published->value)->where('is_current', true);
    }

    public function isPublished(): bool
    {
        return $this->status === LegalDocumentStatus::Published;
    }

    public function format(): string
    {
        return $this->mime_type === 'application/pdf' ? 'pdf' : 'docx';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function unpublisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unpublished_by');
    }
}
