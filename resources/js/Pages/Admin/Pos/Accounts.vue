<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { formatInBranchTime } from '../../../support/datetime';
// El formato de dinero compartido por todo el POS: el mismo importe se lee igual aquí, en la cuenta y en la caja.
import { formatMoney as money } from '../../../support/money';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';
import Icon from '../../../components/Icon.vue';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * Las cuentas, desde el PLANO del salón (§6.3, rediseño).
 *
 * ## El plano manda; la lista acompaña
 *
 * Quien atiende piensa en mesas, no en folios: el salón dibujado —con el color diciendo en qué va cada cuenta— es la
 * forma natural de operar. Se reutiliza el MISMO dibujo del editor y del piso (ADR-003, `FloorCanvas`), aquí coloreado
 * por estado de CUENTA. Abajo, la lista de cuentas activas para buscar por folio o ver de un vistazo los importes.
 *
 * ## Dos peticiones que se fusionan, y por qué
 *
 * El piso (`/branches/{sucursal}/floor`) trae la geometría y el estado de cada mesa, pero NO importes —su permiso es más
 * amplio—. Los importes y el mesero vienen de la lista de cuentas (`/pos-accounts`), cuyo permiso es el de operar. Esta
 * pantalla tiene ese permiso, así que junta las dos: el plano con color y, encima de cada mesa, el total.
 *
 * ## Abrir es un solo gesto
 *
 * Tocar una mesa libre abre su cuenta y entra a capturar; tocar una ocupada la selecciona para cobrar, pedir la cuenta o
 * ver el consumo. De barra y para llevar no tienen mesa: su alta es un formulario aparte.
 *
 * ## Sin salón también se opera
 *
 * Una fonda para llevar o una cafetería de mostrador nunca dibujarán un plano, y para ellas el piso responde 404. Eso no
 * es un error de la pantalla: se oculta «En mesa» y se opera de barra y para llevar. El piso iba en el mismo
 * `Promise.all` que las cuentas, y su 404 tumbaba la lista entera —también en la terminal compartida—.
 */
const page = usePage();

const piso = ref(null);

/** La sucursal no tiene plano de salón (el piso respondió 404): un estado de la sucursal, no una falla. */
const sinSalon = ref(false);

/** Cualquier otro fallo del piso (sin permiso de verlo, servidor caído): se pinta donde irían las mesas. */
const pisoError = ref(null);

const accounts = ref([]);
const branches = ref([]);
const loading = ref(true);
const loadError = ref(null);
const onlyOpen = ref(true);

const modo = ref('table'); // 'table' | 'walkin' | 'takeout'
const vista = ref('plano'); // 'plano' | 'lista'  (sólo en modo mesa)
const zonaActiva = ref(null); // ulid de zona; null = todas
const soloMisMesas = ref(false);
const seleccion = ref(null); // ulid de la mesa seleccionada

const form = ref({ label: '', branch_ulid: '' });

