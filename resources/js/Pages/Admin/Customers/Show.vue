<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { formatInBranchTime } from '../../../support/datetime';
import Icon from '../../../components/Icon.vue';

/**
 * La ficha del cliente: su expediente (§6.6, ADR-005).
 *
 * Datos básicos, perfiles fiscales (CFDI-ready) y direcciones. El crédito y los consumos se consultan desde sus propias
 * superficies; aquí vive lo que la Iteración 6 añadió.
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
            api.get('/sat-catalogs'),
        ]);
        customer.value = c.data;
        profiles.value = p.data;
        addresses.value = a.data;
        consumos.value = h.data;
        sat.value = catalog.data;
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
</script>

<template>
    <Head :title="customer ? customer.name : 'Cliente'" />

    <div class="ficha">
        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <template v-else-if="customer">
            <p><a href="/admin/clientes">← Clientes</a></p>

            <!-- Datos básicos -->
            <section class="panel">
                <h2>{{ customer.name }} <button type="button" class="link-button link-button--warning" @click="startDatos"><Icon name="edit" /> Editar</button></h2>

                <form v-if="editingDatos" @submit.prevent="saveDatos.submit()">
                    <label>Nombre <input v-model="datosForm.name" type="text" required /></label>
                    <label>Teléfono <input v-model="datosForm.phone" type="text" /></label>
                    <label>Correo <input v-model="datosForm.email" type="email" /></label>
                    <label>Cumpleaños <input v-model="datosForm.birthday" type="date" /></label>
                    <label>Notas <input v-model="datosForm.notes" type="text" maxlength="300" /></label>
                    <p v-if="saveDatos.generalError.value" class="error">{{ saveDatos.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveDatos.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="editingDatos = false"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>

                <dl v-else class="datos">
                    <div><dt>Teléfono</dt><dd>{{ customer.phone ?? '—' }}</dd></div>
                    <div><dt>Correo</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                    <div><dt>Cumpleaños</dt><dd>{{ customer.birthday ?? '—' }}</dd></div>
                    <div><dt>Crédito</dt><dd>{{ customer.credit ? `$${customer.credit.balance} / $${customer.credit.limit}` : '—' }}</dd></div>
                </dl>
            </section>

            <!-- Perfiles fiscales -->
            <section class="panel">
                <h2>Perfiles fiscales <button type="button" class="link-button" @click="nuevoFiscal"><Icon name="plus" /> Agregar</button></h2>
                <p class="nota">Los datos para facturar (CFDI). Se capturan y validan; el timbrado llega después.</p>

                <ul class="lista">
                    <li v-for="p in profiles" :key="p.ulid">
                        <strong>{{ p.rfc }}</strong> — {{ p.business_name }}
                        <span class="tag">{{ p.tax_regime_code }} · {{ p.cfdi_use_code }}</span>
                        <span v-if="p.is_default" class="tag tag--def">predeterminado</span>
                        <button type="button" class="link-button link-button--warning" @click="editFiscal(p)"><Icon name="edit" /> Editar</button>
                        <button type="button" class="link-button link-button--danger" @click="removeFiscal.submit(p.ulid)"><Icon name="trash" /> Eliminar</button>
                    </li>
                    <li v-if="! profiles.length" class="nota">Sin perfiles fiscales.</li>
                </ul>

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
                    <p v-if="saveFiscal.generalError.value" class="error">{{ saveFiscal.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveFiscal.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="fiscalForm = null"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>
            </section>

            <!-- Direcciones -->
            <section class="panel">
                <h2>Direcciones <button type="button" class="link-button" @click="nuevaDir"><Icon name="plus" /> Agregar</button></h2>

                <ul class="lista">
                    <li v-for="a in addresses" :key="a.ulid">
                        <strong>{{ a.label || 'Dirección' }}</strong> — {{ a.street }} {{ a.exterior_number }}, {{ a.neighborhood }}, {{ a.municipality }}, {{ a.state }} {{ a.postal_code }}
                        <span v-if="a.is_default" class="tag tag--def">predeterminada</span>
                        <button type="button" class="link-button link-button--warning" @click="editDir(a)"><Icon name="edit" /> Editar</button>
                        <button type="button" class="link-button link-button--danger" @click="removeDir.submit(a.ulid)"><Icon name="trash" /> Eliminar</button>
                    </li>
                    <li v-if="! addresses.length" class="nota">Sin direcciones.</li>
                </ul>

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
                    <p v-if="saveDir.generalError.value" class="error">{{ saveDir.generalError.value }}</p>
                    <div class="acciones">
                        <button type="submit" class="button" :disabled="saveDir.processing.value"><Icon name="check" /> Guardar</button>
                        <button type="button" class="link-button" @click="addrForm = null"><Icon name="x" /> Cancelar</button>
                    </div>
                </form>
            </section>

            <!-- Historial de consumos -->
            <section class="panel">
                <h2>Consumos</h2>
                <p class="nota">Sus cuentas más recientes en el punto de venta. Las fechas están en la hora de la sucursal.</p>

                <table v-if="consumos.length" class="tabla">
                    <thead>
                        <tr><th>Fecha</th><th>Cuenta</th><th>Sucursal</th><th>Estado</th><th class="der">Total</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in consumos" :key="c.account_ulid">
                            <td>{{ formatInBranchTime(c.occurred_at, c.branch_timezone) }}</td>
                            <td>{{ c.reference }}</td>
                            <td>{{ c.branch_name }}</td>
                            <td>{{ ESTADO_CUENTA[c.status] ?? c.status }}</td>
                            <td class="der">${{ c.total }}</td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="nota">Sin consumos registrados.</p>
            </section>
        </template>
    </div>
</template>

<style scoped>
/* Botones/campos del sistema, para igualar esta ficha con el resto (antes usaba botones sin clase y enlaces azules). */
@import '../../../../css/admin-page.css';

.ficha { display: grid; gap: 1rem; max-width: 52rem; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; display: flex; gap: 0.75rem; align-items: baseline; }
.nota { color: #555; font-size: 0.9rem; }
.datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; }
.datos dt { font-size: 0.8rem; color: #666; }
.datos dd { margin: 0; font-weight: 600; }
.lista { list-style: none; margin: 0.5rem 0; padding: 0; display: grid; gap: 0.4rem; }
.lista li { font-size: 0.9rem; }
.tag { background: #f0f0f0; border-radius: 999px; padding: 0.1rem 0.5rem; font-size: 0.75rem; margin: 0 0.2rem; }
.tag--def { background: #e3f2fd; }
.sub { display: grid; gap: 0.5rem; margin-top: 0.75rem; border-top: 1px solid #eee; padding-top: 0.75rem; }
.fila { display: flex; gap: 0.75rem; }
.fila label { flex: 1; }
label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.check { display: flex; gap: 0.4rem; align-items: center; }
.acciones { display: flex; gap: 1rem; align-items: center; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; padding: 0; font-size: 0.85rem; }
.error { color: #a11; }
.tabla { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tabla th, .tabla td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #eee; }
.tabla .der { text-align: right; }
</style>
