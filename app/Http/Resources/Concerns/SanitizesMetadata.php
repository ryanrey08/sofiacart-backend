<?php

namespace App\Http\Resources\Concerns;

trait SanitizesMetadata
{
    /**
     * Recursively sanitize metadata to mask sensitive gateway tokens, keys, and credentials.
     */
    protected function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        return $this->redactSensitiveValues($metadata);
    }

    protected function redactSensitiveValues(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $isSensitive = is_string($key) && (bool) preg_match(
                '/(password|token|secret|authorization|(^|[_-])auth([_-]|$)|api_?key|private_?key|client_?secret|cvv|cvc|card_?number|security_?code|(^|[_-])pin([_-]|$)|credential)/i',
                $key
            );

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redactSensitiveValues($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
