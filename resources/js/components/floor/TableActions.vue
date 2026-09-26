<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';

/**
 * Las operaciones de una mesa durante el servicio: liberarla, unirle otras y separarlas (§6.4, D32).
 *
 * ## Un solo permiso, y es de piso
 *
 * Las tres van con `floor.tables.join`, que es de quien ATIENDE y no de quien configura el salón: las hace el mesero
 * cuando llegan ocho o cuando una mesa se quedó marcada sin nadie. Sin ese permiso —o con el negocio en sólo lectura—
 * este bloque no se pinta.
 *
 * ## Qué se ofrece, según la mesa
 *
 * - **Liberar** (o **marcar limpia**, si espera limpieza) sólo cuando la mesa no está libre y no tiene cuenta encima. Con
 *   cuenta abierta el servidor responde 409 —la cuenta quedaría huérfana y el siguiente cliente se sentaría encima—, así
 *   que ni se ofrece: esa mesa se libera cobrando o cancelando su cuenta.
 * - **Unir** a una mesa que no cuelga de otra: queda como principal y el grupo se atiende con SU cuenta.
 * - **Separar** deshace la unión COMPLETA de la principal: el servidor no suelta mesas de una en una, y pedirlo sobre
 *   una mesa unida respondería 200 sin separar nada. Por eso desde una unida se pide sobre su principal, y la
 *   confirmación nombra todas las que se sueltan para que nadie crea que sólo se va ésa.
 *
 * ## El servidor decide
 *
 * Las candidatas a unir se filtran con lo que el piso ya trae resuelto (`is_available`) para no ofrecer lo que se va a
 * rechazar, pero la regla vive en el servidor: si entre el último refresco y el toque alguien sentó gente en una de
 * ellas, la respuesta dice por qué y se pinta tal cual.
 */
const props = defineProps({
    /** La mesa seleccionada, tal como la trae `GET /branches/{branch}/floor`. */
    mesa: { type: Object, required: true },

    /** Todas las mesas del piso: de ahí salen la principal, las unidas y las candidatas a unir. */
    mesas: { type: Array, required: true },
});

/** El salón cambió: quien pinta el piso lo vuelve a pedir. */
const emit = defineEmits(['changed']);

const { canWrite } = useAuthorization();

/** `null`, o la operación que se está confirmando: `'liberar'`, `'unir'` o `'separar'`. */
const abierta = ref(null);

/** Los ULID de las mesas marcadas para unir. */
const marcadas = ref([]);

const lista = new Intl.ListFormat('es', { type: 'conjunction' });

/** «M5», «M5 y M6», «M5, M6 y M7». */
const codigos = (mesas) => lista.format(mesas.map((m) => m.code));

/** «la mesa M5» o «las mesas M5 y M6», para que los avisos se lean como frases. */
const nombrar = (mesas) => `${mesas.length === 1 ? 'la mesa' : 'las mesas'} ${codigos(mesas)}`;

/** La principal de la unión si esta mesa cuelga de otra; `null` también si la principal no está en este plano. */
const principal = computed(() => (props.mesa.joined_to
    ? props.mesas.find((m) => m.ulid === props.mesa.joined_to) ?? null
    : null));

/** El ULID de la principal de la unión a la que pertenece la mesa: la suya si cuelga de otra, o ella misma. */
const cabeza = computed(() => props.mesa.joined_to ?? props.mesa.ulid);

const laPrincipal = computed(() => {
    if (! props.mesa.joined_to) {
        return `la mesa ${props.mesa.code}`;
    }

    return principal.value ? `la mesa ${principal.value.code}` : 'su mesa principal';
});

/** Las que se sueltan al separar: todas las que cuelgan de la principal, ésta incluida si es una de ellas. */
const grupo = computed(() => props.mesas.filter((m) => m.joined_to === cabeza.value));

/** La cuenta del grupo, que vive en la principal. */
const cuentaDelGrupo = computed(() => (props.mesa.joined_to ? principal.value?.account : props.mesa.account) ?? null);