const activeBranchUlid = computed(() => page.props.context?.branch_ulid ?? null);
const miMembresiaUlid = computed(() => page.props.context?.membership?.ulid ?? null);

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    if (! activeBranchUlid.value) {
        loading.value = false;
        loadError.value = { title: 'Elige una sucursal para ver sus cuentas.' };

        return;
    }

    try {
        const [plano, cuentas, contexto] = await Promise.all([
            cargarPiso(),
            api.get('/pos-accounts', { only_open: onlyOpen.value ? 1 : 0, per_page: 100, branch: activeBranchUlid.value }),
            // Las sucursales donde ESTA persona opera salen de su contexto, que todo rol puede leer. Antes se pedían a
            // `/branches` —la lista de administración—, que un Mesero o un Cajero no ven: la pantalla entera fallaba.
            api.get('/context'),
        ]);

        piso.value = plano;
        accounts.value = cuentas.data;
        branches.value = contexto.data.branches ?? [];

        // Sin salón no hay «En mesa»: se arranca en «Para llevar», el alta de un toque —el número de mostrador lo pone
        // el sistema—, que es como opera una fonda para llevar o una cafetería de mostrador.
        if (sinSalon.value && modo.value === 'table') {
            modo.value = 'takeout';
        }

        if (! form.value.branch_ulid) {
            form.value.branch_ulid = activeBranchUlid.value ?? branches.value[0]?.ulid ?? '';
        }
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

/**
 * El plano de la sucursal activa, o `null` cuando no se puede dibujar.
 *
 * Nunca rechaza por un error de la API: ni el 404 de «sin salón» ni otro fallo del piso deben tumbar la lista de cuentas
 * ni las altas de barra y para llevar, que no lo necesitan. El 404 se distingue por el ESTADO y no por el texto: el
 * servidor responde todo 404 con el mismo mensaje genérico.
 */
async function cargarPiso() {
    try {
        const respuesta = await api.get(`/branches/${activeBranchUlid.value}/floor`);

        sinSalon.value = false;
        pisoError.value = null;

        return respuesta.data;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        sinSalon.value = e.status === 404;
        pisoError.value = sinSalon.value ? null : e;

        return null;
    }
}

/** El panel de la mesa seleccionada, sólo si hay mesas que seleccionar. */
const conPanelDeMesa = computed(() => ! sinSalon.value && ! pisoError.value);

/** La cuenta de la lista (con importes y mesero) por su ULID, para casarla con la mesa del plano. */
const cuentaPorUlid = computed(() => {
    const mapa = new Map();
    for (const c of accounts.value) {
        mapa.set(c.ulid, c);
    }
    return mapa;
});

/**
 * Las mesas del plano con lo que aporta la lista de cuentas pegado encima: el total (ya formateado) para que
 * `FloorCanvas` lo pinte, y la cuenta completa para el panel de la derecha.
 */
const mesasPlano = computed(() => (piso.value?.tables ?? []).map((mesa) => {
    if (! mesa.account) {
        return mesa;
    }

    const detalle = cuentaPorUlid.value.get(mesa.account.ulid) ?? null;

    return {
        ...mesa,
        account: {
            ...mesa.account,
            total_label: detalle ? money(detalle.totals?.total) : null,
            due: detalle?.totals?.due ?? null,
            waiter: detalle?.waiter ?? null,
            status: detalle?.status ?? null,
            status_label: detalle?.status_label ?? null,
        },
        _detalle: detalle,
    };
}));

const zonas = computed(() => piso.value?.plan?.zones ?? []);

/** Las mesas que se pintan, según la zona y el filtro «mis mesas». */
const mesasFiltradas = computed(() => mesasPlano.value.filter((mesa) => {
    if (zonaActiva.value && mesa.zone_ulid !== zonaActiva.value) {
        return false;
    }

    if (soloMisMesas.value) {
        return mesa.account?.waiter?.ulid && mesa.account.waiter.ulid === miMembresiaUlid.value;
    }

    return true;
}));

const mesaSeleccionada = computed(() => mesasPlano.value.find((m) => m.ulid === seleccion.value) ?? null);

const opening = useApiForm(async (cuerpo) => (await api.post('/pos-accounts', cuerpo)).data);

async function open(cuerpo) {
    const cuenta = await opening.submit(cuerpo);

    if (cuenta) {
        router.visit(accountUrl(cuenta));
    }
}

const openTable = (mesa) => open({ table_ulid: mesa.ulid });
const openTakeout = () => open({ branch_ulid: form.value.branch_ulid, takeout: true });
const openWalkin = () => open({ branch_ulid: form.value.branch_ulid, label: form.value.label });

/**
 * La URL de una cuenta, con la ACCIÓN opcional con la que abrirla: la pantalla de la cuenta la lee para arrancar en la
 * vista correcta (cobrar, precuenta) en vez de siempre en «orden». Sin acción, abre en su vista por defecto.
 */
function accountUrl(account, accion = null) {
    return accion
        ? `/admin/pos/cuentas/${account.ulid}?accion=${accion}`
        : `/admin/pos/cuentas/${account.ulid}`;
}

/** Tocar una mesa la selecciona (para abrir si está libre, o para operar si tiene cuenta). `FloorCanvas` emite el ULID. */
function tocarMesa(ulid) {
    seleccion.value = ulid;
}

/** Doble toque / abrir directo. */
function activarMesa(mesa) {
    if (mesa.account) {
        router.visit(accountUrl(mesa.account));

        return;
    }

    openTable(mesa);
}

/**
 * La hora de la SUCURSAL, no la del navegador: quien mira puede estar en otra zona horaria. Mismo ayudante que Piso,
 * Comandas y Caja; por eso trae también la fecha, que en una cuenta abierta desde ayer es justo lo que importa.
 */
function hora(iso) {
    return formatInBranchTime(iso, page.props.context?.branch_timezone) || '—';
}

const ESTADO_LEYENDA = [
    { key: 'libre', label: 'Libre' },
    { key: 'abierta', label: 'Abierta' },
    { key: 'pendiente', label: 'Pendiente' },
    { key: 'solicitada', label: 'Cuenta solicitada' },
];

/** El estado legible de una cuenta seleccionada (para la etiqueta del panel). */
function estadoTexto(mesa) {
    if (! mesa?.account) return 'Libre';
    if (mesa.account.bill_requested_at) return 'Cuenta solicitada';
    if (Number(mesa.account.pending_to_command ?? 0) > 0) return 'Pendiente por comandar';
    return 'Abierta';
}
</script>

<template>
    <Head title="Cuentas" />

    <ListHeader title="Cuentas" subtitle="Opera las cuentas desde el plano del salón o en vista de lista." />

    <div v-if="loadError" class="alert" role="alert">{{ loadError.title ?? loadError.message }}</div>

    <template v-else>
        <section class="tarjeta operar">
            <!-- Modo de alta (izquierda) + vista del plano (derecha) -->
            <div class="operar__barra">
                <!-- `aria-pressed`: el segmento activo sólo se distinguía por el color, y un lector de pantalla no lo ve.
                     Sin salón, «En mesa» ni se ofrece: no hay mesa que elegir. -->
                <div class="segmento">
                    <button v-if="! sinSalon" type="button" class="seg" :class="{ 'seg--on': modo === 'table' }" :aria-pressed="modo === 'table'" @click="modo = 'table'">En mesa</button>
                    <button type="button" class="seg" :class="{ 'seg--on': modo === 'walkin' }" :aria-pressed="modo === 'walkin'" @click="modo = 'walkin'">De barra</button>
                    <button type="button" class="seg" :class="{ 'seg--on': modo === 'takeout' }" :aria-pressed="modo === 'takeout'" @click="modo = 'takeout'">Para llevar</button>
                </div>

                <div v-if="modo === 'table'" class="segmento">
                    <button type="button" class="seg" :class="{ 'seg--on': vista === 'plano' }" :aria-pressed="vista === 'plano'" @click="vista = 'plano'"><Icon name="grid" :size="15" /> Plano</button>
                    <button type="button" class="seg" :class="{ 'seg--on': vista === 'lista' }" :aria-pressed="vista === 'lista'" @click="vista = 'lista'">Lista</button>
                </div>
            </div>

            <p v-if="sinSalon" class="nota operar__sin-salon">
                Esta sucursal no tiene plano de salón: sus cuentas se abren de barra o para llevar.
            </p>

            <!-- Barra de zonas (sólo en mesa) -->
            <div v-if="modo === 'table' && zonas.length" class="zonas">
                <button
                    type="button"
                    class="zona"
                    :class="{ 'zona--on': zonaActiva === null && ! soloMisMesas }"
                    :aria-pressed="zonaActiva === null && ! soloMisMesas"
                    @click="zonaActiva = null; soloMisMesas = false"
                >Todas</button>
                <button
                    v-for="z in zonas"
                    :key="z.ulid"
                    type="button"
                    class="zona"
                    :class="{ 'zona--on': zonaActiva === z.ulid }"
                    :aria-pressed="zonaActiva === z.ulid"
                    @click="zonaActiva = z.ulid; soloMisMesas = false"
                >
                    {{ z.name }}
                </button>
                <button
                    type="button"
                    class="zona zona--mias"
                    :class="{ 'zona--on': soloMisMesas }"
                    :aria-pressed="soloMisMesas"
                    @click="soloMisMesas = ! soloMisMesas; zonaActiva = null"
                >
                    <Icon name="user" :size="14" /> Mis mesas
                </button>
            </div>

            <div class="operar__cuerpo" :class="{ 'operar__cuerpo--solo': ! conPanelDeMesa }">
                <div class="operar__principal">
                    <!-- EN MESA, sin poder pedir el piso: el motivo, donde irían las mesas. Barra y para llevar siguen. -->
                    <p v-if="modo === 'table' && pisoError" class="alert" role="alert">{{ pisoError.title }}</p>

                    <!-- EN MESA · PLANO -->
                    <template v-else-if="modo === 'table' && vista === 'plano'">
                        <FloorCanvas
                            v-if="piso"
                            :canvas="piso.plan.canvas"
                            :tables="mesasFiltradas"
                            :elements="piso.elements ?? []"
                            :zones="zonas"
                            :selected="seleccion"
                            color-by="cuenta"
                            readonly
                            @select="tocarMesa"
                            @activate="activarMesa"
                        />

                        <ul class="leyenda" aria-label="Colores por estado de la cuenta">
                            <li v-for="e in ESTADO_LEYENDA" :key="e.key"><span class="lc" :class="`lc--${e.key}`" /> {{ e.label }}</li>
                        </ul>
                    </template>

                    <!-- EN MESA · LISTA -->
                    <ul v-else-if="modo === 'table' && vista === 'lista'" class="mesas-lista">
                        <li v-for="mesa in mesasFiltradas" :key="mesa.ulid">
                            <button
                                type="button"
                                class="mesa-fila"
                                :class="{ 'mesa-fila--on': seleccion === mesa.ulid }"
                                @click="tocarMesa(mesa.ulid)"
                            >
                                <span class="mesa-fila__code">{{ mesa.code }}</span>
                                <span class="mesa-fila__estado">{{ estadoTexto(mesa) }}</span>
                                <span class="mesa-fila__total">{{ mesa.account?.total_label ?? '' }}</span>
                            </button>
                        </li>
                    </ul>

                    <!-- DE BARRA -->
                    <form v-else-if="modo === 'walkin'" class="alta-form" @submit.prevent="openWalkin()">
                        <label class="field">
                            <span class="field__label">Nombre</span>
                            <input v-model="form.label" class="input" type="text" placeholder="Señor de lentes" required />
                        </label>
                        <label v-if="branches.length > 1" class="field">
                            <span class="field__label">Sucursal</span>
                            <select v-model="form.branch_ulid" class="input input--select" required>
                                <option v-for="s in branches" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                            </select>
                        </label>
                        <p v-if="opening.generalError.value" class="alert" role="alert">{{ opening.generalError.value }}</p>
                        <button type="submit" class="button" :disabled="opening.processing.value"><Icon name="plus" /> Abrir cuenta de barra</button>
                    </form>

                    <!-- PARA LLEVAR -->
                    <div v-else class="alta-form">
                        <p class="nota">El número de mostrador lo asigna el sistema y vuelve a 1 cada jornada: es el que se grita.</p>
                        <label v-if="branches.length > 1" class="field">
                            <span class="field__label">Sucursal</span>
                            <select v-model="form.branch_ulid" class="input input--select" required>
                                <option v-for="s in branches" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                            </select>
                        </label>
                        <p v-if="opening.generalError.value" class="alert" role="alert">{{ opening.generalError.value }}</p>
                        <button type="button" class="button" :disabled="opening.processing.value" @click="openTakeout()"><Icon name="plus" /> Abrir para llevar</button>
                    </div>
                </div>

                <!-- PANEL: MESA SELECCIONADA -->
                <aside v-if="conPanelDeMesa" class="mesa-panel tarjeta">
                    <h2 class="mesa-panel__titulo">Mesa seleccionada</h2>

                    <p v-if="! mesaSeleccionada" class="nota">Toca una mesa para abrir su cuenta o para cobrarla.</p>

                    <template v-else>
                        <div class="mesa-panel__id">
                            <strong class="mesa-panel__code">{{ mesaSeleccionada.code }}</strong>
                            <span class="etiqueta" :class="`etiqueta--${(mesaSeleccionada.account && mesaSeleccionada.account.bill_requested_at) ? 'solicitada' : (mesaSeleccionada.account ? 'abierta' : 'libre')}`">
                                {{ estadoTexto(mesaSeleccionada) }}
                            </span>
                        </div>

                        <!-- Con cuenta: datos + acciones -->
                        <template v-if="mesaSeleccionada.account">
                            <dl class="mesa-panel__datos">
                                <div><dt>Folio</dt><dd>{{ mesaSeleccionada.account.folio }}</dd></div>
                                <div><dt>Personas</dt><dd>{{ mesaSeleccionada.effective_seats }}</dd></div>
                                <div><dt>Mesero</dt><dd>{{ mesaSeleccionada.account.waiter?.name ?? '—' }}</dd></div>
                            </dl>

                            <div class="mesa-panel__totales">
                                <span>Total</span><strong>{{ mesaSeleccionada.account.total_label ?? '—' }}</strong>
                            </div>
                            <div class="mesa-panel__totales mesa-panel__totales--falta">
                                <span>Falta</span><strong>{{ money(mesaSeleccionada.account.due) }}</strong>
                            </div>

                            <div class="mesa-panel__acciones">
                                <button type="button" class="button button--neutral" @click="router.visit(accountUrl(mesaSeleccionada.account, 'precuenta'))"><Icon name="printer" :size="15" /> Precuenta</button>
                                <button type="button" class="button" @click="router.visit(accountUrl(mesaSeleccionada.account, 'cobrar'))"><Icon name="check" :size="15" /> Cobrar</button>
                                <button type="button" class="button button--neutral" @click="router.visit(accountUrl(mesaSeleccionada.account))"><Icon name="eye" :size="15" /> Ver consumo</button>
                            </div>

                            <p class="mesa-panel__mov">
                                <Icon name="refresh" :size="14" /> Último movimiento: {{ hora(mesaSeleccionada.account.bill_requested_at ?? mesaSeleccionada.account.opened_at) }}
                            </p>
                        </template>

                        <!-- Libre: abrir -->
                        <template v-else>
                            <p class="nota">Mesa libre para {{ mesaSeleccionada.effective_seats }} personas.</p>
                            <p v-if="opening.generalError.value" class="alert" role="alert">{{ opening.generalError.value }}</p>
                            <button type="button" class="button mesa-panel__abrir" :disabled="opening.processing.value" @click="openTable(mesaSeleccionada)">
                                <Icon name="plus" /> Abrir cuenta
                            </button>
                        </template>
                    </template>
                </aside>
            </div>
        </section>

        <!-- CUENTAS ACTIVAS -->
        <section class="tarjeta">
            <h2 class="activas__titulo">
                {{ onlyOpen ? 'Cuentas activas' : 'Todas las cuentas' }}
                <span class="activas__n">{{ accounts.length }}</span>
                <button type="button" class="link-button" @click="onlyOpen = ! onlyOpen; load()">
                    <Icon name="eye" :size="14" /> {{ onlyOpen ? 'Ver todas' : 'Ver sólo activas' }}
                </button>
            </h2>

            <!-- «No hay cuentas» sólo cuando ya se sabe: mientras carga, la lista vacía significa «todavía no llega», no
                 «no hay». Al recargar (Ver todas) la tabla anterior se queda hasta que llega la nueva. -->
            <p v-if="! loading && accounts.length === 0" class="nota">No hay cuentas.</p>

            <div v-else-if="accounts.length > 0" class="tabla-envoltura">
                <table class="activas">
                    <thead>
                        <tr><th>Mesa</th><th>Folio</th><th>Estado</th><th>Mesero</th><th class="der">Total</th><th class="der">Falta</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in accounts" :key="c.ulid">
                            <td>{{ c.display_name }}</td>
                            <td>{{ c.folio }}</td>
                            <td>{{ c.status_label }}</td>
                            <td>{{ c.waiter?.name ?? '—' }}</td>
                            <td class="der">{{ money(c.totals?.total) }}</td>
                            <td class="der">{{ money(c.totals?.due) }}</td>
                            <!-- `Link` de Inertia, no `<a href>`: un enlace pelón recargaba la página entera, shell
                                 incluido, sólo para entrar a una cuenta. -->
                            <td><Link :href="accountUrl(c)" class="link-button"><Icon name="eye" :size="14" /> Atender</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.25rem;
}

