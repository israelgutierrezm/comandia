<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { pushToast } from '../../../stores/useToasts';
import { formatInBranchTime } from '../../../support/datetime';
import { useLiveRefresh } from '../../../composables/useLiveRefresh';
import { suscribir } from '../../../support/echo';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * El tablero de cocina (KDS, MVP acotado — D350): la pantalla de la cocina, ahora interactiva.
 *
 * ## De espejo del papel a tablero que se marca
 *
 * Antes esto sólo REFLEJABA las comandas (un monitor en vez de papel), y su propio comentario admitía que «marcar
 * comandas es una función que no está diseñada». D350 la diseñó: cada platillo avanza `comandado → preparando → listo`
 * con un toque, y la comanda cae del tablero cuando todo queda listo. El estado vivo lo sirve el backend
 * (`/kds/areas/{área}/tickets`), no un filtro por jornada.
 *
 * Sigue siendo un ESPEJO de la impresión, no su sustituto: el trabajo de impresión se genera igual y, si el monitor se
 * apaga, la comanda sale por la impresora. Marcar es una ayuda, no un mecanismo del que dependa cobrar.
 *
 * ## Una sola área a la vez, y sin dinero
 *
 * Cocina y barra son dos puestos con dos personas; mezclarlas obligaría a filtrar con la vista en hora pico. Y ni
 * precios ni total: a quien cocina no le sirven.
 */
const page = usePage();

const areas = ref([]);
const areaUlid = ref(null);
const comandas = ref([]);
const loading = ref(true);
const loadError = ref(null);
const enviando = ref(false); // hay un bump en vuelo: evita doble toque

const activeBranch = computed(() => {
    const contexto = page.props.context;

    return contexto?.branch_ulid
        ? { ulid: contexto.branch_ulid, name: contexto.branch_name, timezone: contexto.branch_timezone }
        : null;
});

async function cargar() {
    if (! activeBranch.value) {
        loading.value = false;
        loadError.value = { title: 'Elige una sucursal para ver sus comandas.' };

        return;
    }

    try {
        if (areas.value.length === 0) {
            // Sólo las áreas con tablero (uses_kds): las que se atienden por pantalla, no por impresora.
            areas.value = ((await api.get('/preparation-areas', {
                branch: activeBranch.value.ulid,
                status: 'active',
                per_page: 20,
            })).data ?? []).filter((a) => a.uses_kds);

            areaUlid.value ??= areas.value[0]?.ulid ?? null;
        }

        if (! areaUlid.value) {
            comandas.value = [];
            loadError.value = null;

            return;
        }

        // El backend ya devuelve SÓLO lo activo (comandado/preparando) del área, con el estado vivo de cada línea.
        comandas.value = (await api.get(`/kds/areas/${areaUlid.value}/tickets`)).data;
        loadError.value = null;
    } catch (e) {
        if (e instanceof ApiError) {
            loadError.value = e;
        } else {
            throw e;
        }
    } finally {
        loading.value = false;
    }
}

const { source, lastRefreshAt, refrescarYMarcar, socketConectado, socketCaido } = useLiveRefresh(cargar);

let darDeBaja = () => {};

/**
 * La suscripción sigue al área elegida. Escucha lo que LLEGA (comanda nueva) y lo que otra pantalla MARCÓ (avance), y
 * en ambos casos recarga: el tablero se pinta con una sola petición, así que del canal necesita el aviso, no el detalle.
 */
watch([areaUlid, activeBranch], () => {
    darDeBaja();

    const tenant = page.props.context?.tenant?.ulid;
    const branch = activeBranch.value?.ulid;

    if (! tenant || ! branch || ! areaUlid.value) {
        return;
    }

    darDeBaja = suscribir(
        `tenant.${tenant}.branch.${branch}.area.${areaUlid.value}`,
        {
            'area.order-commanded': () => refrescarYMarcar(),
            'area.items-advanced': () => refrescarYMarcar(),
        },
        { onConectado: socketConectado, onCaido: socketCaido },
    );
}, { immediate: true });

// El reloj de espera avanza solo: sin esto, el color de una comanda sólo cambiaría al recargar, y una comanda que lleva
// rato se vería «fresca» hasta el siguiente sondeo.
const ahora = ref(Date.now());
let reloj = null;
onMounted(() => { reloj = setInterval(() => { ahora.value = Date.now(); }, 30000); });
onBeforeUnmount(() => { clearInterval(reloj); darDeBaja(); });

