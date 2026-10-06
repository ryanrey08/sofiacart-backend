<?php

namespace App\Notifications;

/**
 * Readable field names for notification text ("store_logo" → "store logo").
 */
class NotificationFields
{
    /**
     * @param  array<int, string>  $fields
     */
    public static function describe(array $fields): string
    {
        return implode(', ', array_map(fn (string $field) => str_replace('_', ' ', $field), $fields));
    }
}
