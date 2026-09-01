<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

/**
 * Las cuentas vivas: el piso (§6.3).
 *
 * ## Abre con lo VIVO, no con todo
 *
 * `only_open=1`. A quien atiende no le interesa una cuenta pagada hace tres horas, y una lista que empieza por el
 * historial obliga a buscar lo de ahora entre lo de antes. El historial completo es otra pregunta y tiene su propio
 * filtro.
 *
 * ## Tres formas de abrir una cuenta, porque son tres cosas distintas
 *
 * En **mesa** (comer aquí), de **barra** con nombre libre («Señor de lentes») y **para llevar** con número de mostrador.
 * No son variantes de un formulario: cambian qué identifica a la cuenta, y una sola caja de texto haría que el mesero
 * tuviera que saber cuál llenar.
 *
 * ## Todo se pide por la sucursal ACTIVA
 *
 * Los códigos de mesa son únicos por sucursal, no por negocio. Sin el filtro, el desplegable salía con «M1, M1, M2, M2,
 * M3, M3…» —las mesas de las dos sucursales, indistinguibles— y sentar a alguien en la M1 equivocada abre la cuenta en
 * la otra sucursal, con sus comandas saliendo por la cocina de allá. Se vio en el navegador; ninguna prueba lo miraba.
 */
const accounts = ref([]);
const tables = ref([]);
const branches = ref([]);
const loading = ref(true);
const loadError = ref(null);
const onlyOpen = ref(true);

const page = usePage();

/**
 * La sucursal activa.
 *
 * El contexto de Inertia trae las llaves PLANAS —`branch_ulid`, no `active_branch.ulid`, que es la forma del recurso de
 * la API—. Confundirlas no revienta: deja `undefined` y se sigue trabajando sobre el negocio entero.
 */
const activeBranchUlid = computed(() => page.props.context?.branch_ulid ?? null);

const form = ref({ kind: 'table', table_ulid: '', label: '', branch_ulid: '' });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const deLaSucursal = activeBranchUlid.value ? { branch: activeBranchUlid.value } : {};

        const [cuentas, mesas, sucursales] = await Promise.all([
            api.get('/pos-accounts', { only_open: onlyOpen.value ? 1 : 0, per_page: 50, ...deLaSucursal }),
            api.get('/restaurant-tables', { per_page: 100, ...deLaSucursal }),
            api.get('/branches', { status: 'active', per_page: 50 }),
        ]);

        accounts.value = cuentas.data;
        tables.value = mesas.data;
        branches.value = sucursales.data;

        // La sucursal de una cuenta nueva es la ACTIVA, no «la primera de la lista»: quien atiende está parado en una
        // sucursal, y ofrecerle otra por omisión es invitarlo a equivocarse en el caso normal.
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

const opening = useApiForm(async (cuerpo) => (await api.post('/pos-accounts', cuerpo)).data);

/**
 * Abre la cuenta y ENTRA directo a capturar. Iniciar y marcar es un solo gesto: «abrir, buscar la cuenta en la lista y
 * volver a abrir» era un rodeo. El cuerpo cambia según la forma —mandar las tres llaves sería una contradicción que el
 * endpoint rechaza con 422—.
 */
async function open(cuerpo) {
    const cuenta = await opening.submit(cuerpo);

    if (cuenta) {
        router.visit(accountUrl(cuenta));
    }
}

const openTable = (mesa) => open({ table_ulid: mesa.ulid });
const openTakeout = () => open({ branch_ulid: form.value.branch_ulid, takeout: true });
const openWalkin = () => open({ branch_ulid: form.value.branch_ulid, label: form.value.label });

/** Las mesas donde se puede sentar a alguien ahora mismo. */
const freeTables = computed(() => tables.value.filter((m) => m.status === 'free' && ! m.joined_to));

