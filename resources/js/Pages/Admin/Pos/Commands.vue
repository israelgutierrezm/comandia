<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
import { useLiveRefresh } from '../../../composables/useLiveRefresh';
import { suscribir } from '../../../support/echo';

/**
 * Comandas por área: la pantalla de la cocina (§6.3, §6.9).
 *
 * ## Es un espejo del papel, no su sustituto
 *
 * El trabajo de impresión se sigue generando igual. Esto existe para las cocinas que prefieren monitor, y para ver lo
 * que está en curso sin rebuscar entre tickets — no para reemplazar un mecanismo que funciona sin red. Si el monitor
 * se apaga, la comanda sigue saliendo por la impresora.
 *
 * ## Una sola área a la vez
 *
 * La cocina y la barra son dos puestos de trabajo distintos, con dos personas distintas. Una pantalla que mezclara
 * ambas obligaría a cada una a filtrar con la vista lo que no le toca, que en hora pico es exactamente cuando no se
 * puede.
 *
 * ## Sin dinero, igual que el papel
 *
 * Ni precios ni total. La comanda impresa tampoco los lleva: a quien cocina no le sirven, y a quien pasa por la cocina
 * no le incumben.
 */
const page = usePage();

const areas = ref([]);
const areaUlid = ref(null);
const comandas = ref([]);
const loading = ref(true);
const loadError = ref(null);

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
            areas.value = (await api.get('/preparation-areas', {
                branch: activeBranch.value.ulid,
                status: 'active',
                per_page: 20,
            })).data;

            areaUlid.value ??= areas.value[0]?.ulid ?? null;
        }

        if (! areaUlid.value) {
            comandas.value = [];
            loadError.value = null;

            return;
        }

        comandas.value = (await api.get('/pos-tickets', {
            kind: 'command',
            area: areaUlid.value,
            branch: activeBranch.value.ulid,
            per_page: 30,
            sort: '-issued_at',

            // SÓLO LO DE HOY.
            //
            // Sin el corte, la pantalla arrastraba la comanda de ayer entre las de ahora — y en una cocina eso no es
            // ruido inofensivo: es un platillo que alguien puede preparar de más. No hay estado «preparado» en el
            // ticket, así que la jornada es el mejor límite honesto que existe hoy; marcar comandas es una función
            // que no está diseñada, y fingirla con un filtro sería peor.
            issued_from: hoy(),
        })).data;

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
 * La suscripción sigue al área elegida.
 *
 * Cambiar de cocina a barra tiene que dar de baja la anterior: sin eso, la pantalla acabaría oyendo las dos y
 * pintando comandas que no son de este puesto — que es peor que no pintar ninguna, porque parecen suyas.
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
        { 'area.order-commanded': () => refrescarYMarcar() },
        { onConectado: socketConectado, onCaido: socketCaido },
    );
}, { immediate: true });

onBeforeUnmount(() => darDeBaja());

function cambiarArea(ulid) {
    areaUlid.value = ulid;
    refrescarYMarcar();
}

/** La fecha de hoy en la zona de la SUCURSAL, que es la que define la jornada del negocio. */
function hoy() {
    const partes = new Intl.DateTimeFormat('en-CA', {
        timeZone: activeBranch.value?.timezone ?? undefined,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());

    return partes;
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
        <header class="comandas__cabecera">
            <h1>Comandas</h1>

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

        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <p v-else-if="comandas.length === 0" class="nota">
            Nada en curso en esta área.
        </p>

        <ul v-else class="tarjetas">
            <li v-for="c in comandas" :key="c.ulid" class="tarjeta">
                <header class="tarjeta__cabecera">
                    <strong>{{ c.account?.display_name ?? '—' }}</strong>
                    <span class="tarjeta__hora">{{ hora(c.issued_at) }}</span>
                </header>

                <p class="tarjeta__orden">
                    Orden {{ c.order_sequence ?? '—' }}
                    <!-- Una comanda que salió dos veces es comida preparada dos veces si nadie se da cuenta. -->
                    <span v-if="c.reprint_count > 0" class="tarjeta__reimpresa">
                        · reimpresa {{ c.reprint_count }}
                        {{ c.reprint_count === 1 ? 'vez' : 'veces' }}
                    </span>
                </p>

                <ul class="lineas">
                    <li v-for="(linea, i) in (c.items ?? [])" :key="i">
                        <strong>{{ Number(linea.quantity) }}</strong> {{ linea.article_name }}
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.comandas { display: grid; gap: 0.75rem; }
.comandas__cabecera { display: flex; gap: 1.25rem; align-items: baseline; flex-wrap: wrap; }
.comandas__cabecera h1 { margin: 0; }
.comandas__estado { margin: 0; font-size: 0.85rem; color: #555; display: flex; gap: 0.4rem; align-items: center; }
.comandas__hora { color: #888; }
.punto { width: 0.55rem; height: 0.55rem; border-radius: 50%; display: inline-block; background: #bbb; }
.punto--socket { background: #43a047; }
.punto--polling { background: #fb8c00; }
.areas { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.areas__boton { border: 1px solid #d6d6d6; background: #fff; border-radius: 999px; padding: 0.3rem 0.9rem; cursor: pointer; }
.areas__boton--activa { background: #333; color: #fff; border-color: #333; }
.tarjetas { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: 0.75rem; }
.tarjeta { border: 1px solid #d6d6d6; border-radius: 6px; padding: 0.75rem 1rem; background: #fff; }
.tarjeta__cabecera { display: flex; justify-content: space-between; gap: 0.5rem; align-items: baseline; }
.tarjeta__hora { color: #888; font-size: 0.85rem; }
.tarjeta__orden { margin: 0.2rem 0 0.5rem; color: #666; font-size: 0.85rem; }
.tarjeta__reimpresa { color: #a11; }
.lineas { margin: 0; padding-left: 1.1rem; }
.lineas li { margin: 0.15rem 0; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; font-size: 0.85rem; padding: 0; }
</style>