/**
 * Las que se pueden unir a ésta: las que el servidor da por disponibles —libres, sueltas y en el piso— y que no son a su
 * vez principales de otra unión. Esto último el servidor NO lo impide, y unirlas encadenaría uniones: sus mesas
 * colgarían de una mesa que cuelga de otra.
 */
const candidatas = computed(() => props.mesas.filter((m) => m.ulid !== props.mesa.ulid
    && m.is_available
    && ! props.mesas.some((otra) => otra.joined_to === m.ulid)));

/** Las marcadas que siguen siendo candidatas: el piso se refresca solo, y una que dejó de serlo no se manda. */
const aUnir = computed(() => candidatas.value.filter((m) => marcadas.value.includes(m.ulid)));

/** Vista previa de los lugares del grupo; los de verdad los calcula el servidor al unir. */
const lugaresConUnion = computed(() => aUnir.value.reduce(
    (suma, m) => suma + Number(m.seats),
    Number(props.mesa.effective_seats),
));

const disponibles = computed(() => ({
    liberar: props.mesa.status !== 'free' && ! props.mesa.account,
    unir: ! props.mesa.joined_to && ! props.mesa.is_archived,
    separar: grupo.value.length > 0,
}));

const hayAcciones = computed(() => canWrite('floor.tables.join') && Object.values(disponibles.value).some(Boolean));

const esLimpieza = computed(() => props.mesa.status === 'needs_cleaning');

const avisoLiberar = computed(() => {
    if (esLimpieza.value) {
        return 'Queda libre y el piso la ofrece para sentar al siguiente grupo.';
    }

    const aviso = `Figura como «${props.mesa.status_label}» pero no tiene ninguna cuenta abierta. Al liberarla queda `
        + 'libre y el piso la ofrece para sentar a otro grupo.';

    // Liberar sólo cambia el estado de ESTA mesa: las que cuelgan de ella siguen unidas.
    return ! props.mesa.joined_to && grupo.value.length > 0
        ? `${aviso} La unión con ${codigos(grupo.value)} se mantiene: si el grupo ya se fue, usa «Separar».`
        : aviso;
});

const avisoUnir = computed(() => `El grupo se atiende con una sola cuenta, la de la mesa ${props.mesa.code}`
    + `${props.mesa.account ? '' : ' —ábrela en ella—'}, y en las mesas unidas no se puede sentar a nadie más. La unión `
    + 'se deshace sola al cobrar, o a mano con «Separar».');

const avisoSeparar = computed(() => {
    const varias = grupo.value.length > 1;

    const efecto = cuentaDelGrupo.value
        ? `La cuenta sigue en ${laPrincipal.value}, y ${varias ? 'esas mesas vuelven' : 'esa mesa vuelve'} a ofrecerse `
            + `${varias ? 'libres' : 'libre'} para sentar a otros clientes. Si el grupo sigue sentado, no hace falta: la `
            + 'unión se deshace sola al cobrar.'
        : `${varias ? 'Vuelven a ser mesas sueltas, libres' : 'Vuelve a ser una mesa suelta, libre'} para sentar a otros `
            + 'clientes.';

    return props.mesa.joined_to && varias
        ? `Se deshace la unión completa, no sólo la de la mesa ${props.mesa.code}. ${efecto}`
        : efecto;
});

const operacion = useApiForm(
    async ({ ruta, cuerpo }) => (await api.post(ruta, cuerpo)).data,
    // El aviso lo compone cada operación: «Se unió la mesa M5 a la mesa M4» dice más que un «Listo» genérico.
    { success: (_resultado, [{ aviso }]) => aviso },
);

/** Abre la confirmación de una operación —o la cierra, si era la abierta— y limpia lo que dejó la anterior. */
function alternar(cual) {
    abierta.value = abierta.value === cual ? null : cual;
    marcadas.value = [];
    operacion.generalError.value = null;
}

const cerrar = () => alternar(null);

