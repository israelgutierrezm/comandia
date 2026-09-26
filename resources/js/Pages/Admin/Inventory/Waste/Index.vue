<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError, orEmptyWhenForbidden } from '../../../../api/client';
import { useResourceList, useApiForm } from '../../../../stores/useResourceList';
import { formatInBranchTime } from '../../../../support/datetime';
import { formatMoney } from '../../../../support/money';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import PinAuthorizationDialog from '../../../../components/inventory/PinAuthorizationDialog.vue';
import LotSelect from '../../../../components/inventory/LotSelect.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * Mermas y su catálogo de motivos (D27, §6.2).
 *
 * ## El motivo es obligatorio, y por eso el catálogo vive en la misma pantalla
 *
 * Una merma sin motivo es una salida que nadie puede explicar. Y quien registra necesita poder crear el motivo que le
 * falta **en el momento en que le falta** (D171): obligarlo a pedirle a un gerente que dé de alta «se cayó al piso»
 * acabaría con todas las mermas bajo un motivo genérico, que es justo lo que D27 evita. Comparten permiso, así que
 * comparten pantalla.
 *
 * ## El 409 no es un error: es una firma pendiente
 *
 * Sobre el umbral del negocio, el servidor responde **409 `authorization_required`** con el permiso que hace falta. La
 * pantalla no lo pinta como fallo — abre el diálogo del PIN y reintenta con el token. Es la diferencia que D170 buscaba
 * al no usar 422: no hay nada que corregir en el formulario.
 */
const list = useResourceList('/articles', { initialFilters: { capability: 'inventoriable' } });

const page = usePage();

const reasons = ref([]);
const warehouses = ref([]);
const movements = ref([]);

/**
 * `true` cuando el catálogo de motivos llegó. Sin esto, el aviso «todavía no hay motivos» salía también cuando la carga
 * FALLÓ —y afirmaba algo que la pantalla no sabía—, y parpadeaba mientras la carga estaba en camino.
 */
const reasonsLoaded = ref(false);
const lookupError = ref(null);

/** El artículo cuyas mermas se están mostrando. `null` = todavía no se ha pedido ninguno. */
const recentArticle = ref(null);
const loadingRecent = ref(false);
const recentError = ref(null);

const registering = ref(false);
const form = ref({});
const managingReasons = ref(false);
const newReason = ref({ name: '', requires_evidence: false });

/** El 409 pendiente: qué permiso pide el servidor y por qué. `null` = no hay nada esperando firma. */
const pendingAuthorization = ref(null);

/**
 * Un solo `onMounted`, y el orden es deliberado.
 *
 * Había dos: uno cargaba motivos y almacenes, el otro la lista de artículos. El primero terminaba llamando a
 * `loadRecent()`, que lee la lista — así que leía una lista que el otro `onMounted` todavía estaba cargando. Es la misma
 * carrera de D220: no falla siempre, y no falla nunca en una prueba que no monta Vue.
 */
onMounted(async () => {
    await list.load();

    // Si esto falla se DICE: antes el error se perdía en la consola y la pantalla quedaba con el formulario sin motivos
    // ni almacenes, sin explicar por qué. Los almacenes, si el rol no puede verlos (403), se tratan como vacíos: el
    // historial de mermas sigue sirviendo aunque no se pueda registrar.
    try {
        const [, warehousesResponse] = await Promise.all([
            loadReasons(),
            orEmptyWhenForbidden(api.get('/warehouses', { status: 'active', per_page: 100 })),
        ]);

        // Sin el de tránsito: lo escriben sólo las transferencias (D190), y ofrecerlo daría un 422.
        warehouses.value = warehousesResponse.data.filter((w) => w.kind !== 'transit');
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        lookupError.value = e.title;
    }
});

/**
 * El catálogo COMPLETO, con los dados de baja: el cajón de motivos los lista para poder reactivarlos. El formulario de
 * merma ofrece sólo los activos (`activeReasons`) — registrar con uno dado de baja da 422.
 *
 * Antes la primera carga traía sólo activos y la recarga tras dar de baja traía todos, así que el selector de la merma
 * terminaba ofreciendo motivos que el servidor rechaza.
 */
async function loadReasons() {
    reasons.value = (await api.get('/waste-reasons', { only_active: 0 })).data;
    reasonsLoaded.value = true;
}

