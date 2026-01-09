<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import { Link } from '@inertiajs/vue3'
    import JobItem from '@/Components/JobItem.vue';
    import ChangeFriendly from '@/Components/ChangeFriendly.vue';

    const props = defineProps(['job', 'submissions', 'errors', 'favorites', 'hasSubmittedJob'])

    // Compute header class based on job status
    const headerClass = computed(() => {
        if (props.job.status === 'draft') {
            return 'bg-amber-700'; // Amber theme for Draft
        } else if (props.job.status === 'submitted') {
            return 'bg-blue-700'; // Blue theme for Submitted
        } else if (props.job.status === 'processed') {
            return 'bg-green-700'; // Green theme for Processed
        }
        return 'bg-sky-800'; // Default color for legacy statuses
    })

    const dialogTitle = defineModel('dialogTitle');

    const showFriendlyDialog = ref(false);

    function openDialog(type) {

        if (type == 'friendly')
        {
            dialogTitle.value = 'Enter Friendly Job Name';
            showFriendlyDialog.value = true;
        }
    }

    async function updateFriendly(value) {

        try {
            const response = await axios.post('/api/jobs/' + props.job.slug, {
                type: 'friendly',
                curie: value,
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showFriendlyDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }

    }

</script>

<template>
    <AppLayout title="Job" :headerClass="headerClass">
        <template #header>
            <div class="font-semibold text-4xl text-white leading-tight grid grid-cols-10">
                <div class="col-span-1">
                    Job:
                </div>
                <div class="text-left col-span-7 pl-2">
                    <p v-if="job.friendly" class="font-black text-2xl inline-block align-bottom leading-5">
                        {{ job.slug }}
                        <i v-if="job.is_publishing" class="pi pi-spin pi-spinner text-white ml-2" title="Publishing..."></i>
                        <br>
                        <span class="text-sm underline font-normal" @click="openDialog('friendly')">{{ job.friendly }}</span>
                    </p>
                    <p v-else class="font-black text-2xl inline-block align-bottom leading-5">
                        {{ job.slug }}
                        <i v-if="job.is_publishing" class="pi pi-spin pi-spinner text-white ml-2" title="Publishing..."></i>
                        <br>
                        <span class="text-sm underline font-normal" @click="openDialog('friendly')">Add a Label</span>
                    </p>
                </div>
                <div class="text-right text-base col-span-2">
                    <Link href="/jobs"><i class="pi pi-arrow-left"></i> Return to Job List</Link>
                </div>
            </div>

            <div class="col-span-12">
                <ChangeFriendly v-bind:input="job.friendly" v-model:visible="showFriendlyDialog" @input_friendly_close="showFriendlyDialog= false" @input_friendly_item="updateFriendly" header="header" :title="dialogTitle" :style="{ width: '40rem' }"></ChangeFriendly>
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <JobItem :job="job" :submissions="submissions" :errors="errors" :favorites="favorites" :hasSubmittedJob="hasSubmittedJob" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
