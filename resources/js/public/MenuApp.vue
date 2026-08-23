<script setup>
import { computed } from 'vue';

/**
 * El menú público (Iteración 8, Tanda A). Sólo pinta lo que el servidor ya resolvió: secciones, artículos, precios (si el
 * menú los muestra) y fotos. No tiene lógica de negocio —ni pide datos—: el precio y la disponibilidad los decidió el
 * backend en la sucursal (ADR-006/§ frontend previsualiza, el backend decide).
 */
const props = defineProps({
    menu: { type: Object, required: true },
});

const primary = computed(() => props.menu.theme?.primary || '#1c1917');
</script>

<template>
    <div class="menu" :style="{ '--primary': primary }">
        <header class="menu__head">
            <h1>{{ menu.name }}</h1>
        </header>

        <p v-if="!menu.sections.length" class="menu__empty">Este menú aún no tiene platillos disponibles.</p>

        <section v-for="section in menu.sections" :key="section.name" class="section">
            <h2 class="section__title">{{ section.name }}</h2>

            <ul class="items">
                <li v-for="(item, i) in section.items" :key="i" class="item">
                    <img v-if="item.image" :src="item.image" :alt="item.name" class="item__img" loading="lazy" />
                    <div class="item__body">
                        <div class="item__row">
                            <span class="item__name">{{ item.name }}</span>
                            <span v-if="menu.show_prices && item.price" class="item__price">${{ item.price }}</span>
                        </div>
                        <p v-if="item.description" class="item__desc">{{ item.description }}</p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>

<style scoped>
.menu { max-width: 40rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; font-family: ui-sans-serif, system-ui, sans-serif; color: #1c1917; }
.menu__head { text-align: center; margin-bottom: 1.5rem; }
.menu__head h1 { margin: 0; font-size: 1.6rem; color: var(--primary); }
.menu__empty { text-align: center; color: #78716c; }
.section { margin-bottom: 1.75rem; }
.section__title { font-size: 1.05rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--primary); border-bottom: 2px solid var(--primary); padding-bottom: 0.25rem; margin: 0 0 0.75rem; }
.items { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.9rem; }
.item { display: flex; gap: 0.8rem; align-items: flex-start; }
.item__img { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: 8px; flex: none; }
.item__body { flex: 1; min-width: 0; }
.item__row { display: flex; justify-content: space-between; gap: 0.75rem; align-items: baseline; }
.item__name { font-weight: 600; }
.item__price { font-variant-numeric: tabular-nums; color: var(--primary); font-weight: 600; white-space: nowrap; }
.item__desc { margin: 0.15rem 0 0; font-size: 0.9rem; color: #57534e; line-height: 1.35; }
</style>
