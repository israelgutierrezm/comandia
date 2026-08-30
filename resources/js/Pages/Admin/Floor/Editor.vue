<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';

/**
 * El editor del salón (ADR-003, §6.4).
 *
 * ## Se edita en memoria y se guarda ENTERO
 *
 * Arrastrar y redimensionar no escriben. Se acomoda el salón y se guarda una vez, porque doce mesas movidas son un acto:
 * guardarlas de una en una dejaría el plano a medias si la quinta falla, y un salón a medias describe una distribución
 * que no existió nunca — las mesas se sitúan unas respecto de otras.
 *
 * ## Añadir una mesa SÍ escribe, y por eso guarda antes lo pendiente
 *
 * Dar de alta una mesa es crear una fila (`POST`) y colocarla (`PATCH`), no mover geometría en memoria: el guardado en
 * bloque sólo actualiza mesas que ya existen. Como esa alta recarga el plano desde el servidor, primero persiste lo que
 * se venía moviendo — si no, el alta borraría el acomodo sin guardar.
 *
 * ## El conflicto se enseña, no se resuelve solo
 *
 * Si alguien más guardó mientras tanto, el servidor responde 409 **con el plano actual**. La pantalla lo pinta y deja
 * elegir: descartar lo propio o volver a aplicarlo encima. Resolverlo automáticamente sería inventarse cuál de los dos
 * salones es el bueno, y ninguno de los dos gerentes sabría qué pasó con su trabajo.
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

/**
 * Los tamaños de mesa más comunes, en centímetros (ADR-003). Poner una mesa deja de ser «escribe 140 en ancho y 80 en
 * alto» y pasa a «pon una rectangular»: el mesero piensa en mesas, no en medidas. Los asientos son la sugerencia del
 * preset; se ajustan después si hace falta.
 */
const PRESETS = [
    { key: 'chica', label: 'Chica', width: 60, height: 60, shape: 'rectangle', seats: 2 },
    { key: 'estandar', label: 'Estándar', width: 80, height: 80, shape: 'rectangle', seats: 4 },
    { key: 'grande', label: 'Grande', width: 100, height: 100, shape: 'rectangle', seats: 6 },
    { key: 'rectangular', label: 'Rectangular', width: 140, height: 80, shape: 'rectangle', seats: 6 },
    { key: 'banquete', label: 'Banquete', width: 200, height: 90, shape: 'rectangle', seats: 8 },
    { key: 'redonda', label: 'Redonda', width: 90, height: 90, shape: 'circle', seats: 4 },
];

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

/** Redimensionar con el tirador de la esquina: el equivalente visual de teclear ancho y alto. */
function redimensionar({ ulid, width, height }) {
    const mesa = tables.value.find((m) => m.ulid === ulid);

    if (! mesa) {
        return;
    }

    mesa.geometry.width = Number(width).toFixed(2);
    mesa.geometry.height = Number(height).toFixed(2);
    dirty.value = true;
}

/** Los campos de la mesa seleccionada, que es como se hace lo que el ratón hace mal: girar y afinar medidas. */
const mesaSeleccionada = computed(() => tables.value.find((m) => m.ulid === selected.value) ?? null);

function ajustar(campo, valor) {
    if (! mesaSeleccionada.value) {
        return;
    }

    mesaSeleccionada.value.geometry[campo] = valor;
    dirty.value = true;
}

