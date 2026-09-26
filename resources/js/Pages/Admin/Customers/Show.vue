<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError, orEmptyWhenForbidden } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { formatInBranchTime } from '../../../support/datetime';
import { formatMoney } from '../../../support/money';
import Icon from '../../../components/Icon.vue';
import CustomerCreditPanel from '../../../components/customers/CustomerCreditPanel.vue';
import CustomerCreditStatement from '../../../components/customers/CustomerCreditStatement.vue';

/**
 * La ficha del cliente: su expediente (§6.6, ADR-005).
 *
 * Datos básicos, perfiles fiscales (CFDI-ready), direcciones, crédito y consumos. El crédito —cifras, límite, abonos y
 * estado de cuenta— vive en `components/customers/`, un bloque por permiso (`finance.customer_credit.*`).
 *
 * ## El régimen se valida contra el catálogo del SAT, no es texto libre
 *
 * Los desplegables de régimen y uso CFDI vienen del catálogo oficial. El régimen se filtra por el tipo de persona que
 * implica el RFC: 12 caracteres es persona moral, 13 física.
 */
const props = defineProps({ customerUlid: { type: String, required: true } });

const customer = ref(null);
const profiles = ref([]);
const addresses = ref([]);
const consumos = ref([]);
const sat = ref({ tax_regimes: [], cfdi_uses: [] });
const loading = ref(true);
const loadError = ref(null);

/** El estado de la cuenta, como lo lee una persona. */
const ESTADO_CUENTA = {
    open: 'Abierta',
    bill_requested: 'Cuenta pedida',
    closed: 'Cerrada',
    paid: 'Pagada',
    cancelled: 'Cancelada',
};

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const [c, p, a, h, catalog] = await Promise.all([
            api.get(`/customers/${props.customerUlid}`),
            api.get(`/customers/${props.customerUlid}/fiscal-profiles`),
            api.get(`/customers/${props.customerUlid}/addresses`),
            api.get(`/customers/${props.customerUlid}/consumos`),
            // El catálogo del SAT sólo sirve para CAPTURAR perfiles fiscales y exige `customers.fiscal_profiles.manage`.
            // Sin ese permiso llega vacío en lugar de tumbar la ficha entera, con el crédito y los consumos que el rol sí
            // puede ver (a un Cajero le fallaba toda la ficha con 403).
            orEmptyWhenForbidden(api.get('/sat-catalogs')),
        ]);
        customer.value = c.data;
        profiles.value = p.data;
        addresses.value = a.data;
        consumos.value = h.data;
        sat.value = { tax_regimes: catalog.data?.tax_regimes ?? [], cfdi_uses: catalog.data?.cfdi_uses ?? [] };
    } catch (e) {
        if (e instanceof ApiError) loadError.value = e; else throw e;
    } finally {
        loading.value = false;
    }
}

// --- Datos básicos ---
const datosForm = ref({});
const editingDatos = ref(false);

function startDatos() {
    datosForm.value = {
        name: customer.value.name,
        phone: customer.value.phone ?? '',
        email: customer.value.email ?? '',
        birthday: customer.value.birthday ?? '',
        notes: customer.value.notes ?? '',
    };
    editingDatos.value = true;
}

const saveDatos = useApiForm(async () => {
    // Los campos opcionales vacíos viajan como null, no como cadena vacía: así el backend los limpia de verdad y el
    // teléfono no choca con el `unique` de otro cliente que también lo tenga en blanco.
    const limpio = (v) => (v === '' ? null : v);
    await api.patch(`/customers/${props.customerUlid}`, {
        name: datosForm.value.name,
        phone: limpio(datosForm.value.phone),
        email: limpio(datosForm.value.email),
        birthday: limpio(datosForm.value.birthday),
        notes: limpio(datosForm.value.notes),
    });
    editingDatos.value = false;
    await load();
});

// --- Perfiles fiscales ---
const fiscalForm = ref(null);

function nuevoFiscal() {
    fiscalForm.value = { ulid: null, rfc: '', business_name: '', postal_code: '', tax_regime_code: '', cfdi_use_code: '', is_default: profiles.value.length === 0 };
}

function editFiscal(p) {
    fiscalForm.value = { ...p };
}

/** El tipo de persona que implica el RFC tecleado, para filtrar los regímenes válidos. */
const fiscalPersonType = computed(() => (fiscalForm.value?.rfc ?? '').trim().length === 12 ? 'moral' : 'fisica');

const regimesForPerson = computed(() => sat.value.tax_regimes.filter(
    (r) => fiscalPersonType.value === 'moral' ? r.moral : r.fisica,
));

