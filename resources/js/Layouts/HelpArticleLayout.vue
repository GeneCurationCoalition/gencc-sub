<script setup>
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
});

const page = usePage();
</script>

<template>
    <AppLayout v-if="page.props.mine" :title="title">
        <template #header>
            <slot name="header" />
        </template>
        <slot />
    </AppLayout>

    <GuestLayout v-else :title="title">
        <template #header>
            <slot name="header" />
        </template>
        <slot />
    </GuestLayout>
</template>
