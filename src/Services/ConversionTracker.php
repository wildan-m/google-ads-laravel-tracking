<?php

declare(strict_types=1);

namespace App\Services\Tracking\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Builds Google Ads-compatible dataLayer push events for ecommerce tracking.
 *
 * Supports both gtag.js and Google Tag Manager approaches. Handles
 * Enhanced Conversions user data with proper SHA-256 hashing and
 * formatting per Google's specification.
 *
 * @see https://developers.google.com/tag-platform/security/guides/enhanced-conversions
 * @see https://developers.google.com/analytics/devguides/collection/ga4/ecommerce
 */
class ConversionTracker
{
    /** Session key used to queue events across redirects. */
    private const SESSION_KEY = 'tracking.data_layer_events';

    // ---------------------------------------------------------------
    //  Page-level data
    // ---------------------------------------------------------------

    /**
     * Base dataLayer object pushed on every page.
     *
     * @return array<string, mixed>
     */
    public function buildPageView(Request $request): array
    {
        return [
            'event'     => 'page_view',
            'page_path' => $request->getPathInfo(),
            'page_title' => '',
        ];
    }

    // ---------------------------------------------------------------
    //  Enhanced Conversions — user data
    // ---------------------------------------------------------------

    /**
     * Build the `enhanced_conversion_data` object Google Ads expects.
     *
     * Values are SHA-256 hashed, trimmed, and lowercased before hashing,
     * exactly as Google requires.
     *
     * @param  object  $user  Any object exposing email, phone, first_name,
     *                        last_name, street, city, region, postal_code,
     *                        country properties (all optional).
     * @return array<string, mixed>
     */
    public function buildEnhancedConversionData(object $user): array
    {
        $data = [];

        if ($email = $this->prop($user, 'email')) {
            $data['sha256_email_address'] = self::hashValue($email);
        }

        if ($phone = $this->prop($user, 'phone')) {
            $data['sha256_phone_number'] = self::hashValue(
                self::normalizePhone($phone),
            );
        }

        $address = array_filter([
            'sha256_first_name' => self::hashValue($this->prop($user, 'first_name') ?? ''),
            'sha256_last_name'  => self::hashValue($this->prop($user, 'last_name') ?? ''),
            'street'            => $this->prop($user, 'street') ?? '',
            'city'              => $this->prop($user, 'city') ?? '',
            'region'            => $this->prop($user, 'region') ?? '',
            'postal_code'       => $this->prop($user, 'postal_code') ?? '',
            'country'           => $this->prop($user, 'country') ?? '',
        ]);

        if (count($address) > 0) {
            $data['address'] = $address;
        }

        if (empty($data)) {
            return [];
        }

        return ['enhanced_conversion_data' => $data];
    }

    // ---------------------------------------------------------------
    //  Ecommerce events
    // ---------------------------------------------------------------

    /**
     * Push a `view_item` event onto the queue.
     *
     * @param  array<string, mixed>  $item  Product data (item_id, item_name, price, …).
     */
    public function viewItem(array $item): void
    {
        $this->queueEvent([
            'event'     => 'view_item',
            'ecommerce' => [
                'currency' => $item['currency'] ?? 'USD',
                'value'    => (float) ($item['price'] ?? 0),
                'items'    => [$this->normalizeItem($item)],
            ],
        ]);
    }

    /**
     * Push an `add_to_cart` event onto the queue.
     *
     * @param  array<string, mixed>  $item
     */
    public function addToCart(array $item, int $quantity = 1): void
    {
        $normalized = $this->normalizeItem($item);
        $normalized['quantity'] = $quantity;

        $this->queueEvent([
            'event'     => 'add_to_cart',
            'ecommerce' => [
                'currency' => $item['currency'] ?? 'USD',
                'value'    => (float) ($item['price'] ?? 0) * $quantity,
                'items'    => [$normalized],
            ],
        ]);
    }

    /**
     * Push a `begin_checkout` event onto the queue.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function beginCheckout(array $items, float $value, string $currency = 'USD'): void
    {
        $this->queueEvent([
            'event'     => 'begin_checkout',
            'ecommerce' => [
                'currency' => $currency,
                'value'    => $value,
                'items'    => array_map([$this, 'normalizeItem'], $items),
            ],
        ]);
    }

    /**
     * Push a `purchase` event onto the queue.
     *
     * This is the event Google Ads uses for conversion measurement.
     *
     * @param  string                             $transactionId  Unique order ID.
     * @param  array<int, array<string, mixed>>   $items
     * @param  array<string, mixed>               $extra          Optional: tax, shipping, coupon.
     */
    public function purchase(
        string $transactionId,
        array $items,
        float $value,
        string $currency = 'USD',
        array $extra = [],
    ): void {
        $this->queueEvent([
            'event'     => 'purchase',
            'ecommerce' => array_merge([
                'transaction_id' => $transactionId,
                'currency'       => $currency,
                'value'          => $value,
                'items'          => array_map([$this, 'normalizeItem'], $items),
            ], array_intersect_key($extra, array_flip([
                'tax', 'shipping', 'coupon', 'affiliation',
            ]))),
        ]);
    }

    // ---------------------------------------------------------------
    //  gtag.js helper — alternative to GTM dataLayer pushes
    // ---------------------------------------------------------------

    /**
     * Return a JavaScript string that calls `gtag('event', ...)` directly.
     *
     * Useful when the site uses gtag.js without GTM.
     */
    public function gtagScript(string $event, array $params): string
    {
        $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return "gtag('event', " . json_encode($event) . ", {$json});";
    }

    // ---------------------------------------------------------------
    //  Event queue (survives redirects via session)
    // ---------------------------------------------------------------

    /**
     * Queue a dataLayer event for the next rendered page.
     *
     * @param  array<string, mixed>  $event
     */
    public function queueEvent(array $event): void
    {
        $events   = Session::get(self::SESSION_KEY, []);
        $events[] = $event;
        Session::put(self::SESSION_KEY, $events);
    }

    /**
     * Retrieve and clear all queued events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flushQueuedEvents(): array
    {
        $events = Session::get(self::SESSION_KEY, []);
        Session::forget(self::SESSION_KEY);

        return $events;
    }

    // ---------------------------------------------------------------
    //  Internal helpers
    // ---------------------------------------------------------------

    /**
     * SHA-256 hash a value after trimming and lowercasing.
     *
     * Returns an empty string for blank input so callers can use
     * `array_filter()` to strip missing fields.
     */
    public static function hashValue(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        return hash('sha256', $value);
    }

    /**
     * Strip everything except digits and a leading `+` from a phone number.
     */
    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? $phone;
    }

    /**
     * Map arbitrary product arrays to GA4 item schema.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        return array_filter([
            'item_id'       => $item['item_id']   ?? ($item['id'] ?? ($item['sku'] ?? null)),
            'item_name'     => $item['item_name']  ?? ($item['name'] ?? null),
            'price'         => isset($item['price']) ? (float) $item['price'] : null,
            'quantity'      => isset($item['quantity']) ? (int) $item['quantity'] : null,
            'item_category' => $item['item_category'] ?? ($item['category'] ?? null),
            'item_brand'    => $item['item_brand']    ?? ($item['brand'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Safely read a property from a user object or array.
     */
    private function prop(object $user, string $key): ?string
    {
        if (method_exists($user, 'getAttribute')) {
            $val = $user->getAttribute($key);
        } else {
            $val = $user->{$key} ?? null;
        }

        return is_string($val) && $val !== '' ? $val : null;
    }
}
