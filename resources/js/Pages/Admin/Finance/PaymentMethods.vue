<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError, getAllPages } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { pushToast } from '../../../stores/useToasts';
import { useAuthorization } from '../../../composables/useAuthorization';
import DataTable from '../../../components/DataTable.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Métodos de pago del negocio (§6.3, D232).
 *
 * ## En el orden de la caja
 *
 * El listado llega por `sort_order`, que es el orden de los botones de cobro: lo que aquí se ve primero es lo que el
 * cajero ve primero. El servidor ya lo ordena así y la pantalla no reordena nada.
 *
 * ## Las tres banderas, preguntadas en lenguaje llano
 *
 * Son OBLIGATORIAS al dar de alta un método propio y no tienen un valor por omisión razonable: si el negocio no dice
 * si su vale entra al cajón, el corte lo sumaría mal en una dirección u otra. Por eso se preguntan con «Sí / No» sin
 * nada marcado, y no con una casilla que en blanco significaría «no» sin que nadie lo haya decidido.
 *
 * ## Los del sistema
 *
 * Efectivo, tarjeta, transferencia y crédito del cliente nacen con el negocio. Admiten cambiar su orden y activarse o
 * desactivarse; no su nombre, código ni banderas, porque son la referencia con la que el corte agrupa el dinero. La
 * pantalla lo lee de `can_be_renamed`, resuelto en el servidor (D139), y el servidor tiene la última palabra: si algo
 * se cuela, responde 422 con el motivo y aquí se muestra tal cual.
 *
 * ## Sin borrar
 *
 * Un método se da de BAJA: los cobros lo citan, y un pago que no puede decir con qué se pagó no explica nada.
 */
const { canWrite } = useAuthorization();
const puedeAdministrar = computed(() => canWrite('finance.payment_methods.manage'));

const BANDERAS = [
    {
        key: 'affects_cash_drawer',
        pregunta: '¿El dinero entra al cajón?',
        columna: 'Cajón',
        si: 'El corte lo cuenta en el efectivo que debe haber en el cajón, como el efectivo.',
        no: 'Llega por otro lado —la terminal bancaria, el banco, la plataforma— y el corte no lo busca en el cajón.',
    },
    {
        key: 'requires_reference',
        pregunta: '¿Pide referencia al cobrar?',
        columna: 'Referencia',
        si: 'La caja no cierra el cobro sin un folio o número de operación, que es con lo que después se concilia.',
        no: 'El folio es opcional: se puede cobrar sin capturarlo.',
    },
    {
        key: 'allows_change',
        pregunta: '¿Da cambio?',
        columna: 'Cambio',
        si: 'El cliente puede entregar de más y la caja calcula el cambio, como con billetes.',
        no: 'Se cobra el importe exacto: no hay cambio que devolver.',
    },
];

const metodos = ref([]);
const cargando = ref(true);
const errorCarga = ref(null);
const errorAccion = ref(null);

/** El método que se está activando o desactivando: bloquea los botones para no mandar el cambio dos veces. */
const cambiando = ref(null);

onMounted(cargar);

async function cargar() {
    cargando.value = true;
    errorCarga.value = null;

    try {
        // Todas las páginas (el servidor corta en 100) y sin filtro de estado: los inactivos se listan para poder
        // reactivarlos.
        metodos.value = await getAllPages('/payment-methods');
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorCarga.value = e;
    } finally {
        cargando.value = false;
    }
}

/** El crédito del cliente, para el aviso: nace apagado y hay que encenderlo para poder fiar. */
const credito = computed(() => metodos.value.find((m) => m.kind === 'customer_credit') ?? null);

// ---- Alta y edición ----

const vacio = () => ({
    code: '',
    name: '',
    affects_cash_drawer: null,
    requires_reference: null,
    allows_change: null,
    sort_order: '',
});

const form = ref(vacio());
const formularioAbierto = ref(false);

/** El método en edición, o `null` si es alta. */
const editando = ref(null);

/** Un método del sistema sólo admite cambiar su orden (y su estado, que tiene su propia acción). */
const soloOrden = computed(() => editando.value !== null && editando.value.can_be_renamed === false);

