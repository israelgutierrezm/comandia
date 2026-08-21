<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';

/**
 * El editor del salón (ADR-003, §6.4).
 *
 * ## Se edita en memoria y se guarda ENTERO
 *
 * Arrastrar no escribe. Se mueven las mesas que haga falta y se guarda una vez, porque doce mesas movidas son un acto:
 * guardarlas de una en una dejaría el plano a medias si la quinta falla, y un salón a medias describe una distribución
 * que no existió nunca — las mesas se sitúan unas respecto de otras.
 *
 * ## El conflicto se enseña, no se resuelve solo
 *
 * Si alguien más guardó mientras tanto, el servidor responde 409 **con el plano actual**. La pantalla lo pinta y deja
 * elegir: descartar lo propio o volver a aplicarlo encima. Resolverlo automáticamente sería inventarse cuál de los dos
 * salones es el bueno, y ninguno de los dos gerentes sabría qué pasó con su trabajo.
 *
 * ## Y el borrador se marca
 *
 * Mientras haya cambios sin guardar la pantalla lo dice, porque el estado más peligroso de un editor es el que se ve
 * igual que el guardado.
 */
const props = defineProps({
    planUlid: { type: String, default: null },
});

const page = usePage();

const plan = ref(null);
const tables = ref([]);
const plans = ref([]);
const loading = ref(true);
const loadError = ref(null);
const selected = ref(null);
const dirty = ref(false);
const conflicto = ref(null);

/** El contexto de Inertia trae las llaves PLANAS, no la forma anidada del recurso de la API. */
const activeBranchUlid = computed(() => page.props.context?.branch_ulid ?? null);

onMounted(load);

