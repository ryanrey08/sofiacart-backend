<?php

namespace App\Models;

use App\Enums\LegalDocumentAudience;
use App\Enums\LegalDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant user's acceptance (Terms) or acknowledgment (Privacy Policy) of one exact
 * legal document version, recorded server-side at registration.
 */
class UserLegalConsent extends Model
{
    protected $fillable = [
        'user_id',
        'legal_document_id',
        'audience',
        'type',
        'version',
        'action',
        'context',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'audience' => LegalDocumentAudience::class,
            'type' => LegalDocumentType::class,
            'version' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }
}
