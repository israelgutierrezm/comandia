<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
import { useAuthorization } from '../../../composables/useAuthorization';
import { useLiveRefresh } from '../../../composables/useLiveRefresh';
import { suscribir } from '../../../support/echo';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';
import TableActions from '../../../components/floor/TableActions.vue';
import Icon from '../../../components/Icon.vue';
import ListHeader from '../../../components/ListHeader.vue';

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
 *
 * ## Una sucursal sin salón no es un error
 *
 * Sin plano, el piso responde 404. Una fonda para llevar o una cafetería de mostrador nunca lo tendrán, y la terminal
 * compartida manda aquí al operador en cuanto teclea su PIN: pintar ese 404 en rojo dejaba al operador frente a un error
 * sin salida. La pantalla lo explica, ofrece ir a las cuentas y —a quien puede configurarlo— diseñar el salón.
 *
 * ## Las operaciones de mesa, sin tocar el gesto principal
 *
 * Un toque sigue seleccionando la mesa y el doble toque sigue abriendo su cuenta. Liberar, unir y separar van en el
 * panel de la seleccionada (`TableActions`), con el permiso de piso `floor.tables.join`, y al terminar se vuelve a pedir
 * el piso.
 */
const page = usePage();
const { can } = useAuthorization();

const piso = ref(null);
const loading = ref(true);
const loadError = ref(null);
const selected = ref(null);