// El piso se refresca solo (socket o sondeo). Si bajo una confirmación abierta la operación deja de aplicar —otro mesero
// sentó gente, cobró o separó—, se cierra en vez de seguir ofreciendo algo que ya no corresponde. Con la petición en
// vuelo no: su respuesta, sea cual sea, tiene que verse.
watch(disponibles, (ahora) => {
    if (abierta.value && ! ahora[abierta.value] && ! operacion.processing.value) {
        cerrar();
    }
});

async function ejecutar(ruta, aviso, cuerpo) {
    if (await operacion.submit({ ruta, cuerpo, aviso })) {
        cerrar();
        emit('changed');
    }
}

function liberar() {
    ejecutar(
        `/restaurant-tables/${props.mesa.ulid}/free`,
        esLimpieza.value ? `La mesa ${props.mesa.code} quedó lista para sentar.` : `Se liberó la mesa ${props.mesa.code}.`,
    );
}

function unir() {
    const mesas = aUnir.value;

    ejecutar(
        `/restaurant-tables/${props.mesa.ulid}/join`,
        `Se ${mesas.length === 1 ? 'unió' : 'unieron'} ${nombrar(mesas)} a la mesa ${props.mesa.code}.`,
        { table_ulids: mesas.map((m) => m.ulid) },
    );
}

function separar() {
    ejecutar(
        `/restaurant-tables/${cabeza.value}/separate`,
        `Se ${grupo.value.length === 1 ? 'separó' : 'separaron'} ${nombrar(grupo.value)} de ${laPrincipal.value}.`,
    );
}
</script>

