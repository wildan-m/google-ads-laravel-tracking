# Laravel Google Ads Enhanced Conversions Tracking

Reusable module for integrating Google Ads Enhanced Conversions with a Laravel ecommerce application. Provides a clean data layer implementation, proper SHA-256 hashing of user data, and support for both gtag.js and Google Tag Manager.

## What it does

- Injects a `dataLayer` on every page via middleware (page view + hashed user data for Enhanced Conversions)
- Queues ecommerce events (`view_item`, `add_to_cart`, `begin_checkout`, `purchase`) that survive redirects
- Hashes PII (email, phone, name) per Google's Enhanced Conversions specification
- Works with both GTM and direct gtag.js setups

## Integration

### 1. Register the middleware

```php
// app/Http/Kernel.php — add to the 'web' group
\App\Services\Tracking\Middleware\DataLayerMiddleware::class,
```

### 2. Include the Blade partial

Add this **before** your GTM or gtag.js snippet in your layout:

```blade
@include('tracking::partials.data-layer')
```

### 3. Track ecommerce events

```php
use App\Services\Tracking\Services\ConversionTracker;

// In a controller — product page
app(ConversionTracker::class)->viewItem([
    'id'       => $product->sku,
    'name'     => $product->name,
    'price'    => $product->price,
    'category' => $product->category->name,
]);

// After a successful order
track_purchase(
    transactionId: $order->reference,
    items:         $order->items->map(fn ($i) => [
        'id'       => $i->sku,
        'name'     => $i->name,
        'price'    => $i->unit_price,
        'quantity' => $i->quantity,
    ])->all(),
    value:    $order->total,
    currency: 'USD',
    extra:    ['shipping' => $order->shipping, 'tax' => $order->tax],
);
```

## Supported events

| Event             | Method                          |
|-------------------|---------------------------------|
| `page_view`       | Automatic via middleware         |
| `view_item`       | `ConversionTracker::viewItem()` |
| `add_to_cart`     | `ConversionTracker::addToCart()` |
| `begin_checkout`  | `ConversionTracker::beginCheckout()` |
| `purchase`        | `ConversionTracker::purchase()` |

## Enhanced Conversions

User data (email, phone, address) is automatically hashed with SHA-256 and pushed in the format Google Ads expects. The middleware attaches this data on every page when a user is authenticated, so the Google Ads tag can pick it up for conversion matching.
