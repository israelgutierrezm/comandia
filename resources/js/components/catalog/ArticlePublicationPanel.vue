<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import Icon from '../../components/Icon.vue';

/**
 * Publicación de un artículo (Iteración 8, Tanda A): lo que la vitrina —menú y tienda— agrega al artículo del Core sin
 * duplicarlo: descripción larga, orden de aparición, visibilidad y galería de fotos. La capa vive en `Publishing`
 * (siempre disponible), así que esto sirve tanto para el menú como para la tienda.
 */
const props = defineProps({
    article: { type: Object, required: true },
});

const form = ref({ long_description: '', sort_order: 0, is_visible: true });
const images = ref([]);
const error = ref(null);
const saving = ref(false);
const uploading = ref(false);

onMounted(load);

async function load() {
    const { data } = await api.get(`/articles/${props.article.ulid}/publication`);
    form.value = {
        long_description: data.long_description ?? '',
        sort_order: data.sort_order ?? 0,
        is_visible: data.is_visible,
    };
    images.value = data.images ?? [];
}

async function save() {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.put(`/articles/${props.article.ulid}/publication`, {
            long_description: form.value.long_description || null,
            sort_order: Number(form.value.sort_order) || 0,
            is_visible: form.value.is_visible,
        });
        images.value = data.images ?? [];
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        saving.value = false;
    }
}

async function upload(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    uploading.value = true;
    error.value = null;

    try {
        // Multipart: el cliente JSON no sirve para archivos. Se manda con la cookie de sesión y el token CSRF; el rol y la
        // sucursal por omisión de la membresía los pone el servidor.
        const body = new FormData();
        body.append('image', file);

        const res = await fetch(`/api/v1/articles/${props.article.ulid}/publication/images`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body,
        });

        const payload = await res.json().catch(() => ({}));
        if (!res.ok) throw new ApiError({ ...payload, status: res.status });

        images.value = payload.data?.images ?? images.value;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        uploading.value = false;
        event.target.value = ''; // permite volver a subir el mismo archivo
    }
}

async function removeImage(ulid) {
    error.value = null;
    try {
        await api.delete(`/publication-images/${ulid}`);
        images.value = images.value.filter((img) => img.ulid !== ulid);
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    }
}
</script>

<template>
    <div class="pub">
        <p class="muted small">Lo que se muestra en el menú y la tienda. No cambia el catálogo ni el POS.</p>

        <p v-if="error" class="error">{{ error }}</p>

        <label class="campo">Descripción para la vitrina
            <textarea v-model="form.long_description" rows="3" maxlength="5000"
                placeholder="Se muestra bajo el nombre del platillo en el menú y la tienda."></textarea>
        </label>

        <div class="fila">
            <label class="campo">Orden
                <input v-model="form.sort_order" type="number" min="0" />
            </label>
            <label class="chk">
                <input v-model="form.is_visible" type="checkbox" /> Visible en la vitrina
            </label>
        </div>

        <button type="button" class="button" :disabled="saving" @click="save"><Icon name="check" /> Guardar</button>

        <h3>Fotos</h3>
        <p v-if="!images.length" class="muted small">Sin fotos todavía.</p>
        <ul v-else class="galeria">
            <li v-for="img in images" :key="img.ulid" class="foto">
                <img :src="img.url" :alt="img.alt_text ?? ''" />
                <button type="button" class="link-button link-button--danger" @click="removeImage(img.ulid)"><Icon name="trash" /> Quitar</button>
            </li>
        </ul>

        <label class="subir">
            <span>{{ uploading ? 'Subiendo…' : 'Agregar foto' }}</span>
            <input type="file" accept="image/jpeg,image/png,image/webp" :disabled="uploading" @change="upload" />
        </label>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.pub { display: grid; gap: 0.75rem; max-width: 40rem; }
.muted { color: #78716c; }
.small { font-size: 0.85rem; }
.error { color: #a11; }
.campo { display: grid; gap: 0.25rem; font-size: 0.85rem; }
.campo textarea, .campo input { font: inherit; padding: 0.35rem 0.5rem; }
.fila { display: flex; gap: 1.5rem; align-items: center; }
.chk { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; }
.galeria { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.75rem; }
.foto { display: grid; gap: 0.25rem; justify-items: center; }
.foto img { width: 6rem; height: 6rem; object-fit: cover; border-radius: 8px; border: 1px solid #e7e5e4; }
.subir { display: inline-flex; gap: 0.5rem; align-items: center; cursor: pointer; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #c2410c; cursor: pointer; font: inherit; font-size: 0.8rem; padding: 0; }
h3 { margin: 0.5rem 0 0; font-size: 1rem; }
</style>
