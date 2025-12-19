<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import { Link } from '@inertiajs/vue3'
    import SubmissionItem from '@/Components/SubmissionItem.vue';
    import ChangeFriendly from '@/Components/ChangeFriendly.vue';


    const props = defineProps(['submission', 'criterias', 'hasSubmittedJob', 'unpublishedDuplicateWarning'])

    const showFriendlyDialog = ref(false);
    const dialogTitle = defineModel('dialogTitle');
    const dialogLabel = defineModel('dialogLabel');

    // Compute header color based on submission status
    const headerClass = computed(() => {
        if (!props.submission?.status) {
            return 'bg-sky-800'; // Default for legacy
        }

        const statusColors = {
            'draft_new': 'bg-amber-600',           // Amber for draft
            'submitted_new': 'bg-blue-600',        // Blue for submitted
            'published': 'bg-green-600',           // Green for published/processed
            'draft_republish': 'bg-amber-600',     // Amber for draft
            'submitted_republish': 'bg-blue-600',  // Blue for submitted
            'draft_unpublish': 'bg-amber-600',     // Amber for draft (matches job state)
            'submitted_unpublish': 'bg-blue-600',  // Blue for submitted (matches job state)
            'unpublished': 'bg-green-600'          // Green for processed
        };

        return statusColors[props.submission.status] || 'bg-sky-800';
    });
</script>

<template>
    <AppLayout title="Submission" :header-class="headerClass">
        <template #header>
            <div class="font-semibold text-4xl text-white leading-tight grid grid-cols-10">
                <div class="col-span-2">
                    Submission ID:
                </div>
                <div class="text-left col-span-6">
                    <p class="font-black px-2 ml-3 text-xl inline-block align-center leading-4">
                        {{ submission.sid }}<br>
                    </p>
                </div>
                <div class="text-right text-base col-span-2">
                    <div v-if="submission.job.type == 0" class="float-right text-base "><Link :href="'/jobs/' + submission.job.ident"><i class="pi pi-arrow-left"></i> Return to Job </Link></div>
                    <div v-else class="float-right text-base "><Link href="/submissions"><i class="pi pi-arrow-left"></i> Return to Submission List</Link></div>
                </div>

            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <SubmissionItem :submission="submission" :criteria_options="criterias" :hasSubmittedJob="hasSubmittedJob" :unpublishedDuplicateWarning="unpublishedDuplicateWarning" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
