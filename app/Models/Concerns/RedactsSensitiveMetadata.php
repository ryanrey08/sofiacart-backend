<?php

namespace App\Models\Concerns;

use App\Http\Resources\Concerns\SanitizesMetadata;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Masks card data, secrets and credentials in `metadata` before it is written, so they are
 * never stored (API resources redact again on output).
 */
trait RedactsSensitiveMetadata
{
    use SanitizesMetadata;

    protected function metadata(): Attribute
    {
        return Attribute::make(
            set: fn (?array $value) => $value === null ? null : json_encode($this->sanitizeMetadata($value)),
        );
    }
}