async function load(ulid = null) {
    loading.value = true;
    loadError.value = null;

    try {
        const lista = await api.get('/floor-plans', {
            per_page: 50,
            ...(activeBranchUlid.value ? { branch: activeBranchUlid.value } : {}),
        });

        plans.value = lista.data;

        const objetivo = ulid
            ?? props.planUlid
            ?? plans.value.find((p) => p.is_default)?.ulid
            ?? plans.value[0]?.ulid;

        if (! objetivo) {
            plan.value = null;
            tables.value = [];

            return;
        }

        aplicar((await api.get(`/floor-plans/${objetivo}`)).data);
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

/** Reemplaza lo que hay en pantalla con lo que dice el servidor, y limpia el borrador. */
function aplicar(datos) {
    plan.value = datos;

    // Copia propia de la geometría: se edita en memoria, y mutar la respuesta del servidor haría imposible saber qué
    // se ha cambiado y qué no.
    tables.value = (datos.tables ?? []).map((m) => ({ ...m, geometry: { ...m.geometry } }));

    dirty.value = false;
    conflicto.value = null;
}

function mover({ ulid, x, y }) {
    const mesa = tables.value.find((m) => m.ulid === ulid);

    if (! mesa) {
        return;
    }

    mesa.geometry.x = x.toFixed(2);
    mesa.geometry.y = y.toFixed(2);
    dirty.value = true;
}

/** Los campos de la mesa seleccionada, que es como se hace lo que el ratón hace mal: girar y redimensionar. */
const mesaSeleccionada = computed(() => tables.value.find((m) => m.ulid === selected.value) ?? null);

function ajustar(campo, valor) {
    if (! mesaSeleccionada.value) {
        return;
    }

    mesaSeleccionada.value.geometry[campo] = valor;
    dirty.value = true;
}

const guardar = useApiForm(async () => {
    const cuerpo = {
        version: plan.value.version,
        canvas: { width: plan.value.canvas.width, height: plan.value.canvas.height },
        tables: tables.value.map((m) => ({
            ulid: m.ulid,
            zone_ulid: m.zone?.ulid,
            x: m.geometry.x,
            y: m.geometry.y,
            width: m.geometry.width,
            height: m.geometry.height,
            rotation: m.geometry.rotation,
            shape: m.geometry.shape,
        })),
    };

    try {
        aplicar((await api.put(`/floor-plans/${plan.value.ulid}/layout`, cuerpo)).data);
    } catch (e) {
        // El 409 no es un fallo: es otra persona trabajando. Se guarda su plano para poder enseñarlo.
        if (e instanceof ApiError && e.status === 409 && e.payload?.type === 'version_conflict') {
            conflicto.value = e.payload;

            return;
        }

        throw e;
    }
});

/** Descartar lo propio y quedarse con lo que hay. */
function aceptarDelOtro() {
    aplicar(conflicto.value.data);
}

/** Reaplicar lo propio encima de la versión nueva. Sigue siendo una decisión de quien edita, no del sistema. */
function reaplicar() {
    const version = conflicto.value.current_version;

    plan.value = { ...plan.value, version };
    conflicto.value = null;
    dirty.value = true;
}

const archivar = useApiForm(async () => {
    const mesa = mesaSeleccionada.value;
    const accion = mesa.is_archived ? 'restore' : 'archive';

    await api.post(`/restaurant-tables/${mesa.ulid}/${accion}`);
    await load(plan.value.ulid);
});

const crearZona = useApiForm(async () => {
    await api.post(`/floor-plans/${plan.value.ulid}/zones`, { name: nuevaZona.value });
    nuevaZona.value = '';
    await load(plan.value.ulid);
});

const nuevaZona = ref('');
</script>

<template>
    <Head title="Editor del salón" />

    <div class="editor">
        <header class="editor__cabecera">
            <h1>Editor del salón</h1>

            <div v-if="plans.length > 1" class="editor__planos">
                <label>
                    Plano
                    <select :value="plan?.ulid" @change="load($event.target.value)">
                        <option v-for="p in plans" :key="p.ulid" :value="p.ulid">
                            {{ p.name }}{{ p.is_default ? ' (por omisión)' : '' }}
                        </option>
                    </select>
                </label>
            </div>

            <p v-if="dirty" class="editor__borrador">Hay cambios sin guardar.</p>
        </header>

        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <p v-else-if="!plan" class="nota">
            Esta sucursal todavía no tiene un plano de salón. Créalo desde la pantalla de sucursales.
        </p>

        <template v-else>
            <!-- EL CONFLICTO. Se enseña y se decide; resolverlo solo sería inventarse cuál salón es el bueno. -->
            <section v-if="conflicto" class="conflicto">
                <h2>Alguien más guardó este plano</h2>

                <p>
                    {{ conflicto.title }} La versión que hay ahora es la {{ conflicto.current_version }}; tú venías de
                    la {{ plan.version }}.
                </p>

                <div class="conflicto__acciones">
                    <button type="button" @click="aceptarDelOtro">Quedarme con lo que hay</button>
                    <button type="button" @click="reaplicar">Volver a aplicar lo mío encima</button>
                </div>
            </section>

            <div class="editor__cuerpo">
                <FloorCanvas
                    :canvas="plan.canvas"
                    :tables="tables"
                    :selected="selected"
                    @select="selected = $event"
                    @move="mover"
                />

                <aside class="panel">
                    <h2>Salón</h2>

                    <label>
                        Ancho (cm)
                        <input
                            :value="plan.canvas.width"
                            type="text"
                            inputmode="decimal"
                            @input="plan.canvas.width = $event.target.value; dirty = true"
                        />
                    </label>

                    <label>
                        Alto (cm)
                        <input
                            :value="plan.canvas.height"
                            type="text"
                            inputmode="decimal"
                            @input="plan.canvas.height = $event.target.value; dirty = true"
                        />
                    </label>

                    <h2>Mesa</h2>

                    <p v-if="!mesaSeleccionada" class="nota">Toca una mesa para editarla.</p>

                    <template v-else>
                        <p class="mesa-actual">
                            <strong>{{ mesaSeleccionada.code }}</strong>
                            · {{ mesaSeleccionada.seats }} lugares
                        </p>

                        <label>
                            Ancho (cm)
                            <input
                                :value="mesaSeleccionada.geometry.width"
                                type="text"
                                inputmode="decimal"
                                @input="ajustar('width', $event.target.value)"
                            />
                        </label>

                        <label>
                            Alto (cm)
                            <input
                                :value="mesaSeleccionada.geometry.height"
                                type="text"
                                inputmode="decimal"
                                @input="ajustar('height', $event.target.value)"
                            />
                        </label>

                        <label>
                            Rotación (grados)
                            <input
                                :value="mesaSeleccionada.geometry.rotation"
                                type="text"
                                inputmode="decimal"
                                @input="ajustar('rotation', $event.target.value)"
                            />
                        </label>

                        <label>
                            Forma
                            <select
                                :value="mesaSeleccionada.geometry.shape"
                                @change="ajustar('shape', $event.target.value)"
                            >
                                <option value="rectangle">Rectangular</option>
                                <option value="circle">Redonda</option>
                            </select>
                        </label>

                        <p v-if="archivar.generalError.value" class="error">{{ archivar.generalError.value }}</p>

                        <button type="button" :disabled="archivar.processing.value" @click="archivar.submit()">
                            {{ mesaSeleccionada.is_archived ? 'Devolver al piso' : 'Retirar del piso' }}
                        </button>
                    </template>

                    <h2>Zonas</h2>

                    <ul class="zonas">
                        <li v-for="z in plan.zones" :key="z.ulid">{{ z.name }}</li>
                    </ul>

                    <form @submit.prevent="crearZona.submit()">
                        <label>
                            Nueva zona
                            <input v-model="nuevaZona" type="text" placeholder="Terraza" required />
                        </label>

                        <p v-if="crearZona.generalError.value" class="error">{{ crearZona.generalError.value }}</p>

                        <button type="submit" :disabled="crearZona.processing.value">Agregar</button>
                    </form>
                </aside>
            </div>

            <footer class="editor__pie">
                <p v-if="guardar.generalError.value" class="error">{{ guardar.generalError.value }}</p>

                <button type="button" :disabled="guardar.processing.value || !dirty" @click="guardar.submit()">
                    Guardar el salón
                </button>

                <button type="button" class="enlace" :disabled="!dirty" @click="load(plan.ulid)">
                    Descartar cambios
                </button>
            </footer>
        </template>
    </div>
</template>

<style scoped>
.editor { display: grid; gap: 1rem; }
.editor__cabecera { display: flex; gap: 1.5rem; align-items: baseline; flex-wrap: wrap; }
.editor__cabecera h1 { margin: 0; }
.editor__borrador { color: #a66; font-size: 0.9rem; margin: 0; }
.editor__cuerpo { display: grid; grid-template-columns: minmax(0, 1fr) 18rem; gap: 1rem; align-items: start; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 0.75rem 1rem; display: grid; gap: 0.5rem; }
.panel h2 { font-size: 0.95rem; margin: 0.5rem 0 0; }
.editor__pie { display: flex; gap: 1rem; align-items: center; }
.conflicto { border: 2px solid #e0a800; border-radius: 6px; padding: 0.75rem 1rem; background: #fffdf3; }
.conflicto h2 { margin-top: 0; }
.conflicto__acciones { display: flex; gap: 0.75rem; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
label { display: grid; gap: 0.2rem; font-size: 0.85rem; }
.zonas { margin: 0; padding-left: 1.1rem; font-size: 0.9rem; }
.mesa-actual { margin: 0; font-size: 0.9rem; }
.enlace { background: none; border: 0; color: #06c; cursor: pointer; }

@media (max-width: 60rem) {
    .editor__cuerpo { grid-template-columns: minmax(0, 1fr); }
}
</style>
