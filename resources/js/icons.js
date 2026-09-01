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

/**
 * Iconos de ACCIÓN para los botones, cada uno una lista de trazos (algunos llevan dos). Los consume `Icon.vue`.
 *
 * Un icono por lo que HACE el botón, no por la pantalla: `edit` es el lápiz vaya donde vaya. Así el vocabulario es el
 * mismo en todo el panel y se añade una acción nueva en un solo lugar.
 */
export const ACTION_ICONS = {
    plus: ['M12 5v14', 'M5 12h14'],
    edit: ['M4 20h4L19 9l-4-4L4 16v4z', 'M13.5 6.5l4 4'],
    trash: ['M4 7h16', 'M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2', 'M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13'],
    x: ['M6 6l12 12', 'M18 6L6 18'],
    check: ['M5 12l4 4L19 7'],
    printer: ['M7 9V4h10v5', 'M7 15H5a1 1 0 0 1-1-1v-3a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-2', 'M7 13h10v7H7z'],
    copy: ['M9 9h9v9H9z', 'M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1'],
    eye: ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z', 'M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5'],
    send: ['M22 2 11 13', 'M22 2l-7 20-4-9-9-4z'],
    receive: ['M4 14v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4', 'M12 4v10', 'M8 10l4 4 4-4'],
    refresh: ['M20 11a8 8 0 1 0-1.6 4.8', 'M20 4v5h-5'],
    undo: ['M9 14 4 9l5-5', 'M4 9h11a5 5 0 0 1 0 10h-4'],
    redo: ['m15 14 5-5-5-5', 'M20 9H9a5 5 0 0 0 0 10h4'],
    key: ['M14 7a4 4 0 1 1-5.6 3.6L3 16v3h3l1-1h2v-2h2l1.4-1.4A4 4 0 0 1 14 7z', 'M15.5 8.5h.01'],
    grid: ['M4 4h6v6H4z', 'M14 4h6v6h-6z', 'M4 14h6v6H4z', 'M14 14h6v6h-6z'],
};
