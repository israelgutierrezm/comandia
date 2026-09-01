<script setup>
/**
 * Cuadrícula de listado con los mismos estados que `DataTable` (carga, vacío, error), para que la vista de cuadrícula
 * de cualquier pantalla no repita esa coreografía. Cada pantalla dibuja su tarjeta por el slot `card`; la cuadrícula
 * sólo pone la rejilla responsiva y los estados.
 */
defineProps({
    items: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    emptyMessage: { type: String, default: 'No hay registros.' },
    /** Ancho mínimo de cada tarjeta; la rejilla acomoda cuantas quepan. */
    minCard: { type: String, default: '14rem' },
});
</script>

<template>
    <div v-if="error" class="state state--error">
        <p class="state__title">
            {{ error.isForbidden ? 'No tienes permiso para ver esta información.' : error.message }}
        </p>
        <p v-if="error.isForbidden" class="state__hint">Si crees que deberías tenerlo, pide que revisen tu rol.</p>
    </div>

    <template v-else-if="loading"></template>

    <p v-else-if="items.length === 0" class="state">{{ emptyMessage }}</p>

    <div v-else class="grid" :style="{ '--min-card': minCard }">
        <div v-for="(item, index) in items" :key="item.ulid ?? index" class="grid__cell">
            <slot name="card" :item="item" />
        </div>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--min-card), 1fr));
    gap: 0.85rem;
}

.state {
    padding: 1.5rem;
    text-align: center;
    color: var(--color-suave);
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
}
.state--error { text-align: left; color: var(--color-peligro); }
.state__title { margin: 0; font-weight: 500; }
.state__hint { margin: 0.25rem 0 0; font-size: 0.85rem; color: var(--color-suave); }
</style>