const saveFiscal = useApiForm(async () => {
    const cuerpo = {
        rfc: fiscalForm.value.rfc,
        business_name: fiscalForm.value.business_name,
        postal_code: fiscalForm.value.postal_code,
        tax_regime_code: fiscalForm.value.tax_regime_code,
        cfdi_use_code: fiscalForm.value.cfdi_use_code,
        is_default: fiscalForm.value.is_default,
    };
    if (fiscalForm.value.ulid) {
        await api.patch(`/customers/${props.customerUlid}/fiscal-profiles/${fiscalForm.value.ulid}`, cuerpo);
    } else {
        await api.post(`/customers/${props.customerUlid}/fiscal-profiles`, cuerpo);
    }
    fiscalForm.value = null;
    await load();
});

const removeFiscal = useApiForm(async (ulid) => {
    await api.delete(`/customers/${props.customerUlid}/fiscal-profiles/${ulid}`);
    await load();
});

/** Es un borrado real (queda en la bitácora, pero no se recupera): se confirma antes, con el RFC a la vista. */
async function confirmRemoveFiscal(p) {
    if (!window.confirm(`¿Eliminar el perfil fiscal «${p.rfc}»? Se borra del expediente y ya no podrá usarse para facturarle.`)) return;

    await removeFiscal.submit(p.ulid);
}

// --- Direcciones ---
const addrForm = ref(null);

function nuevaDir() {
    addrForm.value = { ulid: null, label: '', street: '', exterior_number: '', interior_number: '', neighborhood: '', municipality: '', state: '', postal_code: '', reference: '', is_default: addresses.value.length === 0 };
}

function editDir(a) {
    addrForm.value = { ...a };
}

const saveDir = useApiForm(async () => {
    const { ulid, ...cuerpo } = addrForm.value;
    if (ulid) {
        await api.patch(`/customers/${props.customerUlid}/addresses/${ulid}`, cuerpo);
    } else {
        await api.post(`/customers/${props.customerUlid}/addresses`, cuerpo);
    }
    addrForm.value = null;
    await load();
});

const removeDir = useApiForm(async (ulid) => {
    await api.delete(`/customers/${props.customerUlid}/addresses/${ulid}`);
    await load();
});

/** Igual que el perfil fiscal: borrado real, confirmado con la dirección a la vista (la etiqueta es opcional). */
async function confirmRemoveDir(a) {
    const nombre = a.label || `${a.street} ${a.exterior_number}`;

    if (!window.confirm(`¿Eliminar la dirección «${nombre}»? Se borra del expediente del cliente.`)) return;

    await removeDir.submit(a.ulid);
}

// --- Crédito ---

/** Sube tras cada abono para que el estado de cuenta se relea desde su primera página. */
const statementVersion = ref(0);

/**
 * El panel de crédito trae al cliente fresco del servidor (límite nuevo, saldo tras un abono). Se reemplaza sólo el
 * cliente: recargar la ficha entera desmontaría el estado de cuenta y la página en la que estaba.
 */
function onCreditUpdated(fresh) {
    customer.value = fresh;
}

function onRepaid() {
    statementVersion.value++;
}
</script>

