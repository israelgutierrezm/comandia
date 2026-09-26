<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';
import ArticlePicker from '../../../components/catalog/ArticlePicker.vue';

/**
 * Canales de marketplace (ADR-015): DiDi Food, Uber Eats y Rappi.
 *
 * Dos tareas en una pantalla: (1) encender/apagar y configurar cada canal por sucursal —con los datos que la
 * plataforma entrega al registrarse— y (2) mapear el menú de cada plataforma a los artículos de Comandia: sin
 * mapeo, un pedido entrante se rechaza. El backend decide todo; aquí sólo se captura y se muestra.
 */
const CANALES = [
    { value: 'didi_food', label: 'DiDi Food' },
    { value: 'uber_eats', label: 'Uber Eats' },
    { value: 'rappi', label: 'Rappi' },
];

const error = ref(null);
const branches = ref([]);

// La plataforma avisa de cada pedido a una dirección que el negocio registra con ella. Se arma con el slug de la
// tienda en línea (la misma superficie pública `/t/{slug}`), y sólo responde con la tienda encendida.
const store = ref(null);
const copiedChannel = ref(null);

function webhookUrl(channel) {
    return store.value?.slug ? `${window.location.origin}/t/${store.value.slug}/webhook/marketplace/${channel}` : null;
}

async function copyWebhook(channel) {
    const url = webhookUrl(channel);
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        copiedChannel.value = channel;
        setTimeout(() => {
            if (copiedChannel.value === channel) copiedChannel.value = null;
        }, 2000);
    } catch {
        // Sin permiso de portapapeles (o sin contexto seguro): la dirección sigue a la vista para copiarla a mano.
    }
}

// ---- Canales por sucursal ----
const channels = ref([]);
const loadingChannels = ref(true);
// Los tres secretos viajan vacíos cuando no se tocan: vacío = conservar el guardado (no vuelven del servidor).
const blankChannel = () => ({
    branch_ulid: '', channel: 'didi_food', is_active: true, external_store_id: '', commission_rate: '',
    api_key: '', api_secret: '', webhook_secret: '',
});
const channelForm = ref(blankChannel());
const editing = ref(null); // el canal en edición (para el rótulo y bloquear sucursal/canal), o null si es alta
const savingChannel = ref(false);
const channelSaved = ref(false);

// ---- Mapeo del menú ----
const mapChannel = ref('didi_food');
const maps = ref([]);
const loadingMaps = ref(true);
const mapForm = ref({ external_item_id: '', article: null });
const savingMap = ref(false);
const canMap = computed(() => mapForm.value.external_item_id.trim() !== '' && mapForm.value.article !== null);

function report(e) {
    if (e instanceof ApiError) error.value = e.title; else throw e;
}

onMounted(async () => {
    try {
        const [ctx, tienda] = await Promise.all([api.get('/context'), api.get('/store')]);
        branches.value = ctx.data.branches ?? [];
        store.value = tienda.data;
        await Promise.all([loadChannels(), loadMaps()]);
    } catch (e) {
        report(e);
    }
});

async function loadChannels() {
    loadingChannels.value = true;
    try {
        const { data } = await api.get('/delivery-channels');
        channels.value = data;
    } finally {
        loadingChannels.value = false;
    }
}

function editChannel(c) {
    editing.value = c;
    channelSaved.value = false;
    channelForm.value = {
        branch_ulid: c.branch_ulid,
        channel: c.channel,
        is_active: c.is_active,
        external_store_id: c.external_store_id ?? '',
        commission_rate: c.commission_rate ?? '',
        // Los secretos no vuelven del servidor: vacío = conservar el guardado.
        api_key: '',
        api_secret: '',
        webhook_secret: '',
    };
}

function resetChannel() {
    editing.value = null;
    channelForm.value = blankChannel();
}

