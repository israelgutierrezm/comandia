<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatMoney } from '../../../support/money';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Configuración de la tienda en línea (Iteración 8, Tanda B). Una tienda por negocio: dirección pública, nombre, color, y
 * **qué sucursales atiende** (el cliente elige una al comprar). Sólo aparece si el módulo Ecommerce está activo.
 *
 * ## Zonas de envío: su propio permiso, y editar manda la zona completa
 *
 * Las zonas se administran con `ecommerce.shipping_zones.manage`, no con el permiso de configurar la tienda: quien no lo
 * tiene no las ve, en lugar de encontrarse un 403. `PUT /shipping-zones/{zona}` exige nombre, costo y estado juntos, así
 * que activar o desactivar reenvía el nombre y el costo tal como están. Una zona inactiva no se ofrece en el checkout y
 * el servidor rechaza un pedido que la cite; los pedidos ya hechos guardan su propio costo de envío.
 */
const form = ref({
    slug: '', name: '', is_active: false, theme_primary: '#0b8a99', auto_accept_orders: false,
    // Modo y entrega (ADR-013): preparación (A&B) o envío (retail), y qué entregas ofrece.
    fulfillment_mode: 'preparation', offers_pickup: true, offers_shipping: false,
    branch_ulids: [],
});
const branches = ref([]);
const publicUrl = ref(null);
const error = ref(null);
const saved = ref(false);
const saving = ref(false);

const { can, canWrite } = useAuthorization();
const puedeVerZonas = computed(() => can('ecommerce.shipping_zones.manage'));
const puedeEditarZonas = computed(() => canWrite('ecommerce.shipping_zones.manage'));

const zones = ref([]);
// Sólo con una lectura buena: sin ella, «Sin zonas» mentiría sobre una carga que falló.
const zonesLoaded = ref(false);
const zonesLoadError = ref(null);
const zoneError = ref(null);
const zoneForm = ref({ name: '', cost: '' });
// La zona abierta en edición (su ULID) y su borrador: una a la vez.
const editingZone = ref(null);
const zoneDraft = ref({ name: '', cost: '' });
// La zona con una acción en curso (activar, desactivar, quitar): sus botones esperan a que termine.
const zoneBusy = ref(null);
// Si la tienda ofrece envío TAL COMO ESTÁ GUARDADA; la casilla del formulario puede traer un cambio sin guardar.
const savedOffersShipping = ref(false);

onMounted(async () => {
    const [ctx, store] = await Promise.all([api.get('/context'), api.get('/store')]);
    branches.value = ctx.data.branches ?? [];
    savedOffersShipping.value = store.data?.offers_shipping ?? false;

    if (store.data) {
        form.value = {
            slug: store.data.slug,
            name: store.data.name,
            is_active: store.data.is_active,
            theme_primary: store.data.theme_primary,
            auto_accept_orders: store.data.auto_accept_orders,
            fulfillment_mode: store.data.fulfillment_mode ?? 'preparation',
            offers_pickup: store.data.offers_pickup ?? true,
            offers_shipping: store.data.offers_shipping ?? false,
            branch_ulids: store.data.branch_ulids ?? [],
        };
        publicUrl.value = store.data.public_url;
    }

    if (puedeVerZonas.value) {
        await loadZones();
    }
});

async function loadZones() {
    try {
        const { data } = await api.get('/shipping-zones');
        zones.value = data;
        zonesLoadError.value = null;
        zonesLoaded.value = true;
    } catch (e) {
        if (e instanceof ApiError) zonesLoadError.value = e.title; else throw e;
    }
}

// Los callbacks no devuelven la respuesta: `useApiForm` lee `null` (lo que da un 204) como fallo; `undefined`, como éxito.
const createZone = useApiForm(
    async () => {
        await api.post('/shipping-zones', { name: zoneForm.value.name, cost: zoneForm.value.cost, is_active: true });
    },
    { success: { kind: 'create', entity: 'Zona de envío' } },
);

async function addZone() {
    if (await createZone.submit()) {
        zoneForm.value = { name: '', cost: '' };
        await loadZones();
    }
}

const updateZone = useApiForm(
    async (zone) => {
        // El estado viaja tal como está: editar nombre y costo no la activa ni la desactiva.
        await api.put(`/shipping-zones/${zone.ulid}`, { name: zoneDraft.value.name, cost: zoneDraft.value.cost, is_active: zone.is_active });
    },
    { success: { kind: 'update', entity: 'Zona de envío' } },
);

