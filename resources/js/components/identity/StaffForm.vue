<script setup>
import { computed, ref } from 'vue';
import { api } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import Icon from '../../components/Icon.vue';

/**
 * Alta de personal (§4.1, D66).
 *
 * ## Dos naturalezas y no dos formularios
 *
 * Un negocio de alimentos tiene dos clases de persona y las dos son personal: quien **entra al
 * sistema** —mesero, cajero, gerente— y quien sólo **existe en nómina**, como el lavaloza que jamás
 * inicia sesión. Es la decisión de §4.1, y de ella se derivan casi todas las reglas: sin credenciales
 * no hay roles, y sin roles el PIN no autoriza nada.
 *
 * El formulario lo pregunta primero, en lugar de tener dos pantallas: lo que cambia entre los dos
 * casos es qué campos hacen falta, no lo que se está haciendo.
 *
 * ## Sin correo, el perfil laboral es obligatorio
 *
 * Invariante I1: una membresía sin credenciales necesita perfil de empleado, porque **es de ahí de
 * donde sale su nombre**. El servidor lo exige, no como una regla de papeleo, sino porque una
 * membresía sin ninguno de los dos no tiene nombre que mostrar en ninguna pantalla.
 *
 * ## El alcance por sucursal se decide aquí, y con intención
 *
 * «Todas las sucursales» no es «las tres que hay»: incluye las que se abran después. Es la razón por
 * la que es una opción y no una casilla más — quien elige mal se enterará el día que abra la cuarta.
 */
const props = defineProps({
    branches: { type: Array, required: true },
    roles: { type: Array, required: true },
});

const emit = defineEmits(['close', 'saved']);

const form = ref({
    accesses: true,
    email: '',
    password: '',
    first_name: '',
    paternal_surname: '',
    maternal_surname: '',
    employee_code: '',
    role_ulids: [],
    scope: 'all',
    branch_ulids: [],
    profile: {
        legal_first_name: '',
        legal_paternal_surname: '',
        legal_maternal_surname: '',
        hired_at: '',
    },
});

/**
 * El perfil laboral se manda cuando es obligatorio —sin correo— o cuando se llenó algo.
 *
 * Mandar un perfil vacío para alguien con correo crearía una fila de nómina que nadie pidió, y
 * omitirlo cuando no hay correo produce el 422 del invariante I1.
 */
const profileRequired = computed(() => !form.value.accesses);

const warnings = computed(() => {
    const list = [];

    if (form.value.accesses && form.value.email === '') {
        list.push('Una persona con acceso al sistema necesita correo: es con lo que inicia sesión.');
    }

    if (form.value.accesses && form.value.email !== '' && form.value.password.length < 10) {
        list.push('La contraseña necesita al menos 10 caracteres.');
    }

    if (profileRequired.value && form.value.profile.legal_first_name === '') {
        list.push(
            'Sin correo hace falta el nombre legal: es de donde el sistema saca el nombre de esta ' +
                'persona en todas las pantallas.',
        );
    }

    if (form.value.scope === 'some' && form.value.branch_ulids.length === 0) {
        list.push('Elige al menos una sucursal, o dale alcance a todas.');
    }

    if (form.value.accesses && form.value.role_ulids.length === 0) {
        list.push('Sin rol, esta persona podrá entrar y no podrá hacer nada.');
    }

    if (form.value.employee_code === '') {
        list.push(
            'Sin código de empleado no podrá autorizar con PIN: las autorizaciones del punto de venta ' +
                'identifican a la persona por su código.',
        );
    }

    return list;
});

const save = useApiForm(async () => {
    const profile = { ...form.value.profile };

    if (profile.hired_at === '') {
        delete profile.hired_at;
    }

    await api.post('/memberships', {
        email: form.value.accesses && form.value.email !== '' ? form.value.email : null,
        password: form.value.accesses && form.value.email !== '' ? form.value.password : null,

        first_name: form.value.first_name,
        paternal_surname: form.value.paternal_surname,
        maternal_surname: form.value.maternal_surname === '' ? null : form.value.maternal_surname,

        // Vacío = se guarda sin código. El servidor NO inventa uno, y el formulario no debe decir que sí:
        // la primera versión de este campo prometía «se asigna solo» y era falso — la persona quedaba sin
        // código y sin poder autorizar con PIN, sin que nada lo avisara. Lo encontró el navegador.
        employee_code: form.value.employee_code === '' ? null : form.value.employee_code,

        // Sin credenciales no hay roles: los roles son del usuario, y quien no inicia sesión no ejerce
        // permisos. El servidor lo rechaza, así que la UI ni los manda.
        role_ulids: form.value.accesses ? form.value.role_ulids : [],

        has_all_branches: form.value.scope === 'all',
        branch_ulids: form.value.scope === 'all' ? [] : form.value.branch_ulids,

        employee_profile:
            profileRequired.value || profile.legal_first_name !== '' ? profile : null,
    });
});

