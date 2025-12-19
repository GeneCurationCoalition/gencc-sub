<script setup>


    import { ref, computed, onMounted, onUnmounted } from 'vue'
    import { usePage } from '@inertiajs/vue3';
    import { router } from '@inertiajs/vue3'
    import { FilterMatchMode, FilterOperator } from "primevue/api";
    import ConfirmDialog from 'primevue/confirmdialog';
    import { useConfirm } from "primevue/useconfirm";
    import { useToast } from "primevue/usetoast";
    import ToggleButton from 'primevue/togglebutton';
    import Tag from 'primevue/tag';


    const props = defineProps(['jobs', 'favorites', 'blockMessage'])

    const page = usePage()

    const mine = computed(() => page.props.mine)

    const confirm = useConfirm();
    const toast = useToast();

    const filterUser = defineModel(false);

    filterUser.value = false;

    // Check if there are any draft jobs with submissions and no errors that are eligible for submission
    const hasEligibleJobs = computed(() => {
        if (!props.jobs || props.jobs.length === 0) return false;

        return props.jobs.some(job => {
            // Must be draft status (V2 takes precedence)
            const isDraft = job.status ? job.status === 'draft' : job.status === 2;
            if (!isDraft) return false;

            // Must have at least one submission
            if (!job.submissions_count || job.submissions_count === 0) return false;

            // Must not have any errors
            if (job.error_count && job.error_count > 0) return false;

            return true;
        });
    });

    // Check if there are any draft jobs at all
    const hasDraftJobs = computed(() => {
        if (!props.jobs || props.jobs.length === 0) return false;

        return props.jobs.some(job => {
            const isDraft = job.status ? job.status === 'draft' : job.status === 2;
            return isDraft;
        });
    });

    // Check if there are any submitted jobs awaiting processing
    const hasSubmittedJobs = computed(() => {
        if (!props.jobs || props.jobs.length === 0) return false;

        return props.jobs.some(job => {
            const isSubmitted = job.status === 'submitted';
            return isSubmitted;
        });
    });

    // Check if there are any draft jobs with errors
    const hasDraftJobsWithErrors = computed(() => {
        if (!props.jobs || props.jobs.length === 0) return false;

        return props.jobs.some(job => {
            const isDraft = job.status ? job.status === 'draft' : job.status === 2;
            return isDraft && job.error_count && job.error_count > 0;
        });
    });

    // Calculate filtered jobs count
    // This accounts for both the display filter (Show All, Show Active, etc.) and the global search filter
    const filteredJobsCount = computed(() => {
        if (!props.jobs) return 0;

        // First apply the row filter (display options)
        const rowFiltered = props.jobs.filter(rowFilter);

        // Then apply the global filter if present
        if (!filters.value.global.value) {
            return rowFiltered.length;
        }

        const searchTerm = filters.value.global.value.toLowerCase();
        return rowFiltered.filter(job => {
            return (job.slug && job.slug.toLowerCase().includes(searchTerm)) ||
                   (job.friendly && job.friendly.toLowerCase().includes(searchTerm)) ||
                   (job.submission_date && job.submission_date.toLowerCase().includes(searchTerm)) ||
                   (job.status && displayStatusV2(job.status).toLowerCase().includes(searchTerm)) ||
                   (job.status && displayStatus(job.status).toLowerCase().includes(searchTerm));
        }).length;
    });

    function displayStatus(status) {

        switch (status) {
            case 0:
                return 'Initializing';
	 		case 1:
                return 'Newly Submitted';
	 		case 2:
                return 'Processing';
            case 3:
                return 'Completed';
            case 4:
                return 'Processing w/ errors';
            case 5:
                return 'Staged';
	 		case 9:
                return 'Removed';
            case 99:
                return 'Failed';
        }
    }

    // V2 status display for jobs (simplified 3-state model)
    function displayStatusV2(status) {
        const statusMap = {
            'draft': 'Draft',
            'submitted': 'Submitted',
            'processed': 'Processed'
        };
        return statusMap[status] || status;
    }

    // Get severity for job status badge
    function getStatusSeverity(status) {
        const severityMap = {
            'draft': 'warning',
            'submitted': 'info',
            'processed': 'success'
        };
        return severityMap[status] || 'warning';
    }

    // Get custom CSS class for job status badges
    function getJobStatusClass(status) {
        const classMap = {
            'draft': 'job-status-draft',
            'submitted': 'job-status-submitted',
            'processed': 'job-status-processed'
        };
        return classMap[status] || '';
    }

    // Check if a job is currently uploading submissions
    // This includes both queued (validated + is_processing) and actively uploading states
    function isJobUploading(job) {
        if (!job.documents || job.documents.length === 0) return false;
        const uploadState = job.documents[0].upload_state;
        // Check for active upload OR queued upload (validated but job is_processing)
        return uploadState === 'uploading' || (uploadState === 'validated' && job.is_processing);
    }


    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS },
        ident: { value: null, matchMode: FilterMatchMode.CONTAINS },
        submission_date: { value: null, matchMode: FilterMatchMode.CONTAINS }
    })

    const displayOptions = ref([
        { name: 'Show All', option: '1' },
        { name: 'Show Active', option: '4' },
        { name: 'Show Processed', option: '5' },
        { name: 'Show Favorites', option: '6' }
    ]);

    const selectedDisplay = ref('1');

    const axios = window.axios;

    function removeJob(sid) {

        if (sid != '') {

            axios.delete('/api/jobs/' + sid)
                .then(response => {

                    if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                    {
                        // reload the server data
                        router.reload();
                        return true;
                    }
                    else
                    {
                        // Show error toast with message from backend
                        toast.add({
                            severity: 'error',
                            summary: 'Cannot Delete Job',
                            detail: response.data.message || 'Failed to delete job',
                            life: 5000
                        });
                        console.log(response);
                    }
                })
                .catch(error => {
                    console.error(error);
                    toast.add({
                        severity: 'error',
                        summary: 'Error',
                        detail: error.response?.data?.message || 'An error occurred while deleting the job',
                        life: 5000
                    });
                });
        }

        return false;
    }

    const requireConfirmation = (job) => {
        // Check if this job has any republish or unpublish submissions
        const republishCount = job.draft_republish_count || 0;
        const unpublishCount = job.draft_unpublish_count || 0;
        const draftNewCount = job.draft_new_count || 0;
        const totalSubmissions = job.submissions_count || 0;

        let message = 'Please confirm to delete this job.';

        if (republishCount > 0 || unpublishCount > 0 || draftNewCount > 0) {
            message = 'Deleting this draft job will:\n\n';

            // Only include non-zero counts
            if (republishCount > 0) {
                message += `• Restore ${republishCount} republished submission(s)\n`;
            }

            if (unpublishCount > 0) {
                message += `• Restore ${unpublishCount} unpublished submission(s)\n`;
            }

            if (draftNewCount > 0) {
                message += `• Delete ${draftNewCount} new submission(s)\n`;
            }

            if (totalSubmissions > 100) {
                message += '\n⏱️ Note: This job has a large number of submissions and may take a few minutes to delete.\n';
            }

            message += '\nAre you sure you want to continue?';
        }

        confirm.require({
            group: 'headless',
            message: message,
            header: `Delete Job: ${job.slug}`,
            accept: () => {
                removeJob(job.ident);
            },
            reject: () => {
                //
            }
        });
    };

    const submitJob = async (jobId) => {
        try {
            const response = await axios.post('/api/jobs/submit/' + jobId);

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                toast.add({
                    severity: 'success',
                    summary: 'Job Submitted',
                    detail: 'Job has been submitted successfully',
                    life: 3000
                });
                // Reload the page to show updated status
                router.reload();
            } else {
                toast.add({
                    severity: 'error',
                    summary: 'Submission Failed',
                    detail: response.data.message || 'Failed to submit job',
                    life: 5000
                });
            }
        } catch (error) {
            console.error(error);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: error.response?.data?.message || 'An error occurred while submitting the job',
                life: 5000
            });
        }
    };


    async function updateFavorite(jid, toggle) {

        try {
            const response = await axios.post('/api/jobs/' + jid, {
                type: 'favorites',
                value: toggle
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();
                
            }
        } catch (error) {
            console.error(error);
        }     
    }


    function isEmpty(obj) {
        for (const prop in obj) {
            if (Object.hasOwn(obj, prop)) {
                return false;
            }
        }
        return true;
    }

    const dt = ref();

    function rowFilter(item)
    {
        // Helper to check if using V2 status or legacy
        const isDraft = item.status ? item.status === 'draft' : item.status == 2 || item.status == 4;
        const isSubmitted = item.status ? item.status === 'submitted' : item.status == 1 || item.status == 5;
        const isProcessed = item.status ? item.status === 'processed' : item.status == 3;

        if (selectedDisplay.value == 2) {
            // Show Pending (newly submitted)
            if (filterUser.value)
                return (item.user_id == mine.value && isSubmitted)
            else
                return isSubmitted

        }
        else if (selectedDisplay.value == 3) {
            // Show Errors (legacy only - V2 doesn't have error status at job level)
            if (filterUser.value)
                return (item.user_id == mine.value && item.status == 4)
            else
                return (item.status == 4)
        }
        else if (selectedDisplay.value == 4) {
            // Show Active (draft or submitted in V2)
            if (filterUser.value)
                return (item.user_id == mine.value && (isDraft || isSubmitted))
            else
                return (isDraft || isSubmitted)
        }
        else if (selectedDisplay.value == 5) {
            // Show Processed
            if (filterUser.value)
                return (item.user_id == mine.value && isProcessed)
            else
                return isProcessed
        }
        else if (selectedDisplay.value == 6) {
            // Show Favorites
            if (filterUser.value)
                return (props.favorites.includes(item.ident))
            else
                return (item.user_id == mine.value && props.favorites.includes(item.ident))
        }

        if (filterUser.value)
            return (item.user_id == mine.value);

        return true;
    }


    function exportCSV(event)
    {
        // PrimeVue DataTable exportCSV() automatically exports only the filtered data:
        // - Respects :value="jobs?.filter(rowFilter)" for display option filters (Show All, Show Pending, etc.)
        // - Respects v-model:filters for keyword search via globalFilterFields
        // Result: Export includes only rows visible in the current table view
        dt.value.exportCSV();
    }

    function rowStyle(data)
    {
        // No background colors on job rows
        return null;
    }

    // Listen for publish status updates
    // DISABLED: Echo temporarily disabled to eliminate connection delays
    // Will re-enable when needed for publish updates functionality
    let publishChannel = null;

    onMounted(() => {
        // publishChannel = window.Echo.channel('publish-updates')
        //     .listen('.publish.status', (e) => {
        //         console.log('Received publish status update:', e);
        //         // Refresh the jobs list when a publish completes
        //         router.reload({ only: ['jobs'] });
        //     });
    });

    onUnmounted(() => {
        // if (publishChannel) {
        //     window.Echo.leaveChannel('publish-updates');
        // }
    });

</script>

<style>
table tbody tr:hover {
    background: #F8F8F8;
}

.status-tag {
    min-width: 100px;
    max-width: 100px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    white-space: normal;
    text-align: center;
    line-height: 1.2;
}

/* Job Status Colors */
.job-status-draft {
    background-color: #FEF08A !important;  /* Tailwind yellow-200 */
    color: #713F12 !important;             /* Tailwind yellow-950 */
    border-color: #FACC15 !important;      /* Tailwind yellow-400 */
}

.job-status-submitted {
    background-color: #BFDBFE !important;  /* Tailwind blue-200 */
    color: #1E3A8A !important;             /* Tailwind blue-900 */
    border-color: #60A5FA !important;      /* Tailwind blue-400 */
}

.job-status-processed {
    background-color: #86EFAC !important;  /* Tailwind green-300 */
    color: #14532D !important;             /* Tailwind green-950 */
    border-color: #4ADE80 !important;      /* Tailwind green-400 */
}
</style>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <!-- Show yellow success message when there are draft jobs ready for submission -->
            <div v-if="hasEligibleJobs" class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 my-2" role="alert">
                <p class="font-bold">There are draft jobs ready to be submitted for processing. Only one draft job is allowed at any time.</p>
            </div>

            <!-- Show red error warning when there are draft jobs with errors -->
            <div v-if="!hasEligibleJobs && hasDraftJobs && hasDraftJobsWithErrors" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 my-2" role="alert">
                <p class="font-bold">Draft jobs contain submission errors that must be resolved before submission.</p>
            </div>

            <!-- Show yellow warning when there are draft jobs but none are ready (no submissions or other issues) -->
            <div v-if="!hasEligibleJobs && hasDraftJobs && !hasDraftJobsWithErrors" class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 my-2" role="alert">
                <p class="font-bold">Draft jobs exist but are not ready for submission. Draft jobs must have at least one submission with no errors to be submitted.</p>
            </div>

            <!-- Show yellow info message when there are no draft or submitted jobs at all -->
            <div v-if="!hasDraftJobs && !hasSubmittedJobs" class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 my-2" role="alert">
                <p class="font-bold">Create a new job or upload submissions to get started.</p>
            </div>

            <!-- Show info message when there are submitted jobs awaiting processing -->
            <div v-if="hasSubmittedJobs" class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 my-2" role="alert">
                <p class="font-bold">The Submitted job will be processed during the next scheduled processing cycle. A new draft job cannot be created until the current submitted job is processed.</p>
            </div>

            <!-- Show block message when job creation is not allowed -->
            <div v-if="blockMessage" class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 p-4 my-2" role="alert">
                <p class="font-bold">{{ blockMessage }}</p>
            </div>

            <ConfirmDialog group="headless">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-red-500 dark:bg-red-400 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-question text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0 whitespace-pre-line">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button label="Delete Job" @click="acceptCallback" class="bg-red-500 ring-red-500"></Button>
                            <Button label="Cancel" outlined @click="rejectCallback" severity="info"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>
            <Toast />

            <DataTable v-model:filters="filters" ref="dt" :value="jobs?.filter(rowFilter)" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100, 250]" sortField="submission_date" :sortOrder="-1" 
                    :rowStyle="rowStyle" :globalFilterFields="['slug', 'friendly', 'submission_date', 'status']" tableStyle="min-width: 20rem; width: auto;">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-bold">
                            <Button icon="pi pi-download"
                                    label="Download"
                                    @click="exportCSV($event)"
                                    severity="info"
                                    outlined
                                    raised
                                    :disabled="filteredJobsCount === 0" />
                            <span class="ml-3">Total of {{ jobs ? jobs.length : 0 }} jobs. </span>
                        </span>
                        <div class="text-left flex gap-2">
                            <Dropdown v-model="selectedDisplay" :options="displayOptions" optionLabel="name" optionValue="option" class="w-20rem" />
                            <ToggleButton v-model="filterUser" onLabel="User Only" offLabel="Submitter" onIcon="pi pi-user" offIcon="pi pi-sitemap" class="w-12rem" aria-label="Do you confirm" />
                        </div>
                        <IconField iconPosition="left">
                            <InputIcon>
                                <i class="pi pi-search" />
                            </InputIcon>
                            <InputText v-model="filters['global'].value" placeholder="Keyword Search" />
                        </IconField>
                    </div>
                </template>
                <Column field="ident" header="">
                     <template #body="{ data }">
                        <div v-if="props.favorites.includes(data.ident)" class="text-orange-300 text-xl" @click="updateFavorite(data.ident, false)"><i class="pi pi-star-fill" ></i></div>
                        <div v-else class="text-slate-200 text-xl" @click="updateFavorite(data.ident, true)"><i class="pi pi-star"></i></div>
                    </template>
                </Column>
                <Column field="slug" header="Job Id" sortable>
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.slug }}</div>
                        <div v-if="data.friendly" class="font-medium border-t-2">{{ data.friendly }}</div>
                    </template>
                </Column>
                <Column field="ident" header="# Submissions" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.submissions_count }}</div>
                    </template>
                </Column>
                <Column field="processed_count" header="# Processed" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.processed_submission_ids?.length || 0 }}</div>
                    </template>
                </Column>
                <Column field="user.name" header="Created By" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.user?.name || 'N/A' }}</div>
                    </template>
                </Column>
                <Column field="submission_date" header="Submitted" sortable>
                     <template #body="{ data }">
                        {{ new Date(Date.parse(data.submission_date)).toISOString().split('T')[0] }}
                     </template>
                </Column>
                <Column field="status" header="Status" sortable>
                     <template #body="{ data }">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <Tag v-if="data.status" :value="displayStatusV2(data.status)" :severity="getStatusSeverity(data.status)" :class="['status-tag', getJobStatusClass(data.status)]" />
                                <span v-else>{{ displayStatus(data.status) }}</span>
                                <!-- Debug: {{ data.is_publishing }} -->
                                <i v-if="data.is_publishing"
                                   class="pi pi-spin pi-spinner text-blue-500 text-xl"
                                   title="Publishing..."></i>
                                <i v-else-if="isJobUploading(data)"
                                   class="pi pi-spin pi-spinner text-amber-500 text-xl"
                                   title="Processing upload..."></i>
                                <i v-if="data.status === 'draft' && !isJobUploading(data) && (data.error_count > 0 || (data.documents && data.documents.length > 0 && data.documents[0].upload_state === 'validation_failed'))"
                                   class="pi pi-exclamation-triangle text-red-500 text-xl"
                                   :title="data.documents && data.documents.length > 0 && data.documents[0].upload_state === 'validation_failed' ? 'File has validation errors' : `${data.error_count} submission(s) with errors`"></i>
                            </div>
                            <span v-if="isJobUploading(data)" class="text-xs text-blue-600">
                                Uploading - cannot submit yet
                            </span>
                        </div>
                     </template>
                </Column>
                <Column header="Action" style="width: 10%; min-width: 8rem" headerStyle="width: 5rem; text-align: center" bodyStyle="text-align: center; overflow: visible">
                    <template #body="slotProps">
                        <!-- Show submit icon for draft jobs with submissions and no errors (NOT when uploading) -->
                        <span v-if="slotProps.data.status === 'draft' && slotProps.data.submissions_count > 0 && slotProps.data.error_count === 0 && !isJobUploading(slotProps.data)" class="mr-3">
                            <Button @click="submitJob(slotProps.data.slug)"
                                    icon="pi pi-send"
                                    severity="info"
                                    text
                                    raised
                                    rounded
                                    v-tooltip.top="'Submit Job'"></Button>
                        </span>
                        <!-- Show delete icon for all draft jobs (processed jobs preserved for audit history) (NOT when uploading) -->
                        <span v-if="slotProps.data.status === 'draft' && !isJobUploading(slotProps.data)" class="mr-3">
                            <Button @click="requireConfirmation(slotProps.data)"
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    raised
                                    rounded
                                    v-tooltip.top="'Delete Job'"></Button>
                        </span>
                        <a :href="'/jobs/' + slotProps.data.ident"><Button type="button" icon="pi pi-arrow-right" text raised rounded /></a>
                    </template>
                 </Column>
                 <template #footer>Total of {{ jobs ? jobs.length : 0 }} jobs.</template>
            </DataTable>
        </div>

    </div>
</template>
