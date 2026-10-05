<script setup>
import { computed, ref, useId } from 'vue';
import { api, ApiError } from '../../api/client';
import { formatInBranchTime } from '../../support/datetime';
import Icon from '../Icon.vue';
import CopyLink from './CopyLink.vue';

/**
 * Dar acceso o reenviar la invitación de una persona (diseño de acceso, fase 3).
 *
 * - **Dar acceso** (sin invitación previa): correo y roles. Es lo que se hace con alguien que estaba sólo en nómina.
 * - **Reenviar** (con una invitación pendiente o vencida): el correo, que se puede corregir; los roles no se mandan y el
 *   servidor conserva los de la invitación anterior. La anterior deja de servir.
 *
 * Al enviarse, el servidor devuelve el enlace UNA vez: se muestra para copiarlo si el correo no llega.
 */
const props = defineProps({
    membership: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'sent']);

const reenvio = computed(() => props.membership.invitation != null);

const correo = ref(props.membership.invitation?.email ?? '');
const elegidos = ref(props.membership.default_role ? [props.membership.default_role.ulid] : []);
const enviando = ref(false);
const error = ref(null);
const errores = ref({});
const enviada = ref(null);
const idCorreo = useId();

function alternar(ulid) {
    const i = elegidos.value.indexOf(ulid);
    i === -1 ? elegidos.value.push(ulid) : elegidos.value.splice(i, 1);
}

async function enviar() {
    if (enviando.value) {
        return;
    }

    enviando.value = true;
    error.value = null;
    errores.value = {};

    try {
        const cuerpo = { email: correo.value.trim() };

        if (! reenvio.value) {
            cuerpo.role_ulids = elegidos.value;
        }

        const respuesta = await api.post(`/memberships/${props.membership.ulid}/invitation`, cuerpo);

        enviada.value = {
            email: respuesta?.data?.invitation?.email ?? cuerpo.email,
            expiresAt: respuesta?.data?.invitation?.expires_at ?? null,
            link: respuesta?.meta?.invitation_link ?? null,
        };

        emit('sent');
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (e.isValidation) {
            errores.value = e.fieldErrors ?? {};
        } else {
            error.value = e.message;
        }
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <div class="drawer-backdrop" @click.self="emit('close')">
        <form class="drawer" @submit.prevent="enviar">
            <h2>{{ reenvio ? 'Reenviar invitación' : 'Dar acceso' }} a {{ membership.display_name }}</h2>

            <template v-if="enviada">
                <p class="alert alert--ok" role="status">
                    Invitación enviada a {{ enviada.email }}.
                    <template v-if="enviada.expiresAt">Vence el {{ formatInBranchTime(enviada.expiresAt) }}.</template>
                    Crea su contraseña al aceptarla; nadie más la conoce.
                </p>

                <CopyLink v-if="enviada.link" :link="enviada.link" />

                <div class="drawer__actions">
                    <button type="button" class="button" @click="emit('close')"><Icon name="check" /> Listo</button>
                </div>
            </template>

            <template v-else>
                <p class="field__hint">
                    Le llega un enlace que vence en 7 días. Si es su primera vez en Comandia, ahí crea su contraseña; si ya
                    lo usa en otro negocio, acepta con la suya.
                    <template v-if="reenvio"> La invitación anterior deja de servir.</template>
                </p>

                <p v-if="error" class="alert" role="alert">{{ error }}</p>

                <label class="field" :for="idCorreo">
                    <span class="field__label">Correo</span>
                    <input :id="idCorreo" v-model="correo" type="email" class="input" maxlength="150" required />
                    <span v-if="errores.email" class="field__error">{{ errores.email }}</span>
                </label>

                <fieldset v-if="! reenvio && roles.length" class="field">
                    <legend class="field__label">Roles</legend>
                    <p class="field__hint">El primero queda como su rol activo al entrar. Se le asignan al aceptar.</p>
                    <label v-for="role in roles" :key="role.ulid" class="rol">
                        <input type="checkbox" :checked="elegidos.includes(role.ulid)" @change="alternar(role.ulid)" />
                        <span>{{ role.name }}</span>
                    </label>
                </fieldset>

                <p v-if="reenvio" class="field__hint">Conserva los roles de la invitación anterior.</p>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cancelar</button>
                    <button type="submit" class="button" :disabled="enviando">
                        <Icon name="send" /> {{ enviando ? 'Enviando…' : (reenvio ? 'Reenviar' : 'Enviar invitación') }}
                    </button>
                </div>
            </template>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.rol {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.2rem 0;
    font-size: 0.9rem;
}
</style>