<template>
    <Head :title="customer ? customer.name : 'Cliente'" />

    <div class="ficha">
        <template v-if="loading"></template>
        <div v-else-if="loadError" class="error" role="alert">{{ loadError.title }}</div>

        <template v-else-if="customer">
            <p class="ficha__full"><Link href="/admin/clientes">← Clientes</Link></p>

            <!-- Datos básicos -->
            <section class="panel">
                <h2>{{ customer.name }} <button type="button" class="link-button link-button--warning" @click="startDatos"><Icon name="edit" /> Editar</button></h2>

                <form v-if="editingDatos" @submit.prevent="saveDatos.submit()">
                    <label>Nombre <input v-model="datosForm.name" type="text" required /></label>
                    <label>Teléfono <input v-model="datosForm.phone" type="text" /></label>
                    <label>Correo <input v-model="datosForm.email" type="email" /></label>
                    <label>Cumpleaños <input v-model="datosForm.birthday" type="date" /></label>
                    <label>Notas <input v-model="datosForm.notes" type="text" maxlength="300" /></label>
                    <p v-if="saveDatos.generalError.value" class="error" role="alert">{{ saveDatos.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveDatos.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="editingDatos = false"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>

                <dl v-else class="datos">
                    <div><dt>Teléfono</dt><dd>{{ customer.phone ?? '—' }}</dd></div>
                    <div><dt>Correo</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                    <div><dt>Cumpleaños</dt><dd>{{ customer.birthday ?? '—' }}</dd></div>
                    <div><dt>Crédito (saldo / límite)</dt><dd>{{ customer.credit ? `${formatMoney(customer.credit.balance)} / ${formatMoney(customer.credit.limit)}` : '—' }}</dd></div>
                </dl>
            </section>

            <!-- Perfiles fiscales -->
            <section class="panel">
                <h2>Perfiles fiscales <button v-can.write="'customers.fiscal_profiles.manage'" type="button" class="link-button" @click="nuevoFiscal"><Icon name="plus" /> Agregar</button></h2>
                <p class="nota">Los datos para facturar (CFDI). Se capturan y validan; el timbrado llega después.</p>

                <ul class="lista">
                    <li v-for="p in profiles" :key="p.ulid">
                        <strong>{{ p.rfc }}</strong> — {{ p.business_name }}
                        <span class="tag">{{ p.tax_regime_code }} · {{ p.cfdi_use_code }}</span>
                        <span v-if="p.is_default" class="tag tag--def">predeterminado</span>
                        <button v-can.write="'customers.fiscal_profiles.manage'" type="button" class="link-button link-button--warning" @click="editFiscal(p)"><Icon name="edit" /> Editar</button>
                        <button v-can.write="'customers.fiscal_profiles.manage'" type="button" class="link-button link-button--danger" :disabled="removeFiscal.processing.value" @click="confirmRemoveFiscal(p)"><Icon name="trash" /> Eliminar</button>
                    </li>
                    <li v-if="! profiles.length" class="nota">Sin perfiles fiscales.</li>
                </ul>
                <p v-if="removeFiscal.generalError.value" class="error" role="alert">{{ removeFiscal.generalError.value }}</p>

                <form v-if="fiscalForm" class="sub" @submit.prevent="saveFiscal.submit()">
                    <label>RFC <input v-model="fiscalForm.rfc" type="text" required maxlength="13" style="text-transform:uppercase" /></label>
                    <label>Razón social <input v-model="fiscalForm.business_name" type="text" required maxlength="200" /></label>
                    <label>CP fiscal <input v-model="fiscalForm.postal_code" type="text" maxlength="5" required /></label>
                    <label>
                        Régimen ({{ fiscalPersonType === 'moral' ? 'persona moral' : 'persona física' }})
                        <select v-model="fiscalForm.tax_regime_code" required>
                            <option value="">Elige…</option>
                            <option v-for="r in regimesForPerson" :key="r.code" :value="r.code">{{ r.code }} — {{ r.description }}</option>
                        </select>
                    </label>
                    <label>
                        Uso CFDI
                        <select v-model="fiscalForm.cfdi_use_code" required>
                            <option value="">Elige…</option>
                            <option v-for="u in sat.cfdi_uses" :key="u.code" :value="u.code">{{ u.code }} — {{ u.description }}</option>
                        </select>
                    </label>
                    <label class="check"><input v-model="fiscalForm.is_default" type="checkbox" /> Predeterminado</label>
                    <p v-if="saveFiscal.generalError.value" class="error" role="alert">{{ saveFiscal.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveFiscal.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="fiscalForm = null"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>
            </section>

            <!-- Direcciones -->
            <section class="panel">
                <h2>Direcciones <button v-can.write="'customers.addresses.manage'" type="button" class="link-button" @click="nuevaDir"><Icon name="plus" /> Agregar</button></h2>

                <ul class="lista">
                    <li v-for="a in addresses" :key="a.ulid">
                        <strong>{{ a.label || 'Dirección' }}</strong> — {{ a.street }} {{ a.exterior_number }}, {{ a.neighborhood }}, {{ a.municipality }}, {{ a.state }} {{ a.postal_code }}
                        <span v-if="a.is_default" class="tag tag--def">predeterminada</span>
                        <button v-can.write="'customers.addresses.manage'" type="button" class="link-button link-button--warning" @click="editDir(a)"><Icon name="edit" /> Editar</button>
                        <button v-can.write="'customers.addresses.manage'" type="button" class="link-button link-button--danger" :disabled="removeDir.processing.value" @click="confirmRemoveDir(a)"><Icon name="trash" /> Eliminar</button>
                    </li>
                    <li v-if="! addresses.length" class="nota">Sin direcciones.</li>
                </ul>
                <p v-if="removeDir.generalError.value" class="error" role="alert">{{ removeDir.generalError.value }}</p>

                <form v-if="addrForm" class="sub" @submit.prevent="saveDir.submit()">
                    <label>Etiqueta <input v-model="addrForm.label" type="text" maxlength="60" placeholder="Casa" /></label>
                    <div class="fila">
                        <label>Calle <input v-model="addrForm.street" type="text" required /></label>
                        <label>Núm. ext <input v-model="addrForm.exterior_number" type="text" required /></label>
                        <label>Núm. int <input v-model="addrForm.interior_number" type="text" /></label>
                    </div>
                    <div class="fila">
                        <label>Colonia <input v-model="addrForm.neighborhood" type="text" required /></label>
                        <label>Municipio <input v-model="addrForm.municipality" type="text" required /></label>
                    </div>
                    <div class="fila">
                        <label>Estado <input v-model="addrForm.state" type="text" required /></label>
                        <label>CP <input v-model="addrForm.postal_code" type="text" maxlength="5" required /></label>
                    </div>
                    <label>Referencia <input v-model="addrForm.reference" type="text" maxlength="200" /></label>
                    <label class="check"><input v-model="addrForm.is_default" type="checkbox" /> Predeterminada</label>
                    <p v-if="saveDir.generalError.value" class="error" role="alert">{{ saveDir.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveDir.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="addrForm = null"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>
            </section>

            <!-- Crédito: cifras para quien ve la ficha; límite y abonos con `finance.customer_credit.manage` -->
            <CustomerCreditPanel :customer="customer" @updated="onCreditUpdated" @repaid="onRepaid" />

            <!-- Estado de cuenta: sólo con `finance.customer_credit.view` (el componente no se pinta sin él) -->
            <CustomerCreditStatement
                class="ficha__full"
                :customer-ulid="customerUlid"
                :accounts="consumos"
                :version="statementVersion"
            />

            <!-- Historial de consumos -->
            <section class="panel ficha__full">
                <h2>Consumos</h2>
                <p class="nota">Sus cuentas más recientes en el punto de venta. Las fechas están en la hora de la sucursal.</p>

                <div v-if="consumos.length" class="tabla-envoltura">
                    <table class="tabla">
                        <thead>
                            <tr><th>Fecha</th><th>Cuenta</th><th>Sucursal</th><th>Estado</th><th class="der">Total</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in consumos" :key="c.account_ulid">
                                <td>{{ formatInBranchTime(c.occurred_at, c.branch_timezone) }}</td>
                                <td>{{ c.reference }}</td>
                                <td>{{ c.branch_name }}</td>
                                <td>{{ ESTADO_CUENTA[c.status] ?? c.status }}</td>
                                <td class="der">{{ formatMoney(c.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="nota">Sin consumos registrados.</p>
            </section>
        </template>
    </div>
</template>

<style scoped>
/* Botones/campos del sistema, para igualar esta ficha con el resto (antes usaba botones sin clase y enlaces azules). */
@import '../../../../css/admin-page.css';

/* Dos columnas para no quedar en una tira angosta y larga; el enlace de volver y la tabla de consumos van a lo ancho. */
.ficha { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.25rem; align-items: start; }
.ficha__full { grid-column: 1 / -1; }
@media (max-width: 60rem) { .ficha { grid-template-columns: 1fr; } }
/* Igualada al resto del sistema: superficie + borde + radio + sombra de los tokens (antes: borde #d6d6d6, radio 6px). */
.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.1rem 1.25rem;
}
.panel h2 { margin-top: 0; display: flex; gap: 0.75rem; align-items: baseline; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; }
.datos dt { font-size: 0.8rem; color: var(--color-suave); }
.datos dd { margin: 0; font-weight: 600; }
.lista { list-style: none; margin: 0.5rem 0; padding: 0; display: grid; gap: 0.4rem; }
.lista li { font-size: 0.9rem; }
.tag {
    background: color-mix(in srgb, var(--color-suave) 15%, transparent);
    color: var(--color-suave);
    border-radius: 999px; padding: 0.1rem 0.5rem; font-size: 0.75rem; margin: 0 0.2rem; font-weight: 600;
}
.tag--def { background: color-mix(in srgb, var(--color-acento) 14%, transparent); color: var(--color-acento); }
.sub { display: grid; gap: 0.5rem; margin-top: 0.75rem; border-top: 1px solid var(--color-borde); padding-top: 0.75rem; }
/* Envuelve: tres campos lado a lado no caben en una columna de la ficha, y sin esto desbordaban el panel. */
.fila { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.fila label { flex: 1; }
label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.check { display: flex; gap: 0.4rem; align-items: center; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.enlace { background: none; border: 0; color: var(--color-acento); cursor: pointer; padding: 0; font-size: 0.85rem; }
.error { color: var(--color-peligro); }
/* En pantalla angosta la tabla se desplaza dentro de su panel en lugar de ensanchar la página entera. */
.tabla-envoltura { overflow-x: auto; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
.tabla th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-suave); }
.tabla .der { text-align: right; font-variant-numeric: tabular-nums; }
</style>
