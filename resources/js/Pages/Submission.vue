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

    // Check if this is a non-most-recent (historical) version
    const isNotMostRecentVersion = computed(() => {
        return props.submission?.is_most_recent === false &&
               (props.submission?.status === 'published' || props.submission?.status === 'unpublished');
    });

    // Compute header color based on submission status
    // Non-most-recent historical versions get gray header
    const headerClass = computed(() => {
        // Non-most-recent (historical) versions get gray header
        if (isNotMostRecentVersion.value) {
            return 'bg-gray-500';
        }

        if (!props.submission?.status) {
            return 'bg-sky-800'; // Default for legacy
        }

        const statusColors = {
            'draft_new': 'bg-amber-700',          // Amber for draft (matches Job header)
            'submitted_new': 'bg-blue-700',        // Blue for submitted
            'published': 'bg-green-700',           // Green for published/processed
            'draft_republish': 'bg-amber-700',    // Amber for draft (matches Job header)
            'submitted_republish': 'bg-blue-700',  // Blue for submitted
            'draft_unpublish': 'bg-amber-700',    // Amber for draft (matches Job header)
            'submitted_unpublish': 'bg-blue-700',  // Blue for submitted (matches job state)
            'unpublished': 'bg-green-700'          // Green for processed
        };

        return statusColors[props.submission.status] || 'bg-sky-800';
    });
</script>

<template>
    <AppLayout title="Submission" :header-class="headerClass">
        <template #header>
            <div class="font-semibold text-4xl text-white leading-tight grid grid-cols-10">
                <div class="col-span-2">
                    Submission:
                </div>
                <div class="text-left col-span-6 pl-2">
                    <p class="font-black text-2xl inline-block align-bottom leading-5">
                        {{ submission.display_id || submission.sid }}
                        <span v-if="isNotMostRecentVersion" class="text-sm font-normal ml-2 bg-gray-700 px-2 py-1 rounded align-middle">
                            <i class="pi pi-history mr-1"></i>Version {{ submission.version_number || 1 }} (Historical)
                        </span>
                        <br>
                        <Link :href="'/jobs/' + submission.job.ident" class="text-sm underline font-normal">{{ submission.job.slug }}</Link>
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