async function saveChannel() {
    savingChannel.value = true;
    error.value = null;
    channelSaved.value = false;
    try {
        await api.put('/delivery-channels', channelForm.value);
        resetChannel();
        channelSaved.value = true;
        await loadChannels();
    } catch (e) {
        report(e);
    } finally {
        savingChannel.value = false;
    }
}

async function loadMaps() {
    loadingMaps.value = true;
    try {
        const { data } = await api.get('/marketplace-menu-maps', { channel: mapChannel.value });
        maps.value = data;
    } finally {
        loadingMaps.value = false;
    }
}

async function pickMapChannel(value) {
    mapChannel.value = value;
    mapForm.value = { external_item_id: '', article: null };
    error.value = null;
    try {
        await loadMaps();
    } catch (e) {
        report(e);
    }
}

async function saveMap() {
    if (!canMap.value) return;
    savingMap.value = true;
    error.value = null;
    try {
        await api.post('/marketplace-menu-maps', {
            channel: mapChannel.value,
            external_item_id: mapForm.value.external_item_id.trim(),
            article_ulid: mapForm.value.article.ulid,
        });
        mapForm.value = { external_item_id: '', article: null };
        await loadMaps();
    } catch (e) {
        report(e);
    } finally {
        savingMap.value = false;
    }
}

async function removeMap(m) {
    if (!window.confirm(`¿Quitar el mapeo de «${m.external_item_id}»? Los pedidos con ese ítem se rechazarán hasta volver a mapearlo.`)) {
        return;
    }
    error.value = null;
    try {
        await api.delete(`/marketplace-menu-maps/${m.ulid}`);
        await loadMaps();
    } catch (e) {
        report(e);
    }
}
</script>