const save = useApiForm(async () => {
    const orden = String(form.value.sort_order ?? '').trim();
    let cuerpo;

    if (editando.value && soloOrden.value) {
        cuerpo = {};
    } else {
        cuerpo = {
            name: form.value.name.trim(),
            affects_cash_drawer: form.value.affects_cash_drawer,
            requires_reference: form.value.requires_reference,
            allows_change: form.value.allows_change,
        };
    }

    // Tal como se tecleó: el servidor valida que sea un entero entre 0 y 9999. Vacío = no se toca (en el alta, el
    // servidor pone 100: al final de los botones).
    if (orden !== '') {
        cuerpo.sort_order = orden;
    }

    if (editando.value) {
        await api.patch(`/payment-methods/${editando.value.ulid}`, cuerpo);
    } else {
        await api.post('/payment-methods', { code: form.value.code.trim(), ...cuerpo });
    }

    const accion = editando.value ? 'update' : 'create';

    cerrarFormulario();
    await cargar();

    return accion;
}, { success: (accion) => ({ kind: accion, entity: 'Método de pago', gender: 'm' }) });

function limpiarErrores() {
    save.fieldErrors.value = {};
    save.generalError.value = null;
}

function nuevo() {
    editando.value = null;
    form.value = vacio();
    limpiarErrores();
    formularioAbierto.value = true;
}

function editar(metodo) {
    editando.value = metodo;
    form.value = {
        code: metodo.code,
        name: metodo.name,
        affects_cash_drawer: metodo.affects_cash_drawer,
        requires_reference: metodo.requires_reference,
        allows_change: metodo.allows_change,
        sort_order: metodo.sort_order ?? '',
    };
    limpiarErrores();
    formularioAbierto.value = true;
}

function cerrarFormulario() {
    formularioAbierto.value = false;
    editando.value = null;
    form.value = vacio();
}

// ---- Activar / desactivar ----

function textoCambio(metodo) {
    if (metodo.status === 'active') {
        return `¿Desactivar «${metodo.name}»? Deja de aparecer en la pantalla de cobro de todas las sucursales: nadie `
            + 'podrá cobrar con él, ni pagar con él un gasto fuera de caja, hasta que lo vuelvas a activar. Los cobros ya '
            + 'registrados con este método no cambian.';
    }

    if (metodo.kind === 'customer_credit') {
        return `¿Activar «${metodo.name}»? Aparecerá en la pantalla de cobro, y quien tenga el permiso de cobrar a `
            + 'crédito podrá fiar a los clientes que tengan el crédito habilitado en su ficha.';
    }

    return `¿Activar «${metodo.name}»? Aparecerá como opción en la pantalla de cobro de todas las sucursales.`;
}

async function alternar(metodo) {
    if (cambiando.value !== null || ! window.confirm(textoCambio(metodo))) {
        return;
    }

    cambiando.value = metodo.ulid;
    errorAccion.value = null;

    try {
        const { data } = await api.post(`/payment-methods/${metodo.ulid}/toggle`);

        pushToast(
            data.status === 'active' ? `«${data.name}» activado.` : `«${data.name}» desactivado.`,
            data.status === 'active' ? 'success' : 'danger',
        );

        await cargar();
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // El 409 del último método activo se muestra tal cual: dice qué pasaría y qué hacer antes.
        errorAccion.value = e.message;
    } finally {
        cambiando.value = null;
    }
}

const siNo = (valor) => (valor ? 'Sí' : 'No');

const columns = [
    { key: 'sort_order', label: 'Orden', width: '5rem', align: 'right' },
    { key: 'name', label: 'Método' },
    ...BANDERAS.map((b) => ({ key: b.key, label: b.columna, width: '7rem', align: 'center' })),
    { key: 'status', label: 'Estado', width: '8rem' },
    { key: 'actions', label: '', width: '14rem' },
];
</script>

