<?php

declare(strict_types=1);

namespace App\Services\Tracking\Middleware;

use App\Services\Tracking\Services\ConversionTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that injects dataLayer variables into every response.
 *
 * Shares a `dataLayer` variable with all views so the Blade partial
 * can render the <script> block before GTM / gtag.js loads.
 */
class DataLayerMiddleware
{
    public function __construct(
        private readonly ConversionTracker $tracker,
    ) {}

    /**
     * Attach base dataLayer entries (page view + enhanced conversion user data)
     * to the view, then continue the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $baseLayer = $this->tracker->buildPageView($request);

        if ($user) {
            $baseLayer = array_merge(
                $baseLayer,
                $this->tracker->buildEnhancedConversionData($user),
            );
        }

        view()->share('dataLayer', $baseLayer);
        view()->share('dataLayerEvents', $this->tracker->flushQueuedEvents());

        return $next($request);
    }
}