<template>
    <Head title="Canales de marketplace" />

    <div class="canales animar-entrada">
        <ListHeader
            title="Canales de marketplace"
            subtitle="Recibe pedidos de DiDi Food, Uber Eats y Rappi: enciende cada canal por sucursal y mapea su menú a tus artículos."
        />

        <p v-if="error" class="alert" role="alert">{{ error }}</p>

        <!-- 1. Canales por sucursal -->
        <section class="tarjeta bloque">
            <h2 class="bloque__titulo">Canales por sucursal</h2>
            <p class="page-header__hint">
                Cada canal exige tu registro previo con la plataforma: te da el id de tu tienda, las credenciales de su
                API y el secreto con el que firma sus avisos. Sin eso, el canal no opera.
            </p>

            <p v-if="!store" class="alert alert--notice" role="status">
                Para recibir avisos de pedidos necesitas tu tienda en línea configurada: su dirección pública es la que
                registras en cada plataforma.
            </p>
            <p v-else-if="!store.is_active" class="alert alert--notice" role="status">
                Tu tienda en línea está apagada: mientras lo esté, las plataformas no pueden avisarte de sus pedidos.
            </p>

            <p v-if="loadingChannels" class="page-header__hint">Cargando…</p>
            <ul v-else-if="channels.length" class="filas">
                <li v-for="c in channels" :key="c.ulid" class="fila">
                    <div class="fila__info">
                        <strong>{{ c.channel_label }}</strong>
                        <span class="fila__meta">{{ c.branch_name }}</span>
                    </div>
                    <span class="badge" :class="c.is_active ? 'badge--ok' : 'badge--off'">{{ c.is_active ? 'Encendido' : 'Apagado' }}</span>
                    <span class="fila__meta">
                        {{ Number(c.commission_rate) > 0 ? `Comisión ${c.commission_rate}%` : 'Sin comisión' }}
                        · {{ c.has_api_key && c.has_api_secret ? 'Credenciales guardadas' : 'Sin credenciales' }}
                        · {{ c.has_webhook_secret ? 'Firma configurada' : 'Sin firma' }}
                    </span>
                    <button type="button" class="link-button fila__accion" @click="editChannel(c)"><Icon name="edit" /> Editar</button>
                    <div v-if="webhookUrl(c.channel)" class="fila__aviso">
                        <span class="fila__meta">Dirección de avisos:</span>
                        <code class="fila__url">{{ webhookUrl(c.channel) }}</code>
                        <button type="button" class="link-button" @click="copyWebhook(c.channel)">
                            {{ copiedChannel === c.channel ? 'Copiada' : 'Copiar' }}
                        </button>
                    </div>
                </li>
            </ul>
            <p v-else class="page-header__hint">Aún no configuras ningún canal.</p>

            <p v-if="channelSaved" class="alert alert--ok" role="status">Canal guardado.</p>

            <form class="editor" @submit.prevent="saveChannel">
                <p class="editor__titulo">
                    {{ editing ? `Editar ${editing.channel_label} · ${editing.branch_name}` : 'Agregar un canal' }}
                </p>
                <div class="editor__rejilla">
                    <div class="field">
                        <label class="field__label" for="canal-sucursal">Sucursal</label>
                        <select id="canal-sucursal" v-model="channelForm.branch_ulid" class="input" required :disabled="!!editing">
                            <option value="" disabled>Elige sucursal…</option>
                            <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="canal-canal">Canal</label>
                        <select id="canal-canal" v-model="channelForm.channel" class="input" :disabled="!!editing">
                            <option v-for="c in CANALES" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field__label" for="canal-store">Id de tu tienda en la plataforma</label>
                        <input id="canal-store" v-model="channelForm.external_store_id" class="input" type="text" maxlength="120" placeholder="p. ej. STORE-42" />
                    </div>
                    <div class="field">
                        <label class="field__label" for="canal-comision">Comisión de la plataforma (%)</label>
                        <input id="canal-comision" v-model="channelForm.commission_rate" class="input" type="text" inputmode="decimal" placeholder="p. ej. 20" />
                    </div>
                    <div class="field">
                        <label class="field__label" for="canal-api-key">Llave de la API</label>
                        <input
                            id="canal-api-key"
                            v-model="channelForm.api_key"
                            class="input"
                            type="password"
                            autocomplete="new-password"
                            maxlength="255"
                            :placeholder="editing?.has_api_key ? 'Guardada — déjala vacía para conservarla' : 'La da la plataforma al registrarte'"
                        />
                    </div>
                    <div class="field">
                        <label class="field__label" for="canal-api-secret">Secreto de la API</label>
                        <input
                            id="canal-api-secret"
                            v-model="channelForm.api_secret"
                            class="input"
                            type="password"
                            autocomplete="new-password"
                            maxlength="255"
                            :placeholder="editing?.has_api_secret ? 'Guardado — déjalo vacío para conservarlo' : 'Lo da la plataforma al registrarte'"
                        />
                    </div>
                    <div class="field editor__ancho">
                        <label class="field__label" for="canal-secreto">Secreto de firma del webhook</label>
                        <input
                            id="canal-secreto"
                            v-model="channelForm.webhook_secret"
                            class="input"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="editing?.has_webhook_secret ? 'Configurado — déjalo vacío para conservarlo' : 'Lo da la plataforma al registrarte'"
                        />
                    </div>
                </div>
                <label class="check"><input v-model="channelForm.is_active" type="checkbox" /> Canal encendido</label>
                <div class="acciones">
                    <button type="submit" class="button" :disabled="savingChannel">
                        {{ savingChannel ? 'Guardando…' : (editing ? 'Guardar cambios' : 'Agregar canal') }}
                    </button>
                    <button v-if="editing" type="button" class="link-button" @click="resetChannel">Cancelar</button>
                </div>
            </form>
        </section>

        <!-- 2. Mapeo del menú -->
        <section class="tarjeta bloque">
            <h2 class="bloque__titulo">Mapeo del menú</h2>
            <p class="page-header__hint">
                Cada ítem del menú de la plataforma debe apuntar a uno de tus artículos. Si llega un pedido con un ítem sin
                mapear, se rechaza.
            </p>

            <div class="filtros" role="tablist" aria-label="Canal">
                <button
                    v-for="c in CANALES"
                    :key="c.value"
                    type="button"
                    role="tab"
                    class="filtro"
                    :class="{ 'filtro--activo': mapChannel === c.value }"
                    :aria-selected="mapChannel === c.value"
                    @click="pickMapChannel(c.value)"
                >
                    {{ c.label }}
                </button>
            </div>

            <p v-if="loadingMaps" class="page-header__hint">Cargando…</p>
            <ul v-else-if="maps.length" class="filas">
                <li v-for="m in maps" :key="m.ulid" class="fila">
                    <code class="fila__externo">{{ m.external_item_id }}</code>
                    <span class="fila__flecha" aria-hidden="true">→</span>
                    <span class="fila__articulo">{{ m.article?.name }}</span>
                    <button type="button" class="link-button link-button--danger fila__accion" @click="removeMap(m)"><Icon name="trash" /> Quitar</button>
                </li>
            </ul>
            <p v-else class="page-header__hint">Sin ítems mapeados para este canal.</p>

            <form class="editor" @submit.prevent="saveMap">
                <p class="editor__titulo">Mapear un ítem</p>
                <div class="editor__rejilla">
                    <div class="field">
                        <label class="field__label" for="map-externo">Id del ítem en la plataforma</label>
                        <input id="map-externo" v-model="mapForm.external_item_id" class="input" type="text" maxlength="191" placeholder="p. ej. ITEM-1043" />
                    </div>
                    <div class="field">
                        <label class="field__label" for="map-articulo">Artículo de Comandia</label>
                        <div v-if="mapForm.article" class="elegido">
                            <span>{{ mapForm.article.name }}</span>
                            <button type="button" class="link-button" @click="mapForm.article = null">Cambiar</button>
                        </div>
                        <ArticlePicker
                            v-else
                            input-id="map-articulo"
                            capability="sellable"
                            :supply-hint="false"
                            placeholder="Buscar artículo vendible…"
                            @picked="(a) => (mapForm.article = a)"
                        />
                    </div>
                </div>
                <div class="acciones">
                    <button type="submit" class="button" :disabled="!canMap || savingMap">
                        {{ savingMap ? 'Mapeando…' : 'Mapear' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.canales {
    display: grid;
    gap: 1rem;
    max-width: 52rem;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
    border: 1px solid var(--color-borde);
}

.bloque__titulo {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-contenido);
}

.filas {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
}

.fila {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 0.55rem 0.75rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-superficie);
    font-size: 0.9rem;
}