const activeReasons = computed(() => reasons.value.filter((r) => r.is_active !== false));

/**
 * Las mermas de UN artículo, leídas del kardex filtrado por tipo.
 *
 * No hay un endpoint «de mermas» y no hace falta: la merma es un movimiento con motivo (D168), así que el reporte es un
 * filtro sobre el kardex. Tenerlo como endpoint aparte habría creado una segunda cifra que reconciliar.
 *
 * ## Del artículo elegido, y no «del primero de la lista»
 *
 * La primera versión mostraba las del primer artículo del listado, que es el primero por orden alfabético y no le
 * interesa a nadie: el encabezado decía «mermas recientes» y enseñaba las de la cebolla. Ahora se muestran las del
 * artículo sobre el que se acaba de registrar, o las de aquel cuyo historial se pide. La lista general de mermas del
 * negocio es el reporte «Mermas», en Reportes.
 */
async function loadRecent(article) {
    if (!article) {
        movements.value = [];
        recentArticle.value = null;

        return;
    }

    // Se vacía ANTES de pedir: si no, el encabezado ya dice el artículo nuevo mientras la lista sigue mostrando las
    // mermas del anterior.
    recentArticle.value = article;
    movements.value = [];
    recentError.value = null;
    loadingRecent.value = true;

    try {
        const respuesta = await api.get(`/articles/${article.ulid}/kardex`, { kind: 'waste' });

        // Si mientras tanto se pidió otro artículo, esta respuesta ya no es la que se está mirando.
        if (recentArticle.value?.ulid === article.ulid) {
            movements.value = respuesta.data;
        }
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (recentArticle.value?.ulid === article.ulid) {
            recentError.value = e.title;
        }
    } finally {
        if (recentArticle.value?.ulid === article.ulid) {
            loadingRecent.value = false;
        }
    }
}

/** La fecha de la merma en la hora de la sucursal activa: el recorte del ISO en UTC podía mostrar el día siguiente. */
function fechaMerma(iso) {
    return formatInBranchTime(iso, page.props.context?.branch_timezone) || '—';
}

/**
 * Errores del formulario de merma, en refs propios y no con `useApiForm`.
 *
 * La razón: `useApiForm` mete cualquier respuesta que no sea de validación en `generalError`, y ahí un
 * `authorization_required` se vería como un mensaje rojo — cuando lo que el servidor está diciendo es «falta una
 * firma». Distinguirlo exige hacer la petición a mano.
 */
const wasteErrors = ref({});
const wasteError = ref(null);
const submitting = ref(false);

const saveReason = useApiForm(async () => {
    await api.post('/waste-reasons', {
        name: newReason.value.name,
        requires_evidence: newReason.value.requires_evidence,
    });
});

/**
 * El catálogo de motivos empieza VACÍO, y eso deja la pantalla inservible hasta que alguien crea el primero.
 *
 * No es un descuido del alta: los motivos son del negocio (D27) y sembrarlos con una lista genérica sería inventar sus
 * categorías de pérdida. Pero sin ninguno, el formulario abría con el «Motivo» en blanco y enviar daba un 422 sobre un
 * campo que no se podía llenar. Así lo encontré en el navegador, en un negocio recién sembrado.
 */
const hasReasons = computed(() => activeReasons.value.length > 0);

function startRegister(article) {
    form.value = {
        article,
        warehouse_ulid: warehouses.value[0]?.ulid ?? '',
        waste_reason_ulid: activeReasons.value[0]?.ulid ?? '',
        lot_ulid: '',
        quantity: '',
        notes: '',
    };

    wasteErrors.value = {};
    wasteError.value = null;
    registering.value = true;

    loadWasteLots(article);
}

/**
 * Los lotes del artículo, para decir de cuál se perdió la mercancía. Sólo si el artículo lleva lotes.
 *
 * Sin elegir, la merma sale como cualquier salida: primero lo que caduca (FEFO). Pero un lote marcado CADUCADO ya no
 * surte, así que la salida automática nunca lo toca: su existencia sólo se puede dar de baja eligiéndolo aquí. Sin este
 * selector, «marcar caducado» dejaba mercancía en el sistema que ninguna pantalla podía mermar (D166).
 *
 * Un 403 en cualquiera de las dos lecturas (piden «Ver existencias») sólo deja el selector en automático.
 */
