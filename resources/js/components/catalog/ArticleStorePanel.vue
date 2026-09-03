<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import Icon from '../../components/Icon.vue';

/**
 * Ajustes de tienda de un artículo (Iteración 8, Tanda B): lo EXCLUSIVO de la tienda —visibilidad, política de stock, SEO y
 * precio por canal—. La descripción y las fotos se editan en la pestaña «Publicación» (son compartidas con el menú).
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const POLICIES = {
    sell_always: 'Vender siempre (no bloquear por existencia)',
    hide: 'Ocultar si no hay existencia',
    mark_out_of_stock: 'Mostrar «agotado» si no hay existencia',
};

const form = ref({ is_in_store: false, stock_policy: 'sell_always', seo_title: '', seo_description: '', channel_price: '' });
const error = ref(null);
const saved = ref(false);
const saving = ref(false);

onMounted(async () => {
    const { data } = await api.get(`/articles/${props.article.ulid}/store-settings`);
    form.value = {
        is_in_store: data.is_in_store,
        stock_policy: data.stock_policy,
        seo_title: data.seo_title ?? '',
        seo_description: data.seo_description ?? '',
        channel_price: data.channel_price ?? '',
    };
});

async function save() {
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        await api.put(`/articles/${props.article.ulid}/store-settings`, {
            is_in_store: form.value.is_in_store,
            stock_policy: form.value.stock_policy,
            seo_title: form.value.seo_title || null,
            seo_description: form.value.seo_description || null,
            channel_price: form.value.channel_price === '' ? null : form.value.channel_price,
        });
        saved.value = true;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="store">
        <p class="muted small">Cómo aparece este artículo en la tienda en línea. No cambia el catálogo ni el POS.</p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="saved" class="ok">Guardado.</p>

        <label class="chk"><input v-model="form.is_in_store" type="checkbox" /> En la tienda</label>

        <label class="campo">Política de existencias
            <select v-model="form.stock_policy">
                <option v-for="(txt, key) in POLICIES" :key="key" :value="key">{{ txt }}</option>
            </select>
        </label>

        <label class="campo">Precio en la tienda (opcional)
            <input v-model="form.channel_price" type="text" inputmode="decimal" placeholder="Hereda el de la sucursal" />
        </label>

        <label class="campo">Título SEO
            <input v-model="form.seo_title" type="text" maxlength="160" />
        </label>
        <label class="campo">Descripción SEO
            <textarea v-model="form.seo_description" rows="2" maxlength="300"></textarea>
        </label>

        <button type="button" class="button" :disabled="saving" @click="save"><Icon name="check" /> Guardar</button>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.store { display: grid; gap: 0.75rem; max-width: 34rem; }
.muted { color: #78716c; }
.small { font-size: 0.85rem; }
.error { color: var(--color-peligro); }
.ok { color: var(--color-exito); }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.9rem; }
.campo { display: grid; gap: 0.25rem; font-size: 0.85rem; }
.campo input, .campo select, .campo textarea { font: inherit; padding: 0.35rem 0.5rem; }
</style>