<template>
    <div v-if="hayAcciones" class="acciones-mesa">
        <p class="section-label">Operaciones de mesa</p>

        <div class="acciones-mesa__botones">
            <button
                v-if="disponibles.liberar"
                type="button"
                class="button button--neutral"
                :aria-expanded="abierta === 'liberar'"
                :disabled="operacion.processing.value"
                @click="alternar('liberar')"
            >
                <Icon name="check" :size="15" /> {{ esLimpieza ? 'Marcar limpia' : 'Liberar mesa' }}
            </button>

            <button
                v-if="disponibles.unir"
                type="button"
                class="button button--neutral"
                :aria-expanded="abierta === 'unir'"
                :disabled="operacion.processing.value"
                @click="alternar('unir')"
            >
                <Icon name="plus" :size="15" /> Unir mesas
            </button>

            <button
                v-if="disponibles.separar"
                type="button"
                class="button button--neutral"
                :aria-expanded="abierta === 'separar'"
                :disabled="operacion.processing.value"
                @click="alternar('separar')"
            >
                <Icon name="undo" :size="15" /> Separar
            </button>
        </div>

        <div
            v-if="abierta === 'liberar'"
            class="confirmacion confirmacion--aviso"
            role="group"
            :aria-label="esLimpieza ? `Marcar limpia la mesa ${mesa.code}` : `Liberar la mesa ${mesa.code}`"
        >
            <p class="confirmacion__pregunta">
                {{ esLimpieza ? `¿La mesa ${mesa.code} ya está limpia?` : `¿Liberar la mesa ${mesa.code}?` }}
            </p>
            <p>{{ avisoLiberar }}</p>

            <p v-if="operacion.generalError.value" class="alert" role="alert">{{ operacion.generalError.value }}</p>

            <div class="confirmacion__botones">
                <button type="button" class="button" :disabled="operacion.processing.value" @click="liberar">
                    <Icon name="check" :size="15" /> {{ esLimpieza ? 'Sí, marcar limpia' : 'Sí, liberar' }}
                </button>
                <button type="button" class="button button--neutral" :disabled="operacion.processing.value" @click="cerrar">
                    Cancelar
                </button>
            </div>
        </div>

        <div
            v-else-if="abierta === 'unir'"
            class="confirmacion"
            role="group"
            :aria-label="`Unir mesas a la mesa ${mesa.code}`"
        >
            <p class="confirmacion__pregunta">¿Qué mesas se juntan con la mesa {{ mesa.code }}?</p>
            <p>{{ avisoUnir }}</p>

            <p v-if="candidatas.length === 0" class="nota">
                No hay mesas para unir: las demás tienen servicio, esperan limpieza o ya forman parte de otra unión.
            </p>

            <ul v-else class="candidatas">
                <li v-for="m in candidatas" :key="m.ulid">
                    <label class="candidata" :class="{ 'candidata--on': marcadas.includes(m.ulid) }">
                        <input v-model="marcadas" type="checkbox" :value="m.ulid" :disabled="operacion.processing.value" />
                        <strong>{{ m.code }}</strong>
                        <span class="candidata__lugares">{{ m.seats }} lugares</span>
                    </label>
                </li>
            </ul>

            <p v-if="aUnir.length > 0" class="nota">
                El grupo quedaría con {{ lugaresConUnion }} lugares.
            </p>

            <p v-if="operacion.generalError.value" class="alert" role="alert">{{ operacion.generalError.value }}</p>

            <div class="confirmacion__botones">
                <button
                    v-if="candidatas.length > 0"
                    type="button"
                    class="button"
                    :disabled="aUnir.length === 0 || operacion.processing.value"
                    @click="unir"
                >
                    <Icon name="plus" :size="15" />
                    {{ aUnir.length === 0 ? 'Unir' : `Unir ${aUnir.length} ${aUnir.length === 1 ? 'mesa' : 'mesas'}` }}
                </button>
                <button type="button" class="button button--neutral" :disabled="operacion.processing.value" @click="cerrar">
                    {{ candidatas.length > 0 ? 'Cancelar' : 'Cerrar' }}
                </button>
            </div>
        </div>

        <div
            v-else-if="abierta === 'separar'"
            class="confirmacion confirmacion--aviso"
            role="group"
            :aria-label="`Separar ${nombrar(grupo)}`"
        >
            <p class="confirmacion__pregunta">¿Separar {{ nombrar(grupo) }} de {{ laPrincipal }}?</p>
            <p>{{ avisoSeparar }}</p>

            <p v-if="operacion.generalError.value" class="alert" role="alert">{{ operacion.generalError.value }}</p>

            <div class="confirmacion__botones">
                <button type="button" class="button" :disabled="operacion.processing.value" @click="separar">
                    <Icon name="undo" :size="15" /> Sí, separar
                </button>
                <button type="button" class="button button--neutral" :disabled="operacion.processing.value" @click="cerrar">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.acciones-mesa {
    display: grid;
    gap: 0.6rem;
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px solid var(--color-borde);
}

.acciones-mesa .section-label { margin: 0; }

.acciones-mesa__botones,
.confirmacion__botones { display: flex; flex-wrap: wrap; gap: 0.5rem; }

/* Táctil: un dedo necesita unos 44 px de alto, y los botones compartidos están medidos para ratón. */
.acciones-mesa .button { min-height: 2.75rem; }

.confirmacion {
    display: grid;
    gap: 0.6rem;
    padding: 0.8rem 0.9rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-fondo);
    color: var(--color-contenido);
}

/* Liberar y separar cambian lo que el salón ofrece a quien llega: la consecuencia se lee como aviso, no como error. */
.confirmacion--aviso {
    border-color: color-mix(in srgb, var(--color-aviso) 35%, transparent);
    background: var(--color-aviso-tenue);
    color: var(--color-aviso-texto);
}

.confirmacion p { margin: 0; font-size: 0.9rem; line-height: 1.45; }
.confirmacion .alert { margin: 0; }
.confirmacion__pregunta { font-weight: 650; }
.nota { color: var(--color-suave); }

.candidatas {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
    gap: 0.4rem;
}

.candidata {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.75rem;
    padding: 0.35rem 0.6rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
}

.candidata--on { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }
.candidata input { flex: none; width: 1.15rem; height: 1.15rem; margin: 0; accent-color: var(--color-acento); }
.candidata__lugares { margin-left: auto; font-size: 0.8rem; color: var(--color-suave); }
</style>
