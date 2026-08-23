<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- SEO server-side: el título y la descripción se rinden aquí (el cliente no ejecuta JS para el rastreador). --}}
    <title>{{ $menu['name'] }} — Menú</title>
    <meta name="description" content="Menú de {{ $menu['name'] }}.">
    <meta property="og:title" content="{{ $menu['name'] }} — Menú">
    <meta name="robots" content="index,follow">

    @vite('resources/js/public/menu.js')
</head>
<body>
    <div id="menu-app"></div>

    {{-- Los datos ya resueltos por el servidor: la SPA hidrata sin una segunda petición. --}}
    <script>
        window.__COMANDIA_MENU__ = @json($menu);
    </script>
</body>
</html>
