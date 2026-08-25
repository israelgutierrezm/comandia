<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
import { useLiveRefresh } from '../../../composables/useLiveRefresh';
import { suscribir } from '../../../support/echo';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';

/**
 * El piso de venta: el salón con lo que está pasando encima (§6.4).
 *
 * ## El mismo dibujo que el editor, en sólo lectura
 *
 * ADR-003 lo pide literalmente. Dos renders divergirían y el error sería invisible hasta que alguien se sentara en la
 * mesa equivocada.
 *
 * ## Una sola petición, y por eso puede sondearse
 *
 * `GET /branches/{branch}/floor` trae plano, zonas, mesas y cuentas. Con cuatro llamadas la pantalla pintaría el salón
 * vacío y le iría cayendo el estado encima; en una pantalla que se mira de reojo mientras se cargan platos, eso se lee
 * como que el sistema perdió las cuentas.
 *
 * ## Sin importes, a propósito
 *
 * El permiso de esta pantalla lo tiene todo el que atiende y el de ver dinero es otro. Lo que se ve desde lejos es el
 * color del estado, cuántos artículos lleva la mesa y desde cuándo está ocupada — que es lo que decide a quién ir a
 * atender. El importe está a un clic, en la cuenta, donde sí se comprueba el permiso.
 */
const page = usePage();

const piso = ref(null);
const loading = ref(true);
const loadError = ref(null);
const selected = ref(null);

const activeBranch = computed(() => {
    const contexto = page.props.context;

    return contexto?.branch_ulid
        ? { ulid: contexto.branch_ulid, name: contexto.branch_name, timezone: contexto.branch_timezone }
        : null;
});

async function cargar() {
    if (! activeBranch.value) {
        loading.value = false;
        loadError.value = { title: 'Elige una sucursal para ver su piso.' };

        return;
    }

    try {
        piso.value = (await api.get(`/branches/${activeBranch.value.ulid}/floor`)).data;
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

/**
 * El socket, si lo hay.
 *
 * La pantalla NO depende de él: con socket refresca al recibir, sin socket sondea cada diez segundos, y la transición
 * la lleva el composable. En desarrollo, sin `queue:work`, la difusión se queda en la cola y esto no recibe nada — por
 * eso el respaldo no es opcional (§6.9).
 */
let darDeBaja = () => {};

onMounted(() => {
    const tenant = page.props.context?.tenant?.ulid;
    const branch = activeBranch.value?.ulid;

    if (! tenant || ! branch) {
        return;
    }

    darDeBaja = suscribir(
        `tenant.${tenant}.branch.${branch}.floor`,
        {
            // Un solo tipo de evento: «algo cambió aquí». La pantalla se pinta con UNA petición, así que lo que
            // necesita del canal es el aviso, no el detalle.
            'floor.changed': () => refrescarYMarcar(),
        },
        { onConectado: socketConectado, onCaido: socketCaido },
    );
});

// Sin esto, salir de la pantalla dejaría la suscripción viva y la siguiente visita abriría otra encima. En una
// terminal que lleva días abierta eso se acumula hasta que el servidor rechaza suscripciones sin que nadie sepa por qué.
onBeforeUnmount(() => darDeBaja());

const mesas = computed(() => piso.value?.tables ?? []);

const ocupadas = computed(() => mesas.value.filter((m) => m.account !== null));

/** Abrir una mesa lleva a su cuenta; una libre, a abrir una nueva. */
function activar(mesa) {
    if (mesa.account) {
        router.visit(`/admin/pos/cuentas/${mesa.account.ulid}`);

        return;
    }

    router.visit('/admin/pos/cuentas');
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
    <Head title="Piso" />

    <div class="piso">
        <header class="piso__cabecera">
            <h1>Piso</h1>

            <p class="piso__estado">
                <!-- Quien opera un salón lleno merece saber si lo que ve llega solo o se pide cada diez segundos. -->
                <span class="punto" :class="`punto--${source}`" />
                {{ leyenda }}
                <span v-if="lastRefreshAt" class="piso__hora">· {{ hora(lastRefreshAt.toISOString()) }}</span>
            </p>

            <button type="button" class="enlace" @click="refrescarYMarcar()">Actualizar ahora</button>
        </header>

        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <template v-else-if="piso">
            <p class="resumen">
                {{ piso.plan.name }} · {{ mesas.length }} mesas · <strong>{{ ocupadas.length }}</strong> con servicio
            </p>

            <FloorCanvas
                :canvas="piso.plan.canvas"
                :tables="mesas"
                :selected="selected"
                readonly
                @select="selected = $event"
                @activate="activar"
            />

            <section v-if="selected" class="panel">
                <template v-for="mesa in mesas.filter((m) => m.ulid === selected)" :key="mesa.ulid">
                    <h2>{{ mesa.code }} <small>{{ mesa.status_label }}</small></h2>

                    <p v-if="!mesa.account" class="nota">
                        Sin servicio. {{ mesa.effective_seats }} lugares.
                    </p>

                    <template v-else>
                        <p>
                            <strong>{{ mesa.account.display_name }}</strong> · {{ mesa.account.folio }} ·
                            {{ mesa.account.items_count }} artículos · desde {{ hora(mesa.account.opened_at) }}
                        </p>

                        <p v-if="mesa.account.bill_requested_at" class="aviso">
                            Pidió la cuenta a las {{ hora(mesa.account.bill_requested_at) }}.
                        </p>

                        <Link :href="`/admin/pos/cuentas/${mesa.account.ulid}`" class="abrir-cuenta">Abrir la cuenta</Link>
                    </template>
                </template>
            </section>

            <p class="nota">
                Toca una mesa para verla; doble toque para abrir su cuenta.
            </p>
        </template>
    </div>
</template>

<style scoped>
.piso { display: grid; gap: 0.75rem; }
.piso__cabecera { display: flex; gap: 1.25rem; align-items: baseline; flex-wrap: wrap; }
.piso__cabecera h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }
.piso__estado { margin: 0; font-size: 0.85rem; color: var(--color-suave); display: flex; gap: 0.4rem; align-items: center; }
.piso__hora { color: var(--color-suave); opacity: 0.8; }
.punto { width: 0.55rem; height: 0.55rem; border-radius: 50%; display: inline-block; background: var(--color-suave); }
.punto--socket { background: var(--color-exito); }
.punto--polling { background: var(--color-aviso); }
.resumen { margin: 0; color: var(--color-suave); font-size: 0.9rem; }

.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 0.9rem 1.1rem;
}
.panel h2 { margin: 0 0 0.4rem; font-size: 1.05rem; font-weight: 650; }
.panel h2 small { font-weight: 400; color: var(--color-suave); font-size: 0.8rem; }
.aviso { color: var(--color-aviso); }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }

/* «Actualizar ahora» y «Abrir la cuenta»: acciones con borde, no texto azul suelto. */
.enlace,
.abrir-cuenta {
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--color-acento);
    cursor: pointer;
    text-decoration: none;
    transition: background-color 0.15s ease;
}
.enlace:hover,
.abrir-cuenta:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
</style>