function startZoneEdit(zone) {
    editingZone.value = zone.ulid;
    zoneDraft.value = { name: zone.name, cost: zone.cost };
    updateZone.fieldErrors.value = {};
    updateZone.generalError.value = null;
}

async function saveZone(zone) {
    if (await updateZone.submit(zone)) {
        editingZone.value = null;
        await loadZones();
    }
}

/**
 * Quitar o desactivar la ÚNICA zona activa con el envío encendido deja a la tienda ofreciendo envío sin ninguna zona
 * que elegir: el checkout no lista zonas inactivas y el servidor rechaza el pedido. Se dice en la confirmación.
 */
function avisoUltimaZona(zone) {
    const activas = zones.value.filter((z) => z.is_active);

    return savedOffersShipping.value && zone.is_active && activas.length === 1
        ? '\n\nEs la única zona activa y la tienda ofrece envío: nadie podrá completar un pedido a domicilio hasta que haya otra.'
        : '';
}

const toggleZoneForm = useApiForm(
    async (zone) => {
        await api.put(`/shipping-zones/${zone.ulid}`, { name: zone.name, cost: zone.cost, is_active: !zone.is_active });
    },
    {
        success: (result, [zone]) => (zone.is_active
            ? `Zona «${zone.name}» desactivada: ya no se ofrece en el checkout.`
            : `Zona «${zone.name}» activada.`),
    },
);

async function toggleZone(zone) {
    if (zone.is_active && !window.confirm(
        `¿Desactivar la zona «${zone.name}»? Dejará de ofrecerse en el checkout hasta que la vuelvas a activar. `
        + `Los pedidos que ya la usaron conservan su costo de envío.${avisoUltimaZona(zone)}`,
    )) {
        return;
    }

    zoneBusy.value = zone.ulid;
    zoneError.value = null;
    try {
        if (await toggleZoneForm.submit(zone)) await loadZones();
    } finally {
        zoneBusy.value = null;
    }
}

async function deleteZone(zone) {
    // Quitar es un borrado real; desactivar ya existe para «dejar de ofrecerla un tiempo», y la confirmación lo dice.
    if (!window.confirm(
        `¿Quitar la zona «${zone.name}»? Dejará de ofrecerse en el checkout y no se puede deshacer; los pedidos que ya `
        + 'la usaron conservan su costo de envío.'
        + (zone.is_active ? ' Si sólo quieres dejar de ofrecerla un tiempo, mejor desactívala.' : '')
        + avisoUltimaZona(zone),
    )) {
        return;
    }

    zoneBusy.value = zone.ulid;
    zoneError.value = null;
    toggleZoneForm.generalError.value = null;
    try {
        await api.delete(`/shipping-zones/${zone.ulid}`);
        if (editingZone.value === zone.ulid) editingZone.value = null;
        await loadZones();
    } catch (e) {
        if (e instanceof ApiError) zoneError.value = e.title; else throw e;
    } finally {
        zoneBusy.value = null;
    }
}

/** Lo que falló al activar, desactivar o quitar una zona, junto a las zonas y no arriba de la página. */
const zoneActionError = computed(() => zoneError.value ?? toggleZoneForm.generalError.value);

function toggleBranch(ulid) {
    const i = form.value.branch_ulids.indexOf(ulid);
    i === -1 ? form.value.branch_ulids.push(ulid) : form.value.branch_ulids.splice(i, 1);
}