function accountUrl(account) {
    return `/admin/pos/cuentas/${account.ulid}`;
}

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head title="Cuentas" />

    <h1 class="pos-titulo">Cuentas</h1>

    <div class="piso">
        <section class="panel">
            <h2>Abrir cuenta</h2>

            <div class="tipos">
                <button type="button" class="tipo" :class="{ 'tipo--activo': form.kind === 'table' }" @click="form.kind = 'table'">En mesa</button>
                <button type="button" class="tipo" :class="{ 'tipo--activo': form.kind === 'walkin' }" @click="form.kind = 'walkin'">De barra</button>
                <button type="button" class="tipo" :class="{ 'tipo--activo': form.kind === 'takeout' }" @click="form.kind = 'takeout'">Para llevar</button>
            </div>

            <!-- En mesa: las mesas LIBRES como botones. Tocar una abre la cuenta y entra a capturar. -->
            <template v-if="form.kind === 'table'">
                <p v-if="freeTables.length === 0" class="nota">
                    No hay mesas libres. Una mesa ocupada no admite una segunda cuenta: cóbrala o mueve la que tiene.
                </p>
                <div v-else class="mesas">
                    <button
                        v-for="m in freeTables"
                        :key="m.ulid"
                        type="button"
                        class="mesa"
                        :disabled="opening.processing.value"
                        @click="openTable(m)"
                    >
                        <span class="mesa__code">{{ m.code }}</span>
                        <span class="mesa__seats">{{ m.seats }} lugares</span>
                    </button>
                </div>
            </template>

            <!-- De barra: nombre libre. -->
            <form v-else-if="form.kind === 'walkin'" class="mini" @submit.prevent="openWalkin()">
                <label>
                    Nombre
                    <input v-model="form.label" type="text" placeholder="Señor de lentes" required />
                </label>
                <label v-if="branches.length > 1">
                    Sucursal
                    <select v-model="form.branch_ulid" required>
                        <option v-for="s in branches" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                    </select>
                </label>
                <button type="submit" :disabled="opening.processing.value">Abrir</button>
            </form>

            <!-- Para llevar: un toque; el mostrador lo asigna el sistema. -->
            <div v-else class="mini">
                <p class="nota">El número de mostrador lo asigna el sistema y vuelve a 1 cada jornada: es el que se grita.</p>
                <label v-if="branches.length > 1">
                    Sucursal
                    <select v-model="form.branch_ulid" required>
                        <option v-for="s in branches" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                    </select>
                </label>
                <button type="button" :disabled="opening.processing.value" @click="openTakeout()">Abrir para llevar</button>
            </div>

            <p v-if="opening.generalError.value" class="error">{{ opening.generalError.value }}</p>
        </section>

        <section class="panel">
            <h2>
                {{ onlyOpen ? 'Cuentas vivas' : 'Todas las cuentas' }}
                <button type="button" class="enlace" @click="onlyOpen = ! onlyOpen; load()">
                    {{ onlyOpen ? 'ver todas' : 'ver sólo las vivas' }}
                </button>
            </h2>

            <template v-if="loading"></template>
            <div v-else-if="loadError" class="error">{{ loadError.title }}</div>
            <p v-else-if="accounts.length === 0" class="nota">No hay cuentas.</p>

            <table v-else>
                <thead>
                    <tr><th>Cuenta</th><th>Folio</th><th>Estado</th><th>Total</th><th>Falta</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="c in accounts" :key="c.ulid">
                        <td>{{ c.display_name }}</td>
                        <td>{{ c.folio }}</td>
                        <td>{{ c.status_label }}</td>
                        <td>{{ money(c.totals.total) }}</td>
                        <td>{{ money(c.totals.due) }}</td>
                        <td><Link :href="accountUrl(c)" class="lista-abrir">Abrir</Link></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<style scoped>
/* Título en línea con las migajas: fuera de la rejilla para no caer debajo (como el editor de salón). */
.pos-titulo { margin: 0 0 0.25rem; font-size: 1.6rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; }

/* Dos columnas a todo el ancho: abrir cuenta (compacta) junto a la lista de cuentas, en vez de apiladas y angostas. */
.piso { display: grid; grid-template-columns: minmax(20rem, 26rem) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
@media (max-width: 60rem) { .piso { grid-template-columns: 1fr; } }

/* Tarjeta: mismo lenguaje que el resto del administrador (superficie, borde, radio, sombra). */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.15rem 1.25rem;
}
.panel h2 { margin-top: 0; font-size: 1.05rem; font-weight: 650; display: flex; gap: 1rem; align-items: baseline; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }

label { display: grid; gap: 0.3rem; font-size: 0.85rem; }

/* Modo de apertura: botones tipo segmento. */
.tipos { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
.tipo {
    font: inherit;
    font-size: 0.85rem;
    padding: 0.4rem 0.9rem;
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.tipo:hover:not(.tipo--activo) { border-color: color-mix(in srgb, var(--color-acento) 45%, transparent); }
.tipo--activo { background: var(--color-acento); color: var(--color-acento-texto); border-color: var(--color-acento); }

/* Mesas libres: botones grandes en rejilla; un toque para sentar. */
.mesas {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
    gap: 0.6rem;
}
.mesa {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.15rem;
    padding: 0.9rem 0.5rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.mesa:hover:not(:disabled) {
    border-color: var(--color-acento);
    box-shadow: 0 6px 14px -6px color-mix(in srgb, var(--color-acento) 45%, transparent);
    transform: translateY(-1px);
}
.mesa:disabled { opacity: 0.55; cursor: not-allowed; }
.mesa__code { font-weight: 700; font-size: 1rem; }
.mesa__seats { font-size: 0.75rem; color: var(--color-suave); }

.mini { display: grid; gap: 0.85rem; max-width: 24rem; }

/* Campos: el anillo de foco temado lo pinta app.css. */
input[type="text"],
select {
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
}

/* Botón principal: afordante y con buen blanco táctil para la tablet de caja. */
.mini button {
    font: inherit;
    font-size: 0.95rem;
    font-weight: 600;
    padding: 0.65rem 1.25rem;
    border: 1px solid transparent;
    border-radius: 0.5rem;
    background: var(--color-acento);
    color: var(--color-acento-texto);
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
    cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
.mini button:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.mini button:disabled { opacity: 0.55; cursor: not-allowed; }

table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
th { font-size: 0.78rem; font-weight: 600; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }

/* Acciones de fila y alternadores: botón pequeño con borde, no texto azul suelto. */
.enlace,
.lista-abrir {
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
.lista-abrir:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); }

@media (prefers-reduced-motion: reduce) {
    .mini button:hover:not(:disabled) { transform: none; }
}
</style>