function cambiarArea(ulid) {
    areaUlid.value = ulid;
    refrescarYMarcar();
}

/** Minutos que lleva esperando la comanda (desde que se comandó). */
function esperaMin(iso) {
    if (! iso) {
        return 0;
    }

    return Math.max(0, Math.floor((ahora.value - new Date(iso).getTime()) / 60000));
}

/** Color por espera: fresca (verde), media (ámbar), tarde (rojo). Umbrales simples del MVP. */
function nivelEspera(iso) {
    const m = esperaMin(iso);

    return m >= 12 ? 'tarde' : m >= 6 ? 'media' : 'fresca';
}

/** Avanza una línea (preparando/listo) o toda la comanda; recarga al terminar. Sin PIN: no es acción sensible. */
async function avanzar(itemUlid, to) {
    await enviar(() => api.post(`/kds/items/${itemUlid}/advance`, { to }));
}

async function todoListo(ticketUlid) {
    await enviar(() => api.post(`/kds/tickets/${ticketUlid}/ready`));
}

async function enviar(accion) {
    if (enviando.value) {
        return;
    }

    enviando.value = true;

    try {
        await accion();
        refrescarYMarcar();
    } catch (e) {
        pushToast(e instanceof ApiError ? e.title : 'No se pudo marcar la comanda.', 'error');
    } finally {
        enviando.value = false;
    }
}

function hora(iso) {
    return formatInBranchTime(iso, activeBranch.value?.timezone) || '—';
}

const leyenda = computed(() => ({
    socket: 'Al instante',
    polling: 'Cada 10 segundos',
    idle: 'Cargando…',
}[source.value]));
</script>

<template>
    <Head title="Comandas" />

    <div class="comandas">
        <ListHeader
            title="Comandas"
            subtitle="El tablero de cocina en vivo: lo que está en curso en cada área. Toca para marcar preparando o listo."
        />

        <header class="comandas__cabecera">
            <p class="comandas__estado">
                <span class="punto" :class="`punto--${source}`" />
                {{ leyenda }}
                <span v-if="lastRefreshAt" class="comandas__hora">· {{ hora(lastRefreshAt.toISOString()) }}</span>
            </p>

            <button type="button" class="enlace" @click="refrescarYMarcar()">Actualizar ahora</button>
        </header>

        <nav v-if="areas.length > 1" class="areas">
            <button
                v-for="a in areas"
                :key="a.ulid"
                type="button"
                :class="['areas__boton', { 'areas__boton--activa': a.ulid === areaUlid }]"
                @click="cambiarArea(a.ulid)"
            >
                {{ a.name }}
            </button>
        </nav>

        <template v-if="loading"></template>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <p v-else-if="areas.length === 0" class="nota">
            Ninguna área tiene el tablero de cocina activado. Actívalo en el área de preparación (usa KDS).
        </p>

        <p v-else-if="comandas.length === 0" class="nota">
            Nada en curso en esta área.
        </p>

        <ul v-else class="tarjetas">
            <li v-for="c in comandas" :key="c.ulid" class="tarjeta" :class="`tarjeta--${nivelEspera(c.issued_at)}`">
                <header class="tarjeta__cabecera">
                    <strong>{{ c.account?.display_name ?? '—' }}</strong>
                    <span class="tarjeta__espera">{{ esperaMin(c.issued_at) }} min</span>
                </header>

                <p class="tarjeta__meta">
                    {{ c.series }}{{ c.folio }} · {{ hora(c.issued_at) }}
                    <span v-if="c.reprint_count > 0" class="tarjeta__reimpresa">
                        · reimpresa {{ c.reprint_count }} {{ c.reprint_count === 1 ? 'vez' : 'veces' }}
                    </span>
                </p>

                <ul class="lineas">
                    <li v-for="linea in (c.items ?? [])" :key="linea.ulid" :class="{ 'linea--prep': linea.status === 'preparing' }">
                        <div class="linea__datos">
                            <span class="linea__nombre"><strong>{{ Number(linea.quantity) }}</strong> {{ linea.article_name }}</span>
                            <span v-if="(linea.modifiers ?? []).length" class="linea__mods">{{ linea.modifiers.join(' · ') }}</span>
                        </div>
                        <div class="linea__acciones">
                            <button
                                v-if="linea.status === 'commanded'"
                                type="button"
                                class="bump bump--prep"
                                :disabled="enviando"
                                @click="avanzar(linea.ulid, 'preparing')"
                            >Preparando</button>
                            <button
                                type="button"
                                class="bump bump--listo"
                                :disabled="enviando"
                                @click="avanzar(linea.ulid, 'served')"
                            ><Icon name="check" :size="15" /> Listo</button>
                        </div>
                    </li>
                </ul>

                <button type="button" class="todo-listo" :disabled="enviando" @click="todoListo(c.ulid)">
                    <Icon name="check" :size="16" /> Toda la comanda lista
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.comandas { display: grid; gap: 0.75rem; }
.comandas__cabecera { display: flex; gap: 1.25rem; align-items: baseline; flex-wrap: wrap; }
.comandas__estado { margin: 0; font-size: 0.85rem; color: var(--color-suave); display: flex; gap: 0.4rem; align-items: center; }
.comandas__hora { color: var(--color-suave); opacity: 0.8; }
.punto { width: 0.55rem; height: 0.55rem; border-radius: 50%; display: inline-block; background: var(--color-suave); }
.punto--socket { background: var(--color-exito); }
.punto--polling { background: var(--color-aviso); }