async function save() {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await api.put('/store', form.value);
        publicUrl.value = data.public_url;
        savedOffersShipping.value = data.offers_shipping ?? form.value.offers_shipping;
        saved.value = true;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Tienda" />

    <div class="tienda animar-entrada">
        <ListHeader
            title="Tienda en línea"
            subtitle="Configura la dirección pública de tu tienda y qué sucursales atiende."
        />

        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-else-if="saved" class="alert alert--ok" role="status">Cambios guardados.</p>

        <section class="tarjeta bloque">
            <div class="field">
                <label class="field__label" for="store-name">Nombre</label>
                <input id="store-name" v-model="form.name" class="input" type="text" maxlength="120" />
            </div>

            <div class="field">
                <label class="field__label" for="store-slug">Dirección (slug)</label>
                <input id="store-slug" v-model="form.slug" class="input" type="text" maxlength="80" placeholder="mi-tienda" />
            </div>

            <label class="check"><input v-model="form.is_active" type="checkbox" /> Tienda activa (visible al público)</label>
            <label class="check"><input v-model="form.auto_accept_orders" type="checkbox" /> Aceptar pedidos automáticamente al pagarse</label>

            <div class="field field--color">
                <label class="field__label" for="store-color">Color de la tienda</label>
                <input id="store-color" v-model="form.theme_primary" type="color" class="color" />
            </div>
        </section>

        <fieldset class="tarjeta bloque">
            <legend>Modo de la tienda</legend>
            <label class="radio">
                <input v-model="form.fulfillment_mode" type="radio" value="preparation" />
                <span>
                    <strong>Preparación</strong> — alimentos y bebidas: al aceptar sale a cocina y se entrega enseguida.
                </span>
            </label>
            <label class="radio">
                <input v-model="form.fulfillment_mode" type="radio" value="dispatch" />
                <span>
                    <strong>Envío</strong> — retail (p. ej. ferretería): se empaca y se envía después, sin cocina.
                </span>
            </label>
        </fieldset>

        <fieldset class="tarjeta bloque">
            <legend>Entregas que ofrece</legend>
            <label class="check"><input v-model="form.offers_pickup" type="checkbox" /> Recoger en sucursal</label>
            <label class="check"><input v-model="form.offers_shipping" type="checkbox" /> Envío (requiere al menos una zona)</label>
            <p class="page-header__hint">La tienda debe ofrecer al menos una de las dos.</p>
        </fieldset>

        <fieldset class="tarjeta bloque">
            <legend>Sucursales que atiende</legend>
            <label v-for="b in branches" :key="b.ulid" class="check">
                <input type="checkbox" :checked="form.branch_ulids.includes(b.ulid)" @change="toggleBranch(b.ulid)" />
                {{ b.name }}
            </label>
        </fieldset>

        <div class="acciones">
            <button type="button" class="button" :disabled="saving" @click="save">
                {{ saving ? 'Guardando…' : 'Guardar' }}
            </button>
            <a v-if="publicUrl && form.is_active" :href="publicUrl" target="_blank" rel="noopener" class="link-button">
                Ver tienda
            </a>
        </div>

        <fieldset class="tarjeta bloque">
            <legend>Zonas de envío</legend>

            <p v-if="!puedeVerZonas" class="page-header__hint">
                Tu rol activo no administra las zonas de envío: las configura quien tenga ese permiso.
            </p>

            <template v-else>
                <p v-if="zonesLoadError" class="alert" role="alert">{{ zonesLoadError }}</p>
                <p v-if="zoneActionError" class="alert" role="alert">{{ zoneActionError }}</p>

                <ul v-if="zones.length" class="zonas__lista">
                    <li v-for="z in zones" :key="z.ulid" :class="{ 'zona--inactiva': !z.is_active }">
                        <form v-if="editingZone === z.ulid" class="zona__edicion" @submit.prevent="saveZone(z)">
                            <p v-if="updateZone.generalError.value" class="alert zona__error" role="alert">{{ updateZone.generalError.value }}</p>
                            <div class="field">
                                <label class="field__label" :for="`zona-nombre-${z.ulid}`">Nombre</label>
                                <input :id="`zona-nombre-${z.ulid}`" v-model="zoneDraft.name" class="input"
                                       :class="{ 'input--error': updateZone.fieldErrors.value.name }" type="text" maxlength="120" required />
                            </div>
                            <div class="field">
                                <label class="field__label" :for="`zona-costo-${z.ulid}`">Costo de envío</label>
                                <input :id="`zona-costo-${z.ulid}`" v-model="zoneDraft.cost" class="input"
                                       :class="{ 'input--error': updateZone.fieldErrors.value.cost }" type="text" inputmode="decimal" required />
                                <span class="field__hint">Aplica a los pedidos nuevos: los ya hechos conservan su costo de envío.</span>
                            </div>
                            <div class="zona__botones">
                                <button type="button" class="link-button" :disabled="updateZone.processing.value" @click="editingZone = null">
                                    <Icon name="x" /> Cancelar
                                </button>
                                <button type="submit" class="button" :disabled="updateZone.processing.value"><Icon name="check" /> Guardar</button>
                            </div>
                        </form>

                        <template v-else>
                            <span class="zona__dato">
                                {{ z.name }} — {{ formatMoney(z.cost) }}
                                <span v-if="!z.is_active" class="badge badge--off">Inactiva</span>
                            </span>
                            <span v-if="puedeEditarZonas" class="row-actions zona__botones">
                                <button type="button" class="link-button link-button--warning" :disabled="zoneBusy === z.ulid" @click="startZoneEdit(z)">
                                    <Icon name="edit" /> Editar
                                </button>
                                <!-- Sin rojo: desactivar se deshace con un clic. El rojo queda para quitar, que no se deshace. -->
                                <button type="button" class="link-button" :disabled="zoneBusy === z.ulid" @click="toggleZone(z)">
                                    {{ z.is_active ? 'Desactivar' : 'Activar' }}
                                </button>
                                <button type="button" class="link-button link-button--danger" :disabled="zoneBusy === z.ulid" @click="deleteZone(z)">
                                    <Icon name="trash" /> Quitar
                                </button>
                            </span>
                        </template>
                    </li>
                </ul>
                <p v-else-if="zonesLoaded" class="page-header__hint">Sin zonas. Con recoger en sucursal no hacen falta; para envío, agrega al menos una.</p>

                <form v-if="puedeEditarZonas" class="zonas__nueva" @submit.prevent="addZone">
                    <p v-if="createZone.generalError.value" class="alert zona__error" role="alert">{{ createZone.generalError.value }}</p>
                    <div class="field">
                        <label class="field__label" for="zona-nueva-nombre">Nueva zona</label>
                        <input id="zona-nueva-nombre" v-model="zoneForm.name" class="input"
                               :class="{ 'input--error': createZone.fieldErrors.value.name }" type="text" maxlength="120" placeholder="p. ej. Centro" required />
                    </div>
                    <div class="field">
                        <label class="field__label" for="zona-nueva-costo">Costo de envío</label>
                        <input id="zona-nueva-costo" v-model="zoneForm.cost" class="input"
                               :class="{ 'input--error': createZone.fieldErrors.value.cost }" type="text" inputmode="decimal" placeholder="0.00" required />
                    </div>
                    <button type="submit" class="button button--ghost" :disabled="createZone.processing.value"><Icon name="plus" /> Agregar zona</button>
                </form>
            </template>
        </fieldset>

        <section class="tarjeta bloque">
            <p class="page-header__hint">
                ¿Vendes también por DiDi Food, Uber Eats o Rappi? Los canales de marketplace se encienden y se mapean
                en su propia pantalla.
            </p>
            <Link href="/admin/canales" class="link-button">Configurar canales de marketplace →</Link>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.tienda {
    display: grid;
    gap: 1rem;
    max-width: 42rem;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
    border: 1px solid var(--color-borde);
}

.bloque legend {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-contenido);
    padding: 0 0.35rem;
}

