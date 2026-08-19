<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useApiForm } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';

/**
 * El documento de una recepción (D26, §3.2).
 *
 * Aquí vive la decisión, no la captura. **Confirmar** da entrada al inventario, fija el costo del artículo
 * y deja la observación de precio del proveedor — tres efectos en tres módulos, todos por evento (D208).
 * Por eso está en su propia pantalla y detrás de su propio permiso.
 *
 * ## Lo que la pantalla tiene que dejar claro
 *
 * 1. Que un borrador **no ha movido nada**, y que confirmarlo es irreversible en el sentido que importa: la
 *    corrección es una reversa, no una edición.
 * 2. Que el precio se captura **sin IVA** y que si el impuesto entra al costo lo decide la configuración
 *    del negocio (D206). Se muestra el criterio con el que se confirmó, porque sin él nadie puede explicar
 *    el costo de una recepción vieja.
 * 3. Que las dos cantidades del renglón —la capturada y la convertida— dicen cosas distintas: «3 cajas» es
 *    lo que dice la factura y «36 000 g» es lo que entró al inventario.
 */
const props = defineProps({
    receiptUlid: { type: String, required: true },
});

const receipt = ref(null);
const loading = ref(true);
const error = ref(null);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        receipt.value = (await api.get(`/purchase-receipts/${props.receiptUlid}`)).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const confirm = useApiForm(async () => {
    await api.post(`/purchase-receipts/${props.receiptUlid}/confirm`);
});

const cancel = useApiForm(async () => {
    await api.post(`/purchase-receipts/${props.receiptUlid}/cancel`);
});

const reverse = useApiForm(async () => {
    // Devuelve el RECURSO, no la respuesta completa: `useApiForm` propaga lo que el callback produce, así que dejarlo
    // envuelto obligaría a quien lo use a recordar el `.data`.
    return (await api.post(`/purchase-receipts/${props.receiptUlid}/reverse`)).data;
});

async function doConfirm() {
    if (!window.confirm(
        'Confirmar da entrada al inventario y fija el costo de los artículos. Después no se edita: se '
        + 'reversa. ¿Continuar?'
    )) {
        return;
    }

    if (await confirm.submit()) {
        await load();
    }
}

async function doCancel() {
    if (!window.confirm('¿Descartar este borrador? No ha movido nada, así que no queda rastro en el kardex.')) {
        return;
    }

    if (await cancel.submit()) {
        await load();
    }
}

async function doReverse() {
    if (!window.confirm(
        'La reversa saca del inventario la mercancía de esta recepción y queda como un documento nuevo '
        + 'enlazado a ésta. El costo capturado NO se borra. ¿Continuar?'
    )) {
        return;
    }

    const created = await reverse.submit();

    if (created) {
        // A la reversa: es un documento nuevo, no un estado distinto de éste.
        window.location.href = `/admin/recepciones/${created.ulid}`;
    }
}

/** Cuántos renglones llegaron al kardex. `null` mientras es borrador, porque ninguno debería haber llegado. */
const appliedLines = computed(() => {
    if (receipt.value === null || receipt.value.status !== 'confirmed') {
        return null;
    }

    return receipt.value.lines.filter((line) => line.was_applied).length;
});

const columns = [
    { key: 'article', label: 'Artículo' },
    { key: 'quantity', label: 'Capturado', width: '11rem' },
    { key: 'base', label: 'Al inventario', width: '11rem' },
    { key: 'unit_price', label: 'Precio s/IVA', width: '9rem' },
    { key: 'tax', label: 'IVA', width: '8rem' },
    { key: 'total', label: 'Total', width: '9rem' },
    { key: 'lot', label: 'Lote', width: '10rem' },
];
</script>

