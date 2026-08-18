<script setup>
import { ref, watch } from 'vue';
import { api, ApiError } from '../../api/client';

/**
 * Buscador de artículos para elegir uno.
 *
 * Un `<select>` con todos los artículos no sirve aquí: un restaurante mediano tiene cientos, y
 * cargarlos todos para elegir un ingrediente sería traer el catálogo completo en cada línea de cada
 * receta. Se busca contra el servidor —que ya sabe filtrar por nombre y código— y se muestran los
 * primeros resultados.
 *
 * El artículo excluido es el propio dueño de la receta: un artículo que se contiene a sí mismo es el
 * ciclo más corto posible, y aunque el servidor lo rechaza, ofrecerlo sería ofrecer un error.
 */
const props = defineProps({
    excludeUlid: { type: String, default: null },
    placeholder: { type: String, default: 'Buscar artículo…' },
});

const emit = defineEmits(['picked']);

const term = ref('');
const results = ref([]);
const searching = ref(false);
const open = ref(false);
const error = ref(null);

let timer = null;

watch(term, () => {
    clearTimeout(timer);
    error.value = null;

    if (term.value.trim().length < 2) {
        results.value = [];
        open.value = false;

        return;
    }

    // Los resultados anteriores se descartan al empezar a buscar otra cosa. Sin esto, un fallo de la
    // consulta dejaba en pantalla los resultados de la búsqueda ANTERIOR: se buscaba «azúcar» y se
    // seguía viendo «Jitomate», que es el peor resultado posible porque parece una respuesta. Lo
    // encontró el navegador, y de paso destapó un 500 del servidor que ninguna prueba veía.
    results.value = [];

    // Se espera antes de consultar: teclear «jitomate» dispararía ocho búsquedas y las respuestas
    // pueden llegar desordenadas, dejando en pantalla el resultado de «jito».
    timer = setTimeout(async () => {
        const asked = term.value.trim();

        searching.value = true;
        open.value = true;

        try {
            const response = await api.get('/articles', {
                search: asked,
                status: 'active',
                per_page: 10,
            });

            // Sólo se pinta si sigue siendo la búsqueda vigente: dos respuestas en camino pueden
            // llegar al revés, y la lenta de «jito» sobrescribiría a la de «jitomate».
            if (asked !== term.value.trim()) {
                return;
            }

            results.value = (response.data ?? []).filter((article) => article.ulid !== props.excludeUlid);
        } catch (e) {
            if (!(e instanceof ApiError)) {
                throw e;
            }

            // Se muestra. La primera versión no capturaba nada: la excepción se perdía en una promesa
            // sin dueño y el usuario se quedaba mirando una lista que no correspondía a lo que escribió.
            error.value = e.message;
        } finally {
            searching.value = false;
        }
    }, 250);
});

function pick(article) {
    emit('picked', article);
    term.value = '';
    results.value = [];
    open.value = false;
    error.value = null;
}
</script>

<template>
    <div class="picker">
        <input v-model="term" type="search" class="input" :placeholder="props.placeholder" />

        <ul v-if="open" class="results">
            <li v-if="searching" class="result result--quiet">Buscando…</li>

            <li v-else-if="error" class="result result--error">{{ error }}</li>

            <li v-else-if="results.length === 0" class="result result--quiet">
                Nada coincide con «{{ term }}».
            </li>

            <li v-for="article in results" :key="article.ulid">
                <button class="result" type="button" @click="pick(article)">
                    <span>{{ article.name }}</span>
                    <span class="result__meta">
                        {{ article.base_unit?.code ?? '' }}
                        <!--
                            Se muestran también los que no son insumo ni producible. El servidor sólo
                            exige que estén activos, y filtrarlos aquí ocultaría un caso legítimo: una
                            cerveza vendible que se usa para preparar un michelado.
                        -->
                        <template v-if="!article.capabilities?.supply && !article.capabilities?.producible">
                            · no marcado como insumo
                        </template>
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.picker {
    position: relative;
}

.results {
    position: absolute;
    z-index: 5;
    top: calc(100% + 0.15rem);
    left: 0;
    right: 0;
    max-height: 14rem;
    overflow-y: auto;
    margin: 0;
    padding: 0;
    list-style: none;
    background: #fff;
    border: 1px solid #d6d3d1;
    border-radius: 0.375rem;
    box-shadow: 0 6px 16px rgb(0 0 0 / 10%);
}

.result {
    display: flex;
    flex-direction: column;
    width: 100%;
    gap: 0.1rem;
    padding: 0.4rem 0.6rem;
    background: none;
    border: 0;
    font: inherit;
    font-size: 0.85rem;
    text-align: left;
    cursor: pointer;
}

.result:hover {
    background: #fafaf9;
}

.result--quiet {
    opacity: 0.55;
    cursor: default;
}

.result--error {
    color: #b91c1c;
    cursor: default;
}

.result__meta {
    font-size: 0.72rem;
    opacity: 0.55;
}
</style>