/** La sucursal no tiene plano de salón (el piso respondió 404): un estado de la sucursal, no una falla. */
const sinSalon = ref(false);

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
        sinSalon.value = false;
        loadError.value = null;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // Se distingue por el ESTADO y no por el texto: el servidor responde todo 404 con el mismo mensaje genérico.
        if (e.status === 404) {
            piso.value = null;
            sinSalon.value = true;
            loadError.value = null;
        } else {
            loadError.value = e;
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

const mesaSeleccionada = computed(() => mesas.value.find((m) => m.ulid === selected.value) ?? null);

/** La principal de la unión de la que cuelga esta mesa, si está unida a otra (D32). */
function principalDe(mesa) {
    return mesa.joined_to ? mesas.value.find((m) => m.ulid === mesa.joined_to) ?? null : null;
}

/**
 * La línea que explica la unión de la mesa, o `null` si está suelta. Sin ella, una mesa unida diría «Libre» encima de la
 * cuenta de su principal, y parecería que el piso se contradice.
 */
function unionDe(mesa) {
    if (mesa.joined_to) {
        return `Unida a la mesa ${principalDe(mesa)?.code ?? 'principal de su grupo'}.`;
    }

    const unidas = mesas.value.filter((m) => m.joined_to === mesa.ulid);

    return unidas.length ? `Unidas a ésta: ${unidas.map((m) => m.code).join(', ')}.` : null;
}

/**
 * La cuenta con que se atiende la mesa: la suya o, si está unida a otra, la de su principal. Una unión es un conjunto que
 * atiende UNA sola cuenta, y esa cuenta vive en la principal (D32).
 */
function cuentaDe(mesa) {
    return mesa.account ?? principalDe(mesa)?.account ?? null;
}

const cuentaSeleccionada = computed(() => (mesaSeleccionada.value ? cuentaDe(mesaSeleccionada.value) : null));

/** Abrir una mesa lleva a la cuenta con que se atiende; una sin servicio, a la lista para abrir una nueva. */
function activar(mesa) {
    const cuenta = cuentaDe(mesa);

    router.visit(cuenta ? `/admin/pos/cuentas/${cuenta.ulid}` : '/admin/pos/cuentas');
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
        <ListHeader
            title="Piso"
            subtitle="El salón en vivo: qué mesas están libres, ocupadas o en precuenta, y a cuáles atender."
        />

        <header class="piso__cabecera">
            <p class="piso__estado">
                <!-- Quien opera un salón lleno merece saber si lo que ve llega solo o se pide cada diez segundos. -->
                <span class="punto" :class="`punto--${source}`" />
                {{ leyenda }}
                <span v-if="lastRefreshAt" class="piso__hora">· {{ hora(lastRefreshAt.toISOString()) }}</span>
            </p>

            <button type="button" class="link-button" @click="refrescarYMarcar()">Actualizar ahora</button>
        </header>

        <template v-if="loading"></template>
        <div v-else-if="loadError" class="alert" role="alert">{{ loadError.title }}</div>

        <!-- Sin salón: un estado de la sucursal, no una falla. Quien llega aquí desde la terminal compartida necesita una
             salida —la lista de cuentas—, no un aviso en rojo. -->
        <section v-else-if="sinSalon" class="panel vacio">
            <h2>Esta sucursal no tiene salón</h2>

            <p>
                El piso dibuja las mesas del plano de salón, y esta sucursal todavía no tiene uno. Si aquí no se atiende en
                mesa —para llevar, mostrador o barra—, no hace falta: las cuentas se abren desde la lista.
            </p>

            <p v-if="! can('floor.layouts.edit')" class="nota">
                Si aquí sí se atiende en mesas, pide a un gerente que diseñe el salón.
            </p>

            <div class="vacio__acciones">
                <Link href="/admin/pos/cuentas" class="button"><Icon name="receipt" :size="15" /> Ir a las cuentas</Link>
                <Link v-if="can('floor.layouts.edit')" href="/admin/piso/editor" class="button button--neutral">
                    <Icon name="grid" :size="15" /> Diseñar el salón
                </Link>
            </div>
        </section>

        <template v-else-if="piso">
            <p class="resumen">
                {{ piso.plan.name }} · {{ mesas.length }} mesas · <strong>{{ ocupadas.length }}</strong> con servicio
            </p>

            <ul class="leyenda-colores" aria-label="Colores por estado">
                <li><span class="lc lc--libre"></span> Libre</li>
                <li><span class="lc lc--ocupada"></span> Ocupada</li>
                <li><span class="lc lc--precuenta"></span> Precuenta</li>
            </ul>

            <FloorCanvas
                :canvas="piso.plan.canvas"
                :tables="mesas"
                :elements="piso.elements ?? []"
                :selected="selected"
                readonly
                @select="selected = $event"
                @activate="activar"
            />

            <section v-if="mesaSeleccionada" class="panel">
                <h2>{{ mesaSeleccionada.code }} <small>{{ mesaSeleccionada.status_label }}</small></h2>

                <p v-if="unionDe(mesaSeleccionada)" class="nota">{{ unionDe(mesaSeleccionada) }}</p>

                <p v-if="! cuentaSeleccionada" class="nota">
                    Sin servicio. {{ mesaSeleccionada.effective_seats }} lugares.
                </p>

                <template v-else>
                    <p>
                        <strong>{{ cuentaSeleccionada.display_name }}</strong> · {{ cuentaSeleccionada.folio }} ·
                        {{ cuentaSeleccionada.items_count }} artículos · desde {{ hora(cuentaSeleccionada.opened_at) }}
                    </p>

                    <p v-if="cuentaSeleccionada.bill_requested_at" class="aviso">
                        Pidió la cuenta a las {{ hora(cuentaSeleccionada.bill_requested_at) }}.
                    </p>

                    <Link :href="`/admin/pos/cuentas/${cuentaSeleccionada.ulid}`" class="link-button">Abrir la cuenta</Link>
                </template>

                <!-- `key`: cada mesa arranca sin confirmaciones abiertas ni mesas marcadas de la anterior. -->
                <TableActions
                    :key="mesaSeleccionada.ulid"
                    :mesa="mesaSeleccionada"
                    :mesas="mesas"
                    @changed="refrescarYMarcar()"
                />
            </section>

            <p class="nota">
                Toca una mesa para verla; doble toque para abrir su cuenta.
            </p>
        </template>
    </div>
</template>

<style scoped>
/* «Actualizar ahora» y «Abrir la cuenta» son acciones con borde, no texto azul suelto: el `.link-button` compartido, en
   lugar de una copia local que se iba separando del resto del admin. */
@import '../../../../css/admin-page.css';

.piso { display: grid; gap: 0.75rem; }

.leyenda-colores { display: flex; flex-wrap: wrap; gap: 1rem; margin: 0; padding: 0; list-style: none; font-size: 0.82rem; color: var(--color-suave); }
.leyenda-colores li { display: flex; align-items: center; gap: 0.4rem; }
.lc { width: 0.85rem; height: 0.85rem; border-radius: var(--radio-sm); border: 1.5px solid; }
.lc--libre { background: #e8f5e9; border-color: #43a047; }
.lc--ocupada { background: #ffebee; border-color: #e53935; }
.lc--precuenta { background: #fff8e1; border-color: #f9a825; }
.piso__cabecera { display: flex; gap: 1.25rem; align-items: baseline; flex-wrap: wrap; }
.piso__estado { margin: 0; font-size: 0.85rem; color: var(--color-suave); display: flex; gap: 0.4rem; align-items: center; }
.piso__hora { color: var(--color-suave); opacity: 0.8; }
.punto { width: 0.55rem; height: 0.55rem; border-radius: 50%; display: inline-block; background: var(--color-suave); }
.punto--socket { background: var(--color-exito); }
.punto--polling { background: var(--color-aviso); }
.resumen { margin: 0; color: var(--color-suave); font-size: 0.9rem; }

.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 0.9rem 1.1rem;
}
.panel h2 { margin: 0 0 0.4rem; font-size: 1.05rem; font-weight: 650; }
.panel h2 small { font-weight: 400; color: var(--color-suave); font-size: 0.8rem; }
.aviso { color: var(--color-aviso); }
.nota { color: var(--color-suave); font-size: 0.9rem; }

.vacio { display: grid; gap: 0.6rem; }
.vacio p { margin: 0; line-height: 1.5; }
.vacio__acciones { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.25rem; }
/* Táctil: es la salida del operador que llega desde la terminal compartida, y se toca con el dedo. */
.vacio__acciones .button { min-height: 2.75rem; }
</style>