const wasteLots = ref([]);
const wasteStocks = ref([]);
const wasteLotsError = ref(null);

async function loadWasteLots(article) {
    wasteLots.value = [];
    wasteStocks.value = [];
    wasteLotsError.value = null;

    if (!article?.tracks_lots) {
        return;
    }

    try {
        const [lotes, saldos] = await Promise.all([
            orEmptyWhenForbidden(api.get(`/articles/${article.ulid}/lots`)),
            orEmptyWhenForbidden(api.get(`/articles/${article.ulid}/stock`)),
        ]);

        // Si mientras tanto se abrió la merma de otro artículo, esta respuesta ya no es la que se está mirando.
        if (form.value.article?.ulid !== article.ulid) {
            return;
        }

        wasteLots.value = lotes.data ?? [];
        wasteStocks.value = saldos.data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (form.value.article?.ulid === article.ulid) {
            wasteLotsError.value = e.message;
        }
    }
}

/**
 * Envía la merma, y si el servidor pide firma abre el diálogo del PIN.
 *
 * El token NO se pide antes: una concesión es de un solo uso y se gasta al consumirla, así que pedirla «por si acaso»
 * desperdiciaría la que el usuario acababa de conseguir para otra cosa. Se intenta, y el 409 dice si hace falta.
 */
async function trySubmit(authorizationToken = null) {
    submitting.value = true;
    wasteError.value = null;
    wasteErrors.value = {};

    try {
        await api.post('/waste', {
            warehouse_ulid: form.value.warehouse_ulid,
            article_ulid: form.value.article.ulid,
            waste_reason_ulid: form.value.waste_reason_ulid,
            // Vacío = automático (FEFO). Un lote elegido gana: quien lo elige está viendo la caja que se tira.
            lot_ulid: form.value.lot_ulid || null,
            quantity: form.value.quantity,
            notes: form.value.notes || null,
            authorization_token: authorizationToken,
        });

        registering.value = false;
        pendingAuthorization.value = null;

        // Se muestran las de ESTE artículo, que es lo que quien acaba de registrar quiere confirmar.
        await loadRecent(form.value.article);
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // EL caso que hace distinta esta pantalla: no es un error, es una firma pendiente. El permiso viene en la
        // respuesta, así que la pantalla no lleva su propia tabla de «qué permiso pide cada operación» (D170).
        if (e.isAuthorizationRequired) {
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message };

            return;
        }

        if (e.isValidation) {
            wasteErrors.value = e.fieldErrors;
        } else {
            wasteError.value = e.message;
        }
    } finally {
        submitting.value = false;
    }
}

async function onGranted(token) {
    // Con la firma en la mano, se reintenta la MISMA merma. El diálogo se cierra al tener éxito.
    await trySubmit(token);
}

/** Error del cajón de motivos (dar de baja, reactivar o recargar el catálogo). */
const reasonError = ref(null);

/** El motivo que se está dando de baja o reactivando: bloquea los botones para no mandar el cambio dos veces. */
const togglingReason = ref(null);

async function submitReason() {
    if (await saveReason.submit()) {
        newReason.value = { name: '', requires_evidence: false };
        reasonError.value = null;

        try {
            await loadReasons();
        } catch (e) {
            if (!(e instanceof ApiError)) {
                throw e;
            }

            reasonError.value = e.title;
        }
    }
}

async function toggleReason(reason) {
    if (togglingReason.value !== null) {
        return;
    }

    togglingReason.value = reason.ulid;
    reasonError.value = null;

    try {
        await api.patch(`/waste-reasons/${reason.ulid}`, {
            status: reason.is_active ? 'inactive' : 'active',
        });

        await loadReasons();
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        reasonError.value = e.title;
    } finally {
        togglingReason.value = null;
    }
}

const columns = [
    { key: 'name', label: 'Artículo' },
    { key: 'unit', label: 'Unidad', width: '7rem' },
    { key: 'actions', label: '', width: '9rem' },
];
</script>