.fila__info {
    display: grid;
    gap: 0.1rem;
    flex: 1 1 10rem;
}

.fila__meta {
    font-size: 0.82rem;
    color: var(--color-suave);
}

.fila__externo {
    font-size: 0.82rem;
    padding: 0.1rem 0.45rem;
    border-radius: var(--radio-sm);
    background: var(--color-fondo);
    color: var(--color-contenido);
}

.fila__flecha {
    color: var(--color-suave);
}

.fila__articulo {
    flex: 1 1 auto;
}

.fila__accion {
    margin-left: auto;
}

/* La dirección de avisos ocupa su propio renglón dentro de la fila: es larga y se copia, no se lee de reojo. */
.fila__aviso {
    flex: 1 1 100%;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.fila__url {
    min-width: 0;
    overflow-wrap: anywhere;
    font-size: 0.78rem;
    padding: 0.1rem 0.45rem;
    border-radius: var(--radio-sm);
    background: var(--color-fondo);
    color: var(--color-contenido);
}

.editor {
    display: grid;
    gap: 0.85rem;
    padding-top: 0.9rem;
    border-top: 1px solid var(--color-borde);
}

.editor__titulo {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-contenido);
}

.editor__rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
    gap: 0.85rem;
}

.editor__ancho {
    grid-column: 1 / -1;
}

.field {
    margin-bottom: 0;
}

.check {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.acciones {
    display: flex;
    gap: 0.85rem;
    align-items: center;
}

.elegido {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-fondo);
    font-size: 0.9rem;
}
</style>