<template>
    <Head title="Métodos de pago" />

    <div class="metodos animar-entrada">
        <ListHeader
            title="Métodos de pago"
            subtitle="Con qué se puede cobrar en la caja, en el orden en que salen los botones de cobro. Un método se desactiva; no se borra, porque los cobros ya registrados lo citan."
            :count="cargando ? null : metodos.length"
        >
            <template #action>
                <button v-if="puedeAdministrar" type="button" class="button" @click="nuevo">
                    <Icon name="plus" /> Nuevo método
                </button>
            </template>
        </ListHeader>

        <!-- El crédito del cliente nace apagado (D232): sin encenderlo aquí, en la caja no aparece y no se puede fiar. -->
        <p class="alert" :class="credito?.status === 'active' ? 'alert--ok' : 'alert--notice'" role="status">
            <strong>«{{ credito?.name ?? 'Crédito del cliente' }}» nace apagado.</strong>
            Para fiar en el POS hay que encenderlo aquí; además, la cuenta tiene que tener un cliente con crédito
            habilitado en su ficha, y quien cobra, el permiso de cobrar a crédito.
            <template v-if="credito">Hoy está <strong>{{ credito.status === 'active' ? 'encendido' : 'apagado' }}</strong>.</template>
        </p>

        <p v-if="errorAccion" class="alert" role="alert">{{ errorAccion }}</p>

        <section v-if="formularioAbierto" class="tarjeta bloque" aria-labelledby="metodo-titulo">
            <h2 id="metodo-titulo" class="bloque__titulo">
                {{ editando ? `Editar «${editando.name}»` : 'Nuevo método de pago' }}
            </h2>

            <p v-if="soloOrden" class="alert alert--notice" role="status">
                Es un método del sistema: sólo se cambia su lugar en la caja. Su nombre, su código y sus banderas son la
                referencia con la que el corte agrupa el dinero; si necesitas otro comportamiento, crea un método propio.
            </p>

            <p v-else-if="! editando" class="page-header__hint">
                Los métodos que das de alta son de tipo «Otro»: un vale de despensa, una aplicación de reparto, una
                tarjeta de regalo. Las tres preguntas de abajo son obligatorias, porque de ellas depende cómo cuadra el corte.
            </p>

            <form class="editor" @submit.prevent="save.submit()">
                <div class="editor__rejilla">
                    <div class="field">
                        <label class="field__label" for="metodo-codigo">Código</label>
                        <input
                            id="metodo-codigo"
                            v-model="form.code"
                            class="input input--codigo"
                            type="text"
                            maxlength="30"
                            pattern="[A-Za-z0-9_\-]+"
                            title="Sólo letras, números, guiones y guiones bajos."
                            autocomplete="off"
                            placeholder="p. ej. VALES"
                            :disabled="!! editando"
                            :required="! editando"
                            :aria-describedby="editando ? 'metodo-codigo-ayuda' : undefined"
                        />
                        <span v-if="editando" id="metodo-codigo-ayuda" class="field__hint">
                            El código no se cambia: es como lo agrupan los cortes.
                        </span>
                        <span v-if="save.fieldErrors.value.code" class="field__error">{{ save.fieldErrors.value.code }}</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="metodo-nombre">Nombre</label>
                        <input
                            id="metodo-nombre"
                            v-model="form.name"
                            class="input"
                            type="text"
                            maxlength="60"
                            autocomplete="off"
                            placeholder="p. ej. Vales de despensa"
                            :disabled="soloOrden"
                            required
                        />
                        <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
                    </div>

                    <div class="field">
                        <label class="field__label" for="metodo-orden">Orden en la caja</label>
                        <input
                            id="metodo-orden"
                            v-model="form.sort_order"
                            class="input"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            max="9999"
                            step="1"
                            :placeholder="editando ? '' : '100'"
                            aria-describedby="metodo-orden-ayuda"
                        />
                        <span id="metodo-orden-ayuda" class="field__hint">
                            Menor sale primero. Vacío en un método nuevo: al final.
                        </span>
                        <span v-if="save.fieldErrors.value.sort_order" class="field__error">
                            {{ save.fieldErrors.value.sort_order }}
                        </span>
                    </div>
                </div>

                <fieldset v-for="b in BANDERAS" :key="b.key" class="bandera" :disabled="soloOrden">
                    <legend class="field__label">{{ b.pregunta }}</legend>

                    <div class="bandera__opciones">
                        <label
                            :for="`metodo-${b.key}-si`"
                            class="opcion"
                            :class="{ 'opcion--activa': form[b.key] === true }"
                        >
                            <input
                                :id="`metodo-${b.key}-si`"
                                v-model="form[b.key]"
                                type="radio"
                                :name="`metodo-${b.key}`"
                                :value="true"
                                :required="! editando"
                            />
                            <span class="opcion__texto"><strong>Sí</strong><span>{{ b.si }}</span></span>
                        </label>

                        <label
                            :for="`metodo-${b.key}-no`"
                            class="opcion"
                            :class="{ 'opcion--activa': form[b.key] === false }"
                        >
                            <input
                                :id="`metodo-${b.key}-no`"
                                v-model="form[b.key]"
                                type="radio"
                                :name="`metodo-${b.key}`"
                                :value="false"
                            />
                            <span class="opcion__texto"><strong>No</strong><span>{{ b.no }}</span></span>
                        </label>
                    </div>

                    <span v-if="save.fieldErrors.value[b.key]" class="field__error">{{ save.fieldErrors.value[b.key] }}</span>
                </fieldset>

                <p v-if="save.generalError.value" class="alert" role="alert">{{ save.generalError.value }}</p>

                <div class="acciones">
                    <button type="button" class="link-button" :disabled="save.processing.value" @click="cerrarFormulario">
                        <Icon name="x" /> Cancelar
                    </button>
                    <button type="submit" class="button" :disabled="save.processing.value">
                        <Icon name="check" />
                        {{ save.processing.value ? 'Guardando…' : (editando ? 'Guardar cambios' : 'Dar de alta') }}
                    </button>
                </div>
            </form>
        </section>

        <DataTable
            :columns="columns"
            :rows="metodos"
            :loading="cargando"
            :error="errorCarga"
            empty-message="Todavía no hay métodos de pago."
        >
            <template #cell:sort_order="{ row }">{{ row.sort_order }}</template>

            <template #cell:name="{ row }">
                <div class="metodo">
                    <span class="metodo__nombre">
                        {{ row.name }}
                        <span v-if="row.is_system" class="badge badge--off">del sistema</span>
                    </span>
                    <span class="metodo__meta"><code>{{ row.code }}</code> · {{ row.kind_label }}</span>
                </div>
            </template>

            <template #cell:affects_cash_drawer="{ row }">{{ siNo(row.affects_cash_drawer) }}</template>
            <template #cell:requires_reference="{ row }">{{ siNo(row.requires_reference) }}</template>
            <template #cell:allows_change="{ row }">{{ siNo(row.allows_change) }}</template>

            <template #cell:status="{ row }">
                <span class="badge" :class="row.status === 'active' ? 'badge--ok' : 'badge--off'">
                    {{ row.status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </template>

            <template #cell:actions="{ row }">
                <div v-if="puedeAdministrar" class="row-actions">
                    <button type="button" class="link-button link-button--warning" @click="editar(row)">
                        <Icon name="edit" /> {{ row.can_be_renamed ? 'Editar' : 'Cambiar orden' }}
                    </button>
                    <button
                        type="button"
                        class="link-button"
                        :class="{ 'link-button--danger': row.status === 'active' }"
                        :disabled="cambiando !== null"
                        @click="alternar(row)"
                    >
                        <Icon :name="row.status === 'active' ? 'x' : 'check'" />
                        {{ row.status === 'active' ? 'Desactivar' : 'Activar' }}
                    </button>
                </div>
            </template>
        </DataTable>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.metodos {
    display: grid;
    gap: 1rem;
}

/* Dentro de la rejilla el `gap` ya separa: sin esto, el margen inferior de `.alert` se sumaría. */
.metodos > .alert {
    margin: 0;
}

.bloque {
    display: grid;
    gap: 0.85rem;
    padding: 1.15rem;
}

.bloque__titulo {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-contenido);
}

.bloque .alert,
.bloque .page-header__hint {
    margin: 0;
}

.editor {
    display: grid;
    gap: 0.95rem;
}

.editor__rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
    gap: 0.85rem;
}

.editor .field,
.editor .alert {
    margin: 0;
}

.input--codigo {
    text-transform: uppercase;
}

.bandera {
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.bandera__opciones {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
    gap: 0.6rem;
}

.opcion {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.opcion:hover {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
}

.opcion--activa {
    border-color: var(--color-acento);
    background: color-mix(in srgb, var(--color-acento) 6%, var(--color-superficie));
}

.bandera:disabled .opcion {
    cursor: not-allowed;
    opacity: 0.7;
}

.opcion input {
    margin-top: 0.15rem;
}

.opcion__texto {
    display: grid;
    gap: 0.15rem;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.opcion__texto span {
    font-size: 0.82rem;
    color: var(--color-suave);
    line-height: 1.4;
}

.acciones {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
}

.metodo {
    display: grid;
    gap: 0.15rem;
}

.metodo__nombre {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-weight: 500;
}

.metodo__meta {
    font-size: 0.8rem;
    color: var(--color-suave);
}

.metodo__meta code {
    font-size: 0.78rem;
    padding: 0.05rem 0.35rem;
    border-radius: var(--radio-sm);
    background: var(--color-fondo);
    color: var(--color-contenido);
}
</style>
