<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SubmissionsListing from '@/Components/SubmissionsListing.vue';

defineProps(['submissions', 'errors', 'favorites', 'hasSubmittedJob'])

</script>

<template>
    <AppLayout title="Submissions">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Submissions
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <SubmissionsListing :submissions="submissions" :errors="errors" :favorites="favorites" :hasSubmittedJob="hasSubmittedJob"/>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