/* Segmentos de área (cocina / barra): pastillas; la activa se rellena con el acento del negocio. */
.areas { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.areas__boton {
    font: inherit;
    font-size: 0.85rem;
    border: 1px solid var(--color-borde);
    background: var(--color-superficie);
    color: var(--color-contenido);
    border-radius: 999px;
    padding: 0.35rem 0.95rem;
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.areas__boton:hover:not(.areas__boton--activa) { border-color: color-mix(in srgb, var(--color-acento) 45%, transparent); }
.areas__boton--activa { background: var(--color-acento); color: var(--color-acento-texto); border-color: var(--color-acento); }

.tarjetas { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); gap: 0.75rem; }
.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-left: 4px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 0.85rem 1rem;
    display: grid;
    gap: 0.5rem;
}
/* El borde izquierdo dice de un vistazo cuánto lleva esperando. */
.tarjeta--fresca { border-left-color: var(--color-exito); }
.tarjeta--media { border-left-color: var(--color-aviso); }
.tarjeta--tarde { border-left-color: var(--color-peligro); }

.tarjeta__cabecera { display: flex; justify-content: space-between; gap: 0.5rem; align-items: baseline; }
.tarjeta__cabecera strong { font-size: 1.02rem; }
.tarjeta__espera { color: var(--color-suave); font-size: 0.85rem; font-variant-numeric: tabular-nums; }
.tarjeta--tarde .tarjeta__espera { color: var(--color-peligro); font-weight: 600; }
.tarjeta__meta { margin: 0; color: var(--color-suave); font-size: 0.8rem; }
.tarjeta__reimpresa { color: var(--color-peligro); }

.lineas { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.lineas li { display: flex; justify-content: space-between; gap: 0.6rem; align-items: center; }
.linea--prep .linea__nombre { color: var(--color-aviso); }
.linea__datos { min-width: 0; display: grid; gap: 0.1rem; }
.linea__nombre { font-size: 0.95rem; }
.linea__mods { font-size: 0.78rem; color: var(--color-suave); }
.linea__acciones { display: flex; gap: 0.35rem; flex: none; }

.bump {
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.4rem 0.6rem;
    border-radius: 0.5rem;
    border: 1px solid var(--color-borde);
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.bump:disabled { opacity: 0.55; cursor: not-allowed; }
.bump--prep:hover:not(:disabled) { border-color: var(--color-aviso); color: var(--color-aviso); }
.bump--listo { border-color: color-mix(in srgb, var(--color-exito) 45%, transparent); color: var(--color-exito); }
.bump--listo:hover:not(:disabled) { background: color-mix(in srgb, var(--color-exito) 12%, transparent); }

.todo-listo {
    justify-self: stretch;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    padding: 0.55rem;
    border: 0;
    border-radius: 0.55rem;
    background: var(--color-exito);
    color: #fff;
    cursor: pointer;
    transition: filter 0.15s ease;
}
.todo-listo:hover:not(:disabled) { filter: brightness(1.05); }
.todo-listo:disabled { opacity: 0.55; cursor: not-allowed; }

.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }

.enlace {
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 0.3rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-acento);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.enlace:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
</style>