/** El cuerpo del guardado en bloque: canvas + geometría de cada mesa. Compartido por «Guardar» y el alta. */
function cuerpoLayout() {
    return {
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
}

/** Persiste el layout. Devuelve `true` si guardó; si hubo conflicto lo publica y devuelve `false`. */
async function persistirLayout() {
    try {
        aplicar((await api.put(`/floor-plans/${plan.value.ulid}/layout`, cuerpoLayout())).data);

        return true;
    } catch (e) {
        // El 409 no es un fallo: es otra persona trabajando. Se guarda su plano para poder enseñarlo.
        if (e instanceof ApiError && e.status === 409 && e.payload?.type === 'version_conflict') {
            conflicto.value = e.payload;

            return false;
        }

        throw e;
    }
}

const guardar = useApiForm(persistirLayout);

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

// ---------------------------------------------------------------- Añadir mesa

const agregando = ref(false);
const nuevaMesa = reactive({ zoneUlid: '', code: '', seats: 4, preset: 'estandar' });

/** El siguiente código libre estilo «M7»: mirando los que ya hay, no un contador que se desincroniza al retirar mesas. */
function siguienteCodigo() {
    const numeros = tables.value
        .map((m) => /^M?(\d+)$/i.exec(String(m.code)))
        .filter(Boolean)
        .map((coincidencia) => Number(coincidencia[1]));

    return `M${numeros.length ? Math.max(...numeros) + 1 : 1}`;
}

function abrirAgregar() {
    const preset = PRESETS.find((p) => p.key === 'estandar');

    nuevaMesa.zoneUlid = plan.value?.zones?.[0]?.ulid ?? '';
    nuevaMesa.preset = preset.key;
    nuevaMesa.seats = preset.seats;
    nuevaMesa.code = siguienteCodigo();

    agregando.value = true;
}

/** Al cambiar de preset, los asientos siguen la sugerencia del preset (el humano los ajusta si quiere). */
function elegirPreset(key) {
    const preset = PRESETS.find((p) => p.key === key);

    nuevaMesa.preset = key;
    nuevaMesa.seats = preset.seats;
}

const agregarMesa = useApiForm(async () => {
    const preset = PRESETS.find((p) => p.key === nuevaMesa.preset) ?? PRESETS[1];

    // El alta recarga el plano; primero se persiste el acomodo pendiente para no perderlo. Si eso choca, se enseña el
    // conflicto y el alta se pospone: no se puede colocar una mesa sobre un plano que ya no es el que se veía.
    if (dirty.value && ! (await persistirLayout())) {
        return;
    }

    const creada = (await api.post('/restaurant-tables', {
        floor_zone_ulid: nuevaMesa.zoneUlid,
        code: nuevaMesa.code,
        seats: nuevaMesa.seats,
        shape: preset.shape,
    })).data;

    // Nace 80×80 en la esquina (0,0). Se coloca en el centro con el tamaño del preset para que aparezca a la vista,
    // lista para arrastrar donde vaya.
    const x = Math.max(0, (Number(plan.value.canvas.width) - preset.width) / 2);
    const y = Math.max(0, (Number(plan.value.canvas.height) - preset.height) / 2);

    await api.patch(`/restaurant-tables/${creada.ulid}`, {
        x: x.toFixed(2),
        y: y.toFixed(2),
        width: preset.width,
        height: preset.height,
    });

    agregando.value = false;
    await load(plan.value.ulid);
    selected.value = creada.ulid;
});

// ---------------------------------------------------------------- Datos y retiro de la mesa

/** Nombre y asientos NO viajan en el guardado del layout (que es sólo geometría): se editan con su propio PATCH. */
const guardarDatos = useApiForm(async () => {
    const mesa = mesaSeleccionada.value;

    await api.patch(`/restaurant-tables/${mesa.ulid}`, {
        name: mesa.name ?? null,
        seats: Number(mesa.seats),
    });

    await load(plan.value.ulid);
    selected.value = mesa.ulid;
});

const archivar = useApiForm(async () => {
    const mesa = mesaSeleccionada.value;
    const accion = mesa.is_archived ? 'restore' : 'archive';

    await api.post(`/restaurant-tables/${mesa.ulid}/${accion}`);
    await load(plan.value.ulid);
});

// ---------------------------------------------------------------- Zonas

const nuevaZona = ref('');

const crearZona = useApiForm(async () => {
    await api.post(`/floor-plans/${plan.value.ulid}/zones`, { name: nuevaZona.value });
    nuevaZona.value = '';
    await load(plan.value.ulid);
});

// ---------------------------------------------------------------- Imprimir

/**
 * Imprimir el plano en una ventana propia.
 *
 * Se lleva el SVG tal como está en pantalla —así lo impreso es lo que se ve, sin un segundo render que pudiera diferir
 * (ADR-003)— más una lista de mesas. Se hace en ventana aparte para no pelear con el shell de administración: la
 * alternativa, ocultar media interfaz con `@media print`, es frágil y se rompe al menor cambio del layout.
 */
async function imprimir() {
    // El tirador de redimensionar sólo existe en la mesa seleccionada; se quita para que no salga un punto suelto en el
    // papel, y se restaura después.
    const previa = selected.value;
    selected.value = null;
    await nextTick();

    const svg = document.querySelector('.editor .lienzo');
    const ventana = window.open('', '_blank', 'width=1000,height=800');

    selected.value = previa;

    if (! svg || ! ventana) {
        return;
    }

    const filas = tables.value
        .filter((m) => ! m.is_archived)
        .sort((a, b) => String(a.code).localeCompare(String(b.code), 'es', { numeric: true }))
        .map((m) => `<tr><td>${m.code}</td><td>${m.name ?? ''}</td><td>${m.seats}</td>`
            + `<td>${m.zone?.name ?? '—'}</td>`
            + `<td>${m.geometry.shape === 'circle' ? 'Redonda' : 'Rectangular'}</td></tr>`)
        .join('');

    const totalMesas = tables.value.filter((m) => ! m.is_archived).length;
    const totalLugares = tables.value
        .filter((m) => ! m.is_archived)
        .reduce((suma, m) => suma + Number(m.seats || 0), 0);

    ventana.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8">
        <title>Plano — ${plan.value.name}</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: system-ui, sans-serif; color: #1a1a1a; margin: 24px; }
            h1 { font-size: 1.3rem; margin: 0 0 0.25rem; }
            .sub { color: #666; margin: 0 0 1rem; font-size: 0.9rem; }
            svg { width: 100%; height: auto; border: 1px solid #ccc; border-radius: 6px; }
            table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.85rem; }
            th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
            th { background: #f5f5f5; }
            @media print { body { margin: 0; } }
        </style></head><body>
        <h1>${plan.value.name}</h1>
        <p class="sub">${totalMesas} mesas · ${totalLugares} lugares · ${plan.value.canvas.width}×${plan.value.canvas.height} cm</p>
        ${svg.outerHTML}
        <table>
            <thead><tr><th>Código</th><th>Nombre</th><th>Lugares</th><th>Zona</th><th>Forma</th></tr></thead>
            <tbody>${filas}</tbody>
        </table>
    </body></html>`);

    ventana.document.close();
    ventana.focus();
    ventana.print();
}
</script>

<template>
    <Head title="Editor del salón" />

    <div class="editor">
        <header class="editor__cabecera">
            <div class="editor__titulo">
                <h1>Editor del salón</h1>
                <p v-if="dirty" class="editor__borrador">● Hay cambios sin guardar</p>
            </div>

            <label v-if="plans.length > 1" class="editor__planos">
                Plano
                <select :value="plan?.ulid" @change="load($event.target.value)">
                    <option v-for="p in plans" :key="p.ulid" :value="p.ulid">
                        {{ p.name }}{{ p.is_default ? ' (por omisión)' : '' }}
                    </option>
                </select>
            </label>
        </header>

        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <p v-else-if="!plan" class="nota">
            Esta sucursal todavía no tiene un plano de salón. Créalo desde la pantalla de sucursales.
        </p>

        <template v-else>
            <!-- BARRA DE HERRAMIENTAS. Botones con forma de botón: se ve qué es clickeable. -->
            <div class="barra">
                <button type="button" class="button" :disabled="!plan.zones?.length" @click="abrirAgregar">
                    + Añadir mesa
                </button>

                <button type="button" class="button button--ghost" @click="imprimir">Imprimir plano</button>

                <span class="barra__sep" />

                <button
                    type="button"
                    class="button"
                    :disabled="guardar.processing.value || !dirty"
                    @click="guardar.submit()"
                >
                    Guardar el salón
                </button>

                <button type="button" class="link-button" :disabled="!dirty" @click="load(plan.ulid)">
                    Descartar cambios
                </button>
            </div>

            <p v-if="!plan.zones?.length" class="nota">
                Crea una zona antes de añadir mesas: toda mesa vive en una zona del salón.
            </p>

            <p v-if="guardar.generalError.value" class="error">{{ guardar.generalError.value }}</p>

            <!-- EL ALTA. Elegir zona y preset; el código se sugiere y se puede cambiar. -->
            <section v-if="agregando" class="alta tarjeta">
                <h2>Añadir mesa</h2>

                <div class="alta__campos">
                    <label>
                        Zona
                        <select v-model="nuevaMesa.zoneUlid">
                            <option v-for="z in plan.zones" :key="z.ulid" :value="z.ulid">{{ z.name }}</option>
                        </select>
                    </label>

                    <label>
                        Código
                        <input v-model="nuevaMesa.code" type="text" maxlength="10" />
                    </label>

                    <label>
                        Lugares
                        <input v-model.number="nuevaMesa.seats" type="number" min="1" max="99" />
                    </label>
                </div>

                <fieldset class="presets">
                    <legend>Tamaño y forma</legend>

                    <button
                        v-for="p in PRESETS"
                        :key="p.key"
                        type="button"
                        class="preset"
                        :class="{ 'preset--activo': nuevaMesa.preset === p.key }"
                        @click="elegirPreset(p.key)"
                    >
                        <span class="preset__figura" :class="`preset__figura--${p.shape}`" aria-hidden="true" />
                        <span class="preset__nombre">{{ p.label }}</span>
                        <span class="preset__medida">{{ p.width }}×{{ p.height }}</span>
                    </button>
                </fieldset>

                <p v-if="agregarMesa.fieldErrors.value.code" class="error">{{ agregarMesa.fieldErrors.value.code }}</p>
                <p v-else-if="agregarMesa.generalError.value" class="error">{{ agregarMesa.generalError.value }}</p>

                <div class="alta__acciones">
                    <button
                        type="button"
                        class="button"
                        :disabled="agregarMesa.processing.value || !nuevaMesa.zoneUlid || !nuevaMesa.code"
                        @click="agregarMesa.submit()"
                    >
                        Crear mesa
                    </button>
                    <button type="button" class="link-button" @click="agregando = false">Cancelar</button>
                </div>
            </section>

            <!-- EL CONFLICTO. Se enseña y se decide; resolverlo solo sería inventarse cuál salón es el bueno. -->
            <section v-if="conflicto" class="conflicto">
                <h2>Alguien más guardó este plano</h2>

                <p>
                    {{ conflicto.title }} La versión que hay ahora es la {{ conflicto.current_version }}; tú venías de
                    la {{ plan.version }}.
                </p>

                <div class="conflicto__acciones">
                    <button type="button" class="button button--ghost" @click="aceptarDelOtro">
                        Quedarme con lo que hay
                    </button>
                    <button type="button" class="button" @click="reaplicar">Volver a aplicar lo mío encima</button>
                </div>
            </section>

            <div class="editor__cuerpo">
                <FloorCanvas
                    :canvas="plan.canvas"
                    :tables="tables"
                    :selected="selected"
                    @select="selected = $event"
                    @move="mover"
                    @resize="redimensionar"
                />

                <aside class="panel tarjeta">
                    <h2>Mesa</h2>

                    <p v-if="!mesaSeleccionada" class="nota">Toca una mesa para editarla, o arrástrala para moverla.</p>

                    <template v-else>
                        <p class="mesa-actual">
                            <strong>{{ mesaSeleccionada.code }}</strong>
                            <span v-if="mesaSeleccionada.is_archived" class="etiqueta-baja">retirada</span>
                        </p>

                        <label>
                            Nombre
                            <input
                                :value="mesaSeleccionada.name"
                                type="text"
                                maxlength="60"
                                placeholder="Opcional"
                                @input="mesaSeleccionada.name = $event.target.value"
                            />
                        </label>

                        <label>
                            Lugares
                            <input
                                :value="mesaSeleccionada.seats"
                                type="number"
                                min="1"
                                max="99"
                                @input="mesaSeleccionada.seats = $event.target.value"
                            />
                        </label>

                        <p v-if="guardarDatos.generalError.value" class="error">{{ guardarDatos.generalError.value }}</p>

                        <button
                            type="button"
                            class="button button--ghost"
                            :disabled="guardarDatos.processing.value"
                            @click="guardarDatos.submit()"
                        >
                            Guardar datos
                        </button>

                        <hr />

                        <p class="nota">Arrastra la esquina de la mesa para redimensionarla, o afina aquí:</p>

                        <div class="geo">
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
                                Rotación (°)
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
                        </div>

                        <p v-if="archivar.generalError.value" class="error">{{ archivar.generalError.value }}</p>

                        <button
                            type="button"
                            class="link-button link-button--danger"
                            :disabled="archivar.processing.value"
                            @click="archivar.submit()"
                        >
                            {{ mesaSeleccionada.is_archived ? 'Devolver al piso' : 'Retirar del piso' }}
                        </button>
                    </template>

                    <hr />

                    <h2>Salón</h2>

                    <div class="geo">
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
                    </div>

                    <hr />

                    <h2>Zonas</h2>

                    <ul class="zonas">
                        <li v-for="z in plan.zones" :key="z.ulid">{{ z.name }}</li>
                    </ul>

                    <form class="zona-nueva" @submit.prevent="crearZona.submit()">
                        <label>
                            Nueva zona
                            <input v-model="nuevaZona" type="text" placeholder="Terraza" required />
                        </label>

                        <p v-if="crearZona.generalError.value" class="error">{{ crearZona.generalError.value }}</p>

                        <button type="submit" class="button button--ghost" :disabled="crearZona.processing.value">
                            Agregar zona
                        </button>
                    </form>
                </aside>
            </div>
        </template>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.editor { display: grid; gap: 1rem; }

.editor__cabecera { display: flex; gap: 1.5rem; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
.editor__titulo { display: flex; flex-direction: column; gap: 0.15rem; }
.editor__cabecera h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }
.editor__borrador { color: var(--color-aviso); font-size: 0.85rem; margin: 0; font-weight: 500; }
.editor__planos { display: grid; gap: 0.2rem; font-size: 0.85rem; color: var(--color-suave); }

.barra {
    display: flex;
    gap: 0.6rem;
    align-items: center;
    flex-wrap: wrap;
    padding: 0.6rem 0.75rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
}
.barra__sep { flex: 1; }

.editor__cuerpo { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; gap: 1rem; align-items: start; }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
}

.panel { padding: 0.9rem 1.1rem; display: grid; gap: 0.55rem; }
.panel h2 { font-size: 0.95rem; margin: 0; font-weight: 650; }
.panel hr { border: 0; border-top: 1px solid var(--color-borde); margin: 0.35rem 0; }

.geo { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }

.alta { padding: 1rem 1.1rem; display: grid; gap: 0.75rem; }
.alta h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }
.alta__campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.6rem; }
.alta__acciones { display: flex; gap: 0.75rem; align-items: center; }

.presets { border: 0; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: 0.5rem; }
.presets legend { font-size: 0.8rem; color: var(--color-suave); padding: 0 0 0.35rem; }
.preset {
    display: grid;
    justify-items: center;
    gap: 0.25rem;
    padding: 0.6rem 0.5rem;
    font: inherit;
    cursor: pointer;
    background: var(--color-fondo);
    color: var(--color-contenido);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.preset:hover { border-color: var(--color-acento); }
.preset--activo { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }
.preset__figura { display: block; background: color-mix(in srgb, var(--color-acento) 22%, transparent); border: 1.5px solid var(--color-acento); }
.preset__figura--rectangle { width: 2.4rem; height: 1.5rem; border-radius: 0.2rem; }
.preset__figura--circle { width: 1.8rem; height: 1.8rem; border-radius: 50%; }
.preset__nombre { font-weight: 600; font-size: 0.85rem; }
.preset__medida { font-size: 0.72rem; color: var(--color-suave); font-variant-numeric: tabular-nums; }

.conflicto { border: 2px solid var(--color-aviso); border-radius: 0.6rem; padding: 0.9rem 1.1rem; background: color-mix(in srgb, var(--color-aviso) 8%, var(--color-superficie)); }
.conflicto h2 { margin-top: 0; }
.conflicto__acciones { display: flex; gap: 0.75rem; }

.mesa-actual { margin: 0; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
.etiqueta-baja { font-size: 0.72rem; color: var(--color-peligro); border: 1px solid currentColor; border-radius: 0.35rem; padding: 0 0.3rem; }

.nota { color: var(--color-suave); font-size: 0.85rem; margin: 0; }
.error { color: var(--color-peligro); font-size: 0.85rem; margin: 0; }

.zonas { margin: 0; padding-left: 1.1rem; font-size: 0.9rem; color: var(--color-contenido); }
.zona-nueva { display: grid; gap: 0.5rem; }

label { display: grid; gap: 0.2rem; font-size: 0.82rem; color: var(--color-suave); }
label input, label select { font: inherit; padding: 0.4rem 0.55rem; border: 1px solid var(--color-borde); border-radius: 0.45rem; background: var(--color-fondo); color: var(--color-contenido); }

@media (max-width: 60rem) {
    .editor__cuerpo { grid-template-columns: minmax(0, 1fr); }
}
</style>
