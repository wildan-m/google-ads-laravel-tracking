<?php

declare(strict_types=1);

use App\Services\Tracking\Services\ConversionTracker;

if (! function_exists('push_data_layer')) {
    /**
     * Queue a custom dataLayer event for the next page render.
     *
     * @param  array<string, mixed>  $event
     */
    function push_data_layer(array $event): void
    {
        app(ConversionTracker::class)->queueEvent($event);
    }
}

if (! function_exists('track_purchase')) {
    /**
     * Convenience wrapper — queue a purchase conversion event.
     *
     * @param  string                            $transactionId
     * @param  array<int, array<string, mixed>>  $items
     * @param  float                             $value
     * @param  string                            $currency
     * @param  array<string, mixed>              $extra  Optional: tax, shipping, coupon.
     */
    function track_purchase(
        string $transactionId,
        array $items,
        float $value,
        string $currency = 'USD',
        array $extra = [],
    ): void {
        app(ConversionTracker::class)->purchase(
            $transactionId,
            $items,
            $value,
            $currency,
            $extra,
        );
    }
}

if (! function_exists('hash_user_data')) {
    /**
     * SHA-256 hash a value for Enhanced Conversions (trim + lowercase first).
     */
    function hash_user_data(string $value): string
    {
        return ConversionTracker::hashValue($value);
    }
}