async function submit() {
    if (await save.submit()) {
        emit('saved');
    }
}

function toggle(list, ulid) {
    const index = form.value[list].indexOf(ulid);

    if (index === -1) {
        form.value[list].push(ulid);
    } else {
        form.value[list].splice(index, 1);
    }
}

/** Error del servidor para un campo del perfil, que llega anidado como `employee_profile.campo`. */
function profileError(field) {
    return save.fieldErrors.value[`employee_profile.${field}`];
}
</script>

<template>
    <div class="drawer-backdrop" @click.self="emit('close')">
        <form class="drawer drawer--wide" @submit.prevent="submit">
            <h2>Nueva persona</h2>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <fieldset class="block">
                <legend class="field__label">¿Va a entrar al sistema?</legend>

                <label class="choice">
                    <input v-model="form.accesses" type="radio" :value="true" />
                    <span>
                        <span class="choice__label">Sí, con correo y contraseña</span>
                        <span class="choice__hint">Mesero, cajero, gerente: alguien que usa la aplicación.</span>
                    </span>
                </label>

                <label class="choice">
                    <input v-model="form.accesses" type="radio" :value="false" />
                    <span>
                        <span class="choice__label">No, sólo existe en nómina</span>
                        <span class="choice__hint">
                            El lavaloza que nunca inicia sesión. Necesita perfil laboral, que es de donde
                            sale su nombre.
                        </span>
                    </span>
                </label>
            </fieldset>

            <div class="pair">
                <label class="field">
                    <span class="field__label">Nombre</span>
                    <input v-model="form.first_name" class="input" maxlength="60" required />
                    <span v-if="save.fieldErrors.value.first_name" class="field__error">
                        {{ save.fieldErrors.value.first_name }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Apellido paterno</span>
                    <input v-model="form.paternal_surname" class="input" maxlength="60" required />
                    <span v-if="save.fieldErrors.value.paternal_surname" class="field__error">
                        {{ save.fieldErrors.value.paternal_surname }}
                    </span>
                </label>
            </div>

            <div class="pair">
                <label class="field">
                    <span class="field__label">Apellido materno</span>
                    <input v-model="form.maternal_surname" class="input" maxlength="60" />
                </label>

                <label class="field">
                    <span class="field__label">Código de empleado</span>
                    <input v-model="form.employee_code" class="input" maxlength="20" placeholder="M02" />
                    <span class="field__hint">
                        Opcional, pero <strong>sin código no se puede autorizar con PIN</strong>: la
                        autorización identifica a la persona por su código.
                    </span>
                    <span v-if="save.fieldErrors.value.employee_code" class="field__error">
                        {{ save.fieldErrors.value.employee_code }}
                    </span>
                </label>
            </div>

            <template v-if="form.accesses">
                <div class="pair">
                    <label class="field">
                        <span class="field__label">Correo</span>
                        <input v-model="form.email" type="email" class="input" maxlength="150" />
                        <span class="field__hint">
                            Único en toda la plataforma: quien administra dos negocios usa el mismo.
                        </span>
                        <span v-if="save.fieldErrors.value.email" class="field__error">
                            {{ save.fieldErrors.value.email }}
                        </span>
                    </label>

                    <label class="field">
                        <span class="field__label">Contraseña</span>
                        <input v-model="form.password" type="password" class="input" minlength="10" />
                        <span class="field__hint">Al menos 10 caracteres.</span>
                        <span v-if="save.fieldErrors.value.password" class="field__error">
                            {{ save.fieldErrors.value.password }}
                        </span>
                    </label>
                </div>

                <fieldset v-if="props.roles.length" class="block">
                    <legend class="field__label">Roles</legend>
                    <p class="block__hint">
                        Se puede tener varios, y el <strong>primero</strong> queda como el rol activo al
                        entrar. Las decisiones se toman siempre por rol activo, nunca sumando permisos.
                    </p>

                    <label v-for="role in props.roles" :key="role.ulid" class="choice choice--tight">
                        <input
                            type="checkbox"
                            :checked="form.role_ulids.includes(role.ulid)"
                            @change="toggle('role_ulids', role.ulid)"
                        />
                        <span>{{ role.name }}</span>
                    </label>

                    <span v-if="save.fieldErrors.value.role_ulids" class="field__error">
                        {{ save.fieldErrors.value.role_ulids }}
                    </span>
                </fieldset>
            </template>

            <fieldset class="block">
                <legend class="field__label">¿En qué sucursales opera?</legend>

                <label class="choice">
                    <input v-model="form.scope" type="radio" value="all" />
                    <span>
                        <span class="choice__label">En todas</span>
                        <span class="choice__hint">
                            Incluye las que se abran después. Es lo que quieres para un gerente general.
                        </span>
                    </span>
                </label>

                <label class="choice">
                    <input v-model="form.scope" type="radio" value="some" />
                    <span>
                        <span class="choice__label">Sólo en las que elija</span>
                        <span class="choice__hint">Una sucursal nueva NO la incluirá automáticamente.</span>
                    </span>
                </label>

                <div v-if="form.scope === 'some'" class="branches">
                    <label v-for="branch in props.branches" :key="branch.ulid" class="choice choice--tight">
                        <input
                            type="checkbox"
                            :checked="form.branch_ulids.includes(branch.ulid)"
                            @change="toggle('branch_ulids', branch.ulid)"
                        />
                        <span>{{ branch.name }} <span class="muted">({{ branch.code }})</span></span>
                    </label>
                </div>

                <span v-if="save.fieldErrors.value.branch_ulids" class="field__error">
                    {{ save.fieldErrors.value.branch_ulids }}
                </span>
            </fieldset>

            <fieldset class="block">
                <legend class="field__label">
                    Perfil laboral<template v-if="profileRequired"> (obligatorio sin correo)</template>
                </legend>
                <p class="block__hint">
                    Es el nombre <strong>legal</strong>, que puede no ser el de uso diario. Los datos
                    fiscales —CURP, RFC, NSS— se capturan después, en la ficha de la persona, porque
                    exigen un permiso propio para verlos.
                </p>

                <div class="pair">
                    <label class="field">
                        <span class="field__label">Nombre legal</span>
                        <input
                            v-model="form.profile.legal_first_name"
                            class="input"
                            maxlength="60"
                            :required="profileRequired"
                        />
                        <span v-if="profileError('legal_first_name')" class="field__error">
                            {{ profileError('legal_first_name') }}
                        </span>
                    </label>

                    <label class="field">
                        <span class="field__label">Apellido paterno legal</span>
                        <input
                            v-model="form.profile.legal_paternal_surname"
                            class="input"
                            maxlength="60"
                            :required="profileRequired"
                        />
                        <span v-if="profileError('legal_paternal_surname')" class="field__error">
                            {{ profileError('legal_paternal_surname') }}
                        </span>
                    </label>
                </div>

                <div class="pair">
                    <label class="field">
                        <span class="field__label">Apellido materno legal</span>
                        <input v-model="form.profile.legal_maternal_surname" class="input" maxlength="60" />
                    </label>

                    <label class="field">
                        <span class="field__label">Fecha de alta</span>
                        <input v-model="form.profile.hired_at" type="date" class="input" />
                        <span v-if="profileError('hired_at')" class="field__error">
                            {{ profileError('hired_at') }}
                        </span>
                    </label>
                </div>
            </fieldset>

            <ul v-if="warnings.length" class="warnings">
                <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
            </ul>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value"><Icon name="plus" /> Dar de alta</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.drawer--wide {
    max-width: 34rem;
}

