<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
</script>

<template>
    <div v-if="success || error" class="flash" :class="error ? 'flash--error' : 'flash--success'">
        {{ error ?? success }}
    </div>
</template>

<style scoped>
.flash {
    margin: 1rem 1.5rem 0;
    padding: 0.7rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.9rem;
}

.flash--success {
    background: #dcfce7;
    border: 1px solid color-mix(in srgb, var(--color-exito) 40%, transparent);
}

.flash--error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
}
</style>