.field {
    margin-bottom: 0;
}

.field--color .color {
    width: 3.5rem;
    height: 2.25rem;
    padding: 0.15rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    cursor: pointer;
}

.check {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.radio {
    display: flex;
    gap: 0.6rem;
    align-items: start;
    font-size: 0.9rem;
    color: var(--color-contenido);
    line-height: 1.45;
}
.radio input { margin-top: 0.2rem; flex: none; }

.acciones {
    display: flex;
    gap: 0.85rem;
    align-items: center;
}

.zonas__lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
    font-size: 0.9rem;
}

.zonas__lista li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem 0.75rem;
    /* En un teléfono, las acciones bajan debajo del nombre en lugar de desbordar la tarjeta. */
    flex-wrap: wrap;
}

/* Se atenúa el dato, no las acciones: «Activar» en una zona inactiva tiene que verse disponible. */
.zona--inactiva .zona__dato {
    opacity: 0.6;
}

.zona__dato {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.zona__botones {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

/* La edición en su renglón: ocupa todo el ancho, con los campos uno bajo otro. */
.zona__edicion {
    flex: 1 1 100%;
    display: grid;
    gap: 0.6rem;
    padding: 0.75rem;
    border: 1px solid var(--color-acento);
    border-radius: var(--radio);
}

.zona__edicion .zona__botones {
    justify-content: flex-end;
}

.bloque > .alert,
.zona__error {
    margin: 0;
}

.zonas__nueva .zona__error {
    flex: 1 1 100%;
}

.zonas__nueva {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.zonas__nueva .field {
    flex: 1 1 10rem;
}
</style>
