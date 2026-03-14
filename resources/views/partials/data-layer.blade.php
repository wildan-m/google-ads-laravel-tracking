{{--
    Include this partial in your layout BEFORE the GTM / gtag.js snippet.
    The middleware shares $dataLayer (base page data + enhanced conversion user data)
    and $dataLayerEvents (queued ecommerce events).
--}}
<script>
window.dataLayer = window.dataLayer || [];

{{-- Base page data + enhanced conversion user data --}}
@if(isset($dataLayer) && !empty($dataLayer))
window.dataLayer.push({!! json_encode($dataLayer, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!});
@endif

{{-- Queued ecommerce events (view_item, add_to_cart, purchase, etc.) --}}
@if(isset($dataLayerEvents) && count($dataLayerEvents) > 0)
@foreach($dataLayerEvents as $event)
window.dataLayer.push({ ecommerce: null }); {{-- Clear previous ecommerce object --}}
window.dataLayer.push({!! json_encode($event, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!});
@endforeach
@endif
</script>
