<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Help from '@/Components/Help.vue';
</script>

<template>
    <AppLayout title="Help">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Help & Documentation
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <Help />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
