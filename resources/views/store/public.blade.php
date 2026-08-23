<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $store['name'] }} — Tienda</title>
    <meta name="description" content="Compra en línea en {{ $store['name'] }}.">
    <meta property="og:title" content="{{ $store['name'] }} — Tienda">
    <meta name="robots" content="index,follow">

    @vite('resources/js/public/store.js')
</head>
<body>
    <div id="store-app"></div>

    <script>
        window.__COMANDIA_STORE__ = @json($store);
    </script>
</body>
</html>
