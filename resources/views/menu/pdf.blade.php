<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 2.2cm 1.8cm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1c1917; font-size: 11pt; }
        h1 { text-align: center; color: {{ $menu['theme']['primary'] }}; margin: 0 0 1.2rem; font-size: 20pt; }
        .section { margin-bottom: 1rem; }
        .section-title {
            text-transform: uppercase; letter-spacing: 1px; font-size: 12pt; font-weight: bold;
            color: {{ $menu['theme']['primary'] }};
            border-bottom: 2px solid {{ $menu['theme']['primary'] }};
            padding-bottom: 3px; margin-bottom: 8px;
        }
        .item { margin-bottom: 7px; }
        .item-head { width: 100%; }
        .item-name { font-weight: bold; }
        .item-price { float: right; font-weight: bold; color: {{ $menu['theme']['primary'] }}; }
        .item-desc { font-size: 9.5pt; color: #57534e; margin-top: 1px; }
        .empty { text-align: center; color: #78716c; }
    </style>
</head>
<body>
    <h1>{{ $menu['name'] }}</h1>

    @forelse ($menu['sections'] as $section)
        <div class="section">
            <div class="section-title">{{ $section['name'] }}</div>

            @foreach ($section['items'] as $item)
                <div class="item">
                    <div class="item-head">
                        @if ($menu['show_prices'] && $item['price'])
                            <span class="item-price">${{ $item['price'] }}</span>
                        @endif
                        <span class="item-name">{{ $item['name'] }}</span>
                    </div>
                    @if ($item['description'])
                        <div class="item-desc">{{ $item['description'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <p class="empty">Este menú aún no tiene platillos disponibles.</p>
    @endforelse
</body>
</html>
