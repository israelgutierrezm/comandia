/**
 * Trazos SVG (24×24, line-art como la marca). Simples a propósito; se pueden refinar sin tocar la lógica.
 *
 * Viven aquí, y no dentro de un componente, porque los comparten el shell de administración (el rail y el acordeón del
 * menú) y el encabezado de cada listado (que pinta el icono de su sección). Una sola fuente evita que se desincronicen.
 */
export const ICON_PATHS = {
    home: 'M3 10.6 12 4l9 6.6M5.2 9.4V20h13.6V9.4',
    building: 'M5 21V4h9v17M14 9h5v12M8 7.5h2.5M8 11h2.5M8 14.5h2.5',
    tag: 'M4 4h7l9 9-7 7-9-9V4Z',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2ZM9 8h6M9 12h6',
    box: 'M3 8 12 4l9 4-9 4-9-4ZM3 8v8l9 4 9-4V8M12 12v8',
    truck: 'M3 6h11v9H3zM14 9h4l3 3v3h-2M8 18a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0ZM19 18a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z',
    users: 'M17 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM21 20v-1a4 4 0 0 0-3-3.87',
    user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
    shop: 'M4 9h16l-1.2-4H5.2L4 9ZM5.2 9v11h13.6V9M9.5 20v-6h5v6',
    chart: 'M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6',
    chevron: 'm14.5 6-6 6 6 6',
    dot: 'M12 8v8M8 12h8',
};