<template>
    <Head title="Mermas" />

    <ListHeader
        title="Mermas"
        subtitle="El motivo es obligatorio: una merma sin motivo es una salida que nadie puede explicar. Sobre el monto que el negocio configuró, hace falta el PIN de un superior — y eso no es un error del formulario, es una firma pendiente."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar artículo…"
    >
        <template #action>
            <button v-can.write="'inventory.waste.create'" class="button" type="button" @click="managingReasons = true">
                Motivos de merma
            </button>
        </template>
    </ListHeader>

    <p v-if="lookupError" class="alert" role="alert">
        No se pudieron cargar los motivos de merma o los almacenes, así que por ahora no se puede registrar una merma.
        Detalle: {{ lookupError }}
    </p>

    <p v-if="reasonsLoaded && !hasReasons" class="alert alert--notice">
        Todavía no hay <strong>motivos de merma</strong>, y sin motivo no se puede registrar ninguna: da de alta al menos
        uno en «Motivos de merma». Los motivos son de tu negocio — el sistema no los inventa, porque «merma» sin decir
        de qué sirve tan poco como no registrarla.
    </p>


    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay artículos inventariables que coincidan."
    >
        <template #cell:unit="{ row }">
            {{ row.base_unit?.code ?? '—' }}
        </template>

        <template #cell:actions="{ row }">
            <button
                v-can.write="'inventory.waste.create'"
                class="link-button"
                type="button"
                :disabled="!hasReasons"
                :title="hasReasons ? '' : 'Primero da de alta al menos un motivo de merma.'"
                @click="startRegister(row)"
            ><Icon name="plus" /> Registrar</button>
            <button class="link-button" type="button" @click="loadRecent(row)"><Icon name="eye" /> Ver mermas</button>
        </template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="artículos" />

    <section v-if="recentArticle" class="recent">
        <h2>Mermas de {{ recentArticle.name }}</h2>

        <!-- Mientras carga, nada; si falló, el error — nunca «no tiene mermas», que afirmaría algo que no se sabe. -->
        <template v-if="loadingRecent"></template>
        <p v-else-if="recentError" class="alert">{{ recentError }}</p>
        <p v-else-if="movements.length === 0" class="muted">Este artículo no tiene mermas registradas.</p>

        <ul v-else class="recent__list">
            <li v-for="movement in movements" :key="movement.ulid">
                <strong>{{ movement.quantity }}</strong>
                · {{ movement.waste_reason?.name ?? '—' }}
                · {{ movement.warehouse?.name }}
                <template v-if="movement.lot"> · lote {{ movement.lot.code }}</template>
                · {{ fechaMerma(movement.occurred_at) }}
                <small v-if="movement.total_cost">({{ formatMoney(movement.total_cost) }})</small>
            </li>
        </ul>
    </section>

    <!-- Registro de la merma -->
    <div v-if="registering" class="drawer-backdrop" @click.self="registering = false">
        <form class="drawer" @submit.prevent="trySubmit()">
            <FormHeader :title="`Merma de ${form.article?.name ?? ''}`" />

            <p v-if="wasteError" class="alert">{{ wasteError }}</p>

            <label class="field">
                <span class="field__label">Almacén</span>
                <select v-model="form.warehouse_ulid" class="input" required>
                    <option v-for="warehouse in warehouses" :key="warehouse.ulid" :value="warehouse.ulid">
                        {{ warehouse.name }}
                    </option>
                </select>
            </label>

            <label class="field">
                <span class="field__label">Motivo</span>
                <select v-model="form.waste_reason_ulid" class="input" required>
                    <!-- Sólo los activos: un motivo dado de baja se conserva para las mermas viejas, pero ya no se usa. -->
                    <option v-for="reason in activeReasons" :key="reason.ulid" :value="reason.ulid">
                        {{ reason.name }}
                    </option>
                </select>
                <span class="field__hint">
                    Si falta el motivo que necesitas, créalo en «Motivos de merma»: sin el motivo correcto, el reporte
                    de pérdidas no sirve para nada.
                </span>
                <span v-if="wasteErrors.waste_reason_ulid" class="field__error">
                    {{ wasteErrors.waste_reason_ulid }}
                </span>
            </label>

            <div v-if="form.article?.tracks_lots" class="field">
                <label class="field__label" for="merma-lote">Lote</label>
                <LotSelect
                    id="merma-lote"
                    v-model="form.lot_ulid"
                    :lots="wasteLots"
                    :stocks="wasteStocks"
                    :warehouse-ulid="form.warehouse_ulid"
                    include-inactive
                    empty-label="Automático: primero lo que caduca"
                    :unit="form.article?.base_unit?.code ?? ''"
                />
                <span class="field__hint">
                    Elige el lote si sabes de cuál se perdió. Un lote caducado sólo se da de baja así: la salida
                    automática ya no lo toma.
                </span>
                <span v-if="wasteLotsError" class="field__error">No se pudieron cargar los lotes: {{ wasteLotsError }}</span>
                <span v-if="wasteErrors.lot_ulid" class="field__error">{{ wasteErrors.lot_ulid }}</span>
            </div>

            <label class="field">
                <span class="field__label">Cantidad ({{ form.article?.base_unit?.code }})</span>
                <input v-model="form.quantity" class="input" required />
                <span v-if="wasteErrors.quantity" class="field__error">
                    {{ wasteErrors.quantity }}
                </span>
            </label>

            <label class="field">
                <span class="field__label">Notas</span>
                <textarea v-model="form.notes" class="input" rows="2" maxlength="200"></textarea>
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="registering = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="submitting"><Icon name="plus" /> Registrar merma</button>
            </div>
        </form>
    </div>

    <!-- La firma que el servidor pidió con un 409 -->
    <PinAuthorizationDialog
        v-if="pendingAuthorization"
        :required-permission="pendingAuthorization.permission"
        :reason="pendingAuthorization.reason"
        @granted="onGranted"
        @cancelled="pendingAuthorization = null"
    />

    <!-- Catálogo de motivos -->
    <div v-if="managingReasons" class="drawer-backdrop" @click.self="managingReasons = false">
        <div class="drawer">
            <h2>Motivos de merma</h2>

            <p class="field__hint">
                Un motivo se da de <strong>baja</strong>, no se borra: las mermas que lo citan tienen que poder seguir
                diciendo por qué se perdió aquella mercancía.
            </p>

            <p v-if="reasonError" class="alert">{{ reasonError }}</p>

            <ul class="reasons">
                <li v-for="reason in reasons" :key="reason.ulid">
                    <span>
                        {{ reason.name }}
                        <!-- Los motivos del sistema no se editan: su nombre es lo que hace legible el reporte (D186). -->
                        <small v-if="reason.is_system" class="badge badge--off">del sistema</small>
                        <small v-if="reason.requires_evidence" class="badge badge--warn">pide evidencia</small>
                        <small v-if="reason.is_active === false" class="badge badge--off">de baja</small>
                    </span>

                    <button
                        v-if="!reason.is_system"
                        class="link-button"
                        type="button"
                        :disabled="togglingReason !== null"
                        @click="toggleReason(reason)"
                    >
                        {{ reason.is_active ? 'Dar de baja' : 'Reactivar' }}
                    </button>
                </li>
            </ul>

            <form class="reason-form" @submit.prevent="submitReason">
                <p v-if="saveReason.generalError.value" class="alert">{{ saveReason.generalError.value }}</p>

                <label class="field">
                    <span class="field__label">Nuevo motivo</span>
                    <input v-model="newReason.name" class="input" maxlength="80" required placeholder="Se cayó al piso" />
                    <span v-if="saveReason.fieldErrors.value.name" class="field__error">
                        {{ saveReason.fieldErrors.value.name }}
                    </span>
                </label>

                <label class="checkbox">
                    <input v-model="newReason.requires_evidence" type="checkbox" />
                    <span>Exigir evidencia fotográfica</span>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="managingReasons = false"><Icon name="x" /> Cerrar</button>
                    <button type="submit" class="button" :disabled="saveReason.processing.value"><Icon name="plus" /> Agregar</button>
                </div>
            </form>
        </div>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.muted {
    color: var(--color-suave);
    font-size: 0.9rem;
}

.recent {
    margin-top: 1.25rem;
}

.recent h2 {
    font-size: 0.95rem;
    margin: 0 0 0.4rem;
}

.recent__list,
.reasons {
    list-style: none;
    margin: 0;
    padding: 0;
}

.recent__list li,
.reasons li {
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--color-fondo);
    font-size: 0.9rem;
}

.reasons li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
}

.reason-form {
    margin-top: 0.9rem;
    padding-top: 0.9rem;
    border-top: 1px solid var(--color-borde);
}

.checkbox {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
    margin-bottom: 0.6rem;
}
</style>