<template>
    <Head :title="receipt ? `Recepción ${receipt.folio}` : 'Recepción'" />

    <p class="breadcrumb">
        <Link href="/admin/recepciones" class="link-button">← Recepciones</Link>
    </p>

    <p v-if="loading" class="state">Cargando…</p>
    <p v-else-if="error" class="alert">{{ error }}</p>

    <template v-else-if="receipt">
        <header class="page-header">
            <div>
                <h1>
                    Recepción {{ receipt.folio }}
                    <span
                        class="badge"
                        :class="{
                            'badge--warn': receipt.status === 'draft',
                            'badge--ok': receipt.status === 'confirmed',
                            'badge--off': receipt.status === 'cancelled',
                        }"
                    >
                        {{ receipt.status_label }}
                    </span>
                </h1>

                <p v-if="receipt.is_reversal" class="page-header__hint">
                    Esta recepción <strong>reversa</strong> la
                    <Link :href="`/admin/recepciones/${receipt.reverses?.ulid}`">{{ receipt.reverses?.folio }}</Link>:
                    saca del inventario lo que aquélla metió.
                </p>

                <p v-else-if="receipt.reversed_by" class="page-header__hint">
                    Esta recepción ya fue <strong>reversada</strong> por la
                    <Link :href="`/admin/recepciones/${receipt.reversed_by.ulid}`">{{ receipt.reversed_by.folio }}</Link>.
                    Sigue confirmada: la corrección es un documento nuevo, no una edición de éste.
                </p>

                <p v-else-if="receipt.status === 'draft'" class="page-header__hint">
                    Este borrador <strong>no ha movido nada</strong>. Cuadra los totales con la factura antes
                    de confirmar.
                </p>
            </div>

            <div class="row-actions">
                <button
                    v-if="receipt.status === 'draft'"
                    v-can.write="'purchasing.receipts.confirm'"
                    class="button"
                    type="button"
                    :disabled="confirm.processing.value"
                    @click="doConfirm"
                >
                    Confirmar y dar entrada
                </button>

                <button
                    v-if="receipt.status === 'draft'"
                    v-can.write="'purchasing.receipts.create'"
                    class="link-button link-button--danger"
                    type="button"
                    @click="doCancel"
                >
                    Descartar borrador
                </button>

                <button
                    v-if="receipt.status === 'confirmed' && !receipt.is_reversal && !receipt.reversed_by"
                    v-can.write="'purchasing.receipts.confirm'"
                    class="link-button link-button--danger"
                    type="button"
                    :disabled="reverse.processing.value"
                    @click="doReverse"
                >
                    Reversar
                </button>
            </div>
        </header>

        <p v-if="confirm.generalError.value" class="alert">{{ confirm.generalError.value }}</p>
        <p v-if="cancel.generalError.value" class="alert">{{ cancel.generalError.value }}</p>
        <p v-if="reverse.generalError.value" class="alert">{{ reverse.generalError.value }}</p>

        <section class="facts">
            <div class="fact">
                <p class="fact__label">Proveedor</p>
                <p class="fact__value">{{ receipt.supplier?.name ?? '—' }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">Almacén</p>
                <p class="fact__value">{{ receipt.warehouse?.name ?? '—' }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">Recibida</p>
                <p class="fact__value">{{ receipt.received_at }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">Factura del proveedor</p>
                <p class="fact__value">
                    {{ receipt.supplier_document_number ?? '—' }}
                </p>
            </div>

            <div class="fact">
                <p class="fact__label">Capturó</p>
                <p class="fact__value">{{ receipt.created_by?.name ?? '—' }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">Confirmó</p>
                <p class="fact__value">{{ receipt.confirmed_by?.name ?? 'Sin confirmar' }}</p>
            </div>
        </section>

        <section v-if="receipt.status === 'confirmed'" class="totals">
            <div class="fact">
                <p class="fact__label">Subtotal</p>
                <p class="fact__value">{{ receipt.subtotal }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">IVA</p>
                <p class="fact__value">{{ receipt.tax_total }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">Total</p>
                <p class="fact__value fact__value--strong">{{ receipt.total }}</p>
            </div>

            <div class="fact">
                <p class="fact__label">El IVA en el costo</p>
                <!-- El criterio con el que se confirmó, congelado. Sin esto nadie puede explicar el costo de
                     una recepción vieja si el ajuste del negocio cambió después (D206). -->
                <p class="fact__value">
                    {{ receipt.vat_was_creditable ? 'No entró (acreditable)' : 'Sí entró (no acreditable)' }}
                </p>
            </div>

            <div v-if="appliedLines !== null" class="fact">
                <p class="fact__label">Aplicado al kardex</p>
                <p class="fact__value">
                    {{ appliedLines }} de {{ receipt.lines.length }}
                    <!-- Un renglón con cantidad y sin movimiento es una confirmación que se interrumpió. Se
                         dice, porque es el único síntoma visible de ese caso. -->
                    <small v-if="appliedLines < receipt.lines.length" class="warn">
                        · quedaron renglones sin aplicar
                    </small>
                </p>
            </div>
        </section>

        <DataTable
            :columns="columns"
            :rows="receipt.lines"
            :loading="false"
            :error="null"
            empty-message="Esta recepción no tiene renglones."
        >
            <template #cell:article="{ row }">
                {{ row.article?.name ?? '—' }}
            </template>

            <template #cell:quantity="{ row }">
                <!-- Lo que dice la factura: «3 cajas». -->
                {{ row.quantity }}
                <small v-if="row.presentation" class="muted">{{ row.presentation.name }}</small>
                <small v-else class="muted">{{ row.article?.base_unit_code }}</small>
            </template>

            <template #cell:base="{ row }">
                <!-- Lo que entró al inventario: «36 000 g». La primera explica la segunda, y es lo que alguien
                     necesita cuando el saldo no le cuadra con el papel. -->
                {{ row.quantity_in_base_unit }} {{ row.article?.base_unit_code }}
            </template>

            <template #cell:unit_price="{ row }">
                {{ row.unit_price }}
            </template>

            <template #cell:tax="{ row }">
                {{ row.line_tax }}
                <small class="muted">{{ row.tax_rate }} %</small>
            </template>

            <template #cell:total="{ row }">
                {{ row.line_total }}
            </template>

            <template #cell:lot="{ row }">
                <span v-if="row.lot_code">
                    {{ row.lot_code }}
                    <small v-if="row.expires_at" class="muted">vence {{ row.expires_at }}</small>
                </span>
                <span v-else class="muted">—</span>
            </template>
        </DataTable>
    </template>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.breadcrumb {
    margin: 0 0 0.5rem;
    font-size: 0.85rem;
}

.state {
    color: #6b7280;
}

.facts,
.totals {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.fact {
    padding: 0.55rem 0.85rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    background: #fff;
    min-width: 9rem;
}

.fact__label {
    margin: 0;
    font-size: 0.75rem;
    color: #6b7280;
}

.fact__value {
    margin: 0.15rem 0 0;
    font-weight: 500;
}

.fact__value--strong {
    font-weight: 700;
}

.muted {
    color: #6b7280;
    display: block;
    font-size: 0.78rem;
}

.warn {
    color: #b45309;
}
</style>