/* Barra: modos a la izquierda, vista a la derecha. */
.operar__barra { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; }
.segmento { display: inline-flex; gap: 0.4rem; }
.seg {
    font: inherit; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem;
    padding: 0.4rem 0.9rem; border: 1px solid var(--color-borde); border-radius: 999px;
    background: var(--color-superficie); color: var(--color-contenido); cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.seg:hover:not(.seg--on) { border-color: color-mix(in srgb, var(--color-acento) 45%, transparent); }
.seg--on { background: var(--color-acento); color: var(--color-acento-texto); border-color: var(--color-acento); }

/* Zonas. */
.zonas { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.85rem; }
.zona {
    font: inherit; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.3rem 0.75rem; border: 1px solid var(--color-borde); border-radius: 999px;
    background: var(--color-superficie); color: var(--color-suave); cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}
.zona:hover:not(.zona--on) { border-color: color-mix(in srgb, var(--color-acento) 45%, transparent); }
.zona--on { background: color-mix(in srgb, var(--color-acento) 12%, transparent); border-color: var(--color-acento); color: var(--color-acento); font-weight: 600; }
.zona--mias { margin-left: auto; }

/* Cuerpo: plano/forma (izq) + panel (der). */
.operar__cuerpo { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; gap: 1.25rem; align-items: start; margin-top: 1rem; }
/* Sin panel de mesa (sin salón, o sin poder pedir el piso), el formulario ocupa el ancho entero. */
.operar__cuerpo--solo { grid-template-columns: minmax(0, 1fr); }
.operar__principal { min-width: 0; }
/* Con el prefijo de la tarjeta: `.nota` se declara más abajo con `margin: 0` y, a igual peso, se comería el margen. */
.operar .operar__sin-salon { margin-top: 0.75rem; }

.leyenda { list-style: none; margin: 0.75rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.4rem 1rem; font-size: 0.8rem; color: var(--color-suave); }
.leyenda li { display: inline-flex; align-items: center; gap: 0.4rem; }
.lc { width: 0.7rem; height: 0.7rem; border-radius: 3px; display: inline-block; border: 1px solid; }
.lc--libre { background: #e8f5e9; border-color: #43a047; }
.lc--abierta { background: #e3f0ff; border-color: #3b82c4; }
.lc--pendiente { background: #ffe6d6; border-color: #e8703a; }
.lc--solicitada { background: #fff3cd; border-color: #d9a441; }

/* Lista de mesas (vista lista). */
.mesas-lista { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: 0.5rem; }
.mesa-fila {
    width: 100%; font: inherit; cursor: pointer; text-align: left;
    display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 0.5rem;
    padding: 0.6rem 0.8rem; border: 1px solid var(--color-borde); border-radius: var(--radio); background: var(--color-superficie);
}
.mesa-fila:hover { border-color: var(--color-acento); }
.mesa-fila--on { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }
.mesa-fila__code { font-weight: 650; }
.mesa-fila__estado { color: var(--color-suave); font-size: 0.82rem; }
.mesa-fila__total { font-variant-numeric: tabular-nums; font-weight: 600; }

/* Formularios de alta (barra / para llevar). */
.alta-form { display: grid; gap: 0.8rem; max-width: 24rem; }

/* Panel de la mesa seleccionada. */
.mesa-panel { padding: 1rem 1.1rem; margin: 0; align-self: start; }
.mesa-panel__titulo { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-suave); margin: 0 0 0.75rem; }
.mesa-panel__id { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.75rem; }
.mesa-panel__code { font-size: 1.3rem; font-weight: 700; }
.etiqueta { font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.55rem; border-radius: 999px; }
.etiqueta--libre { background: #e8f5e9; color: #2e7d32; }
.etiqueta--abierta { background: #e3f0ff; color: #2f6bb0; }
.etiqueta--solicitada { background: #fff3cd; color: #9a6b00; }
.mesa-panel__datos { display: grid; gap: 0.4rem; margin: 0 0 0.85rem; }
.mesa-panel__datos > div { display: flex; justify-content: space-between; gap: 1rem; font-size: 0.9rem; }
.mesa-panel__datos dt { color: var(--color-suave); margin: 0; }
.mesa-panel__datos dd { margin: 0; font-weight: 500; }
.mesa-panel__totales { display: flex; justify-content: space-between; align-items: baseline; padding: 0.35rem 0; border-top: 1px solid var(--color-borde); font-size: 0.95rem; }
.mesa-panel__totales strong { font-size: 1.15rem; font-variant-numeric: tabular-nums; }
.mesa-panel__totales--falta strong { color: var(--color-peligro); }
.mesa-panel__acciones { display: grid; gap: 0.5rem; margin: 0.85rem 0 0; }
.mesa-panel__acciones .button { width: 100%; }
.mesa-panel__abrir { width: 100%; margin-top: 0.5rem; }
.mesa-panel__mov { margin: 0.85rem 0 0; font-size: 0.8rem; color: var(--color-suave); display: flex; align-items: center; gap: 0.35rem; }

/* Cuentas activas. */
.activas__titulo { display: flex; align-items: center; gap: 0.6rem; margin: 0 0 0.75rem; font-size: 1.05rem; font-weight: 650; }
.activas__n { background: color-mix(in srgb, var(--color-acento) 14%, transparent); color: var(--color-acento); border-radius: 999px; padding: 0.05rem 0.55rem; font-size: 0.8rem; font-weight: 700; }
.activas__titulo .link-button { margin-left: auto; }
.tabla-envoltura { overflow-x: auto; }
.activas { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.activas th, .activas td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); white-space: nowrap; }
.activas th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.activas .der { text-align: right; font-variant-numeric: tabular-nums; }

.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0; }

@media (max-width: 60rem) {
    .operar__cuerpo { grid-template-columns: 1fr; }
}
</style>
