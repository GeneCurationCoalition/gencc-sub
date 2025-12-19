<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import AliasListing from '@/Components/AliasListing.vue';

defineProps(['aliases'])

</script>

<template>
    <AppLayout title="aliases">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Terms
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <AliasListing :aliases="aliases" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
