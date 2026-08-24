<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthWaves from '../../components/AuthWaves.vue';

/**
 * Selección de negocio (§2).
 *
 * Aparece cuando la persona pertenece a más de un negocio, y también cuando quiere cambiar de uno a
 * otro sin cerrar sesión — el caso de quien administra dos restaurantes: la identidad es global y la
 * pertenencia es por tenant (§4.1).
 */
defineProps({
    tenants: { type: Array, required: true },
});

function enter(ulid) {
    router.post('/negocios', { tenant_ulid: ulid });
}
</script>

<template>
    <Head title="Elige un negocio" />

    <AuthWaves>
        <template #subtitulo>
            <p class="lead">Elige el negocio con el que quieres trabajar.</p>
        </template>

        <ul class="lista">
            <li v-for="tenant in tenants" :key="tenant.ulid">
                <button class="opcion grupo" type="button" @click="enter(tenant.ulid)">
                    <span class="opcion__texto">
                        <span class="opcion__nombre">{{ tenant.name }}</span>
                        <span class="opcion__meta">
                            {{ tenant.display_name }}
                            <!--
                                Se avisa antes de entrar: un negocio en sólo lectura por impago
                                conserva sus datos y no puede operar, y enterarse al intentar
                                guardar sería peor.
                            -->
                            <template v-if="tenant.is_read_only">
                                · <strong>sólo lectura</strong>
                            </template>
                        </span>
                    </span>
                    <span class="flechas" aria-hidden="true">
                        <svg v-for="n in 3" :key="n" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                        </svg>
                    </span>
                </button>
            </li>
        </ul>

        <button class="salir" type="button" @click="router.post('/logout')">
            Salir de mi cuenta
        </button>
    </AuthWaves>
</template>

<style scoped>
.lead {
    margin: 0.35rem 0 0;
    font-size: 0.9rem;
    color: #78716c;
}

.lista {
    list-style: none;
    margin: 0 0 1.25rem;
    padding: 0;
    display: grid;
    gap: 0.6rem;
}

.opcion {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    border: 1px solid #d6d3d1;
    border-radius: 0.6rem;
    background: #fff;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition:
        border-color 0.18s ease,
        background 0.18s ease,
        transform 0.18s ease,
        box-shadow 0.18s ease;
}

.opcion:hover {
    border-color: #c2410c;
    background: #fff7ed;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px -14px rgba(194, 65, 12, 0.8);
}

.opcion__texto {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}

.opcion__nombre {
    font-weight: 600;
    color: #1c1917;
}

.opcion__meta {
    font-size: 0.8rem;
    color: #78716c;
}

/* Las flechas del botón, iguales que en el CTA de acceso: una en reposo, en cadena al pasar el
   cursor. Aquí el color lo hereda del acento de marca. */
.flechas {
    position: relative;
    display: inline-flex;
    width: 1.1rem;
    height: 1.1rem;
    flex: none;
    color: #c2410c;
}

.chev {
    position: absolute;
    inset: 0;
    width: 1.1rem;
    height: 1.1rem;
    opacity: 0;
}

.chev:first-child {
    opacity: 1;
}

.grupo:hover .chev {
    animation: fluir 0.9s ease-in-out infinite;
}

.grupo:hover .chev:nth-child(2) {
    animation-delay: 0.2s;
}

.grupo:hover .chev:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes fluir {
    0% {
        opacity: 0;
        transform: translateX(-6px);
    }
    35% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translateX(9px);
    }
}

.salir {
    display: block;
    margin: 0 auto;
    background: none;
    border: 0;
    padding: 0.35rem;
    color: #78716c;
    cursor: pointer;
    font: inherit;
    font-size: 0.85rem;
    transition: color 0.2s ease;
}

.salir:hover {
    color: #c2410c;
}

@media (prefers-reduced-motion: reduce) {
    .grupo:hover .chev {
        animation: none;
    }

    .opcion:hover {
        transform: none;
    }
}
</style>
