<script setup>
/**
 * Tabla de listado con estados de carga, vacío y error.
 *
 * Los tres estados están aquí y no en cada pantalla porque son los que más se olvidan: una tabla que
 * no distingue "cargando" de "no hay nada" hace que el usuario crea que no tiene datos, y una que no
 * muestra el error deja un listado vacío donde en realidad hubo un 403.
 */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    emptyMessage: { type: String, default: 'No hay registros.' },
});
</script>

<template>
    <div class="wrapper">
        <div v-if="error" class="state state--error">
            <p class="state__title">
                {{ error.isForbidden ? 'No tienes permiso para ver esta información.' : error.message }}
            </p>
            <p v-if="error.isForbidden" class="state__hint">
                Si crees que deberías tenerlo, pide que revisen tu rol.
            </p>
        </div>

        <table v-else class="table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" :style="column.width ? `width:${column.width}` : ''">
                        {{ column.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-if="loading">
                    <td :colspan="columns.length" class="state">Cargando…</td>
                </tr>

                <tr v-else-if="rows.length === 0">
                    <td :colspan="columns.length" class="state">{{ emptyMessage }}</td>
                </tr>

                <tr v-for="(row, index) in loading ? [] : rows" :key="row.ulid ?? index">
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="`cell:${column.key}`" :row="row">
                            {{ row[column.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.wrapper {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0.5rem;
    /* Las tablas anchas se desplazan dentro de su contenedor: el cuerpo de la página nunca. */
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

th,
td {
    padding: 0.6rem 0.85rem;
    text-align: left;
    border-bottom: 1px solid #f5f5f4;
    white-space: nowrap;
}

th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.6;
    background: #fafaf9;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.state {
    padding: 1.5rem;
    text-align: center;
    opacity: 0.65;
}

.state--error {
    text-align: left;
    opacity: 1;
    color: #b91c1c;
}

.state__title {
    margin: 0;
    font-weight: 500;
}

.state__hint {
    margin: 0.25rem 0 0;
    font-size: 0.85rem;
    color: #78716c;
}
</style>