.block {
    border: 1px solid #e7e5e4;
    border-radius: 0.375rem;
    padding: 0.75rem;
    margin: 0 0 0.9rem;
}

.block legend {
    padding: 0 0.35rem;
}

.block__hint {
    margin: 0 0 0.5rem;
    font-size: 0.78rem;
    opacity: 0.65;
}

.choice {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.25rem 0;
}

.choice--tight {
    padding: 0.1rem 0;
    font-size: 0.87rem;
}

.choice input {
    margin-top: 0.25rem;
}

.choice__label {
    display: block;
    font-size: 0.9rem;
    font-weight: 500;
}

.choice__hint {
    display: block;
    font-size: 0.78rem;
    opacity: 0.6;
}

.branches {
    margin: 0.4rem 0 0 1.4rem;
    padding-left: 0.6rem;
    border-left: 2px solid #e7e5e4;
}

.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.muted {
    opacity: 0.55;
}

/* Ámbar y no rojo: todavía no ha fallado nada. Es lo que el servidor rechazaría si se guarda así. */
.warnings {
    margin: 0 0 0.9rem;
    padding: 0.6rem 0.85rem 0.6rem 1.9rem;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.375rem;
    font-size: 0.82rem;
    color: #92400e;
}

.warnings li + li {
    margin-top: 0.35rem;
}
</style>
