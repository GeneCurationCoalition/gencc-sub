<script setup>
    import ApplicationLogo from '@/Components/ApplicationLogo.vue';
    import Fieldset from 'primevue/fieldset';
    import Divider from 'primevue/divider';
    import VueApexCharts from "vue3-apexcharts";
    import Message from 'primevue/message';
    import Tag from 'primevue/tag';
    import Button from 'primevue/button';
    import { computed, ref } from 'vue';
    import { Link, router } from '@inertiajs/vue3';
    import axios from 'axios';
    import { useToast } from 'primevue/usetoast';

    const props = defineProps(['total_jobs_processing', 'total_submissions_processing', 'active_new_count', 'active_republish_count', 'active_unpublish_count', 'token_expire_date', 'total_submissions_published',
                               'token_days', 'job_labels', 'classifications', 'pending_classifications', 'delta_new_counts', 'delta_republish_unpublished_counts', 'delta_unpublish_counts', 'has_pending_changes', 'submissions_new', 'submissions_republished', 'submissions_unpublished_chart', 'total_jobs_errors', 'total_submissions_errors',
                                'total_jobs_completed', 'total_submissions_unpublished',
                                'unprocessed_job_status', 'unprocessed_job_date', 'unprocessed_job_slug', 'unprocessed_job_ident', 'unprocessed_job_is_publishing', 'unprocessed_job_is_processing',
                                'unprocessed_new_count', 'unprocessed_republish_count', 'unprocessed_unpublish_count', 'unprocessed_error_count', 'unprocessed_new_error_count', 'unprocessed_republish_error_count', 'unprocessed_unpublish_error_count', 'has_submitter',
                                'total_unique_sids',
                                // Section 1: Submissions Released
                                'released_first_version_count', 'released_republish_count', 'released_unpublish_count', 'released_total',
                                // Section 2: Submissions Awaiting Release
                                'awaiting_first_version_count', 'awaiting_republish_count', 'awaiting_unpublish_count', 'awaiting_total',
                                // Section 3: Submissions Archived
                                'archived_first_version_unique', 'archived_republish_unique', 'archived_unpublish_unique',
                                'archived_first_version_total', 'archived_republish_total', 'archived_unpublish_total',
                                'archived_unique_total', 'archived_total'])

    const seriesColors = ['#3b82f6', '#ef4444']; // Published (blue), Unpublished (red)

    const options = {
            chart: {
                id: 'vuechart-example',
                type: 'bar',
                stacked: false,
                toolbar: {
                    show: true
                }
            },
            xaxis: {
                categories: props.job_labels,
                labels: {
                    rotate: -45,
                    rotateAlways: true,
                    style: {
                        fontSize: '10px'
                    }
                }
            },
            title: {
                text: "Last 5 Released Jobs"
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '80%',
                    dataLabels: {
                        position: 'top',
                        hideOverflowingLabels: false
                    }
                }
            },
            dataLabels: {
                enabled: true,
                formatter: function (val) {
                    return val > 0 ? val : '';
                },
                style: {
                    fontSize: '11px',
                    fontWeight: 'bold',
                    colors: seriesColors
                },
                offsetY: -20,
                dropShadow: {
                    enabled: true,
                    top: 1,
                    left: 1,
                    blur: 3,
                    color: '#ffffff',
                    opacity: 0.9
                }
            },
            tooltip: {
                enabled: true,
                shared: true,
                intersect: false,
                followCursor: false,
                fixed: {
                    enabled: false
                },
                custom: function({series, seriesIndex, dataPointIndex, w}) {
                    // When shared=true and intersect=false, show all series for this data point
                    if (dataPointIndex === undefined || dataPointIndex === -1) {
                        return '';
                    }

                    const jobLabel = w.globals.labels[dataPointIndex];
                    let html = '<div class="apexcharts-tooltip-custom" style="padding: 8px; background: white; border: 1px solid #e3e3e3; border-radius: 4px;">';
                    html += '<div style="font-weight: bold; margin-bottom: 4px;">' + jobLabel.replace('\n', ' ') + '</div>';

                    // Show all three series values
                    for (let i = 0; i < series.length; i++) {
                        const value = series[i][dataPointIndex];
                        if (value > 0) {
                            const seriesName = w.globals.seriesNames[i];
                            html += '<div style="color: ' + seriesColors[i] + '; font-weight: bold; margin-top: 2px;">' +
                                seriesName + ': ' + value + '</div>';
                        }
                    }

                    html += '</div>';
                    return html;
                }
            },
            yaxis: {
                min: 0,
                forceNiceScale: false,
                title: {
                    text: 'Submission Count'
                }
            },
            colors: seriesColors,
            legend: {
                show: true,
                position: 'top'
            },
            states: {
                hover: {
                    filter: {
                        type: 'darken',
                        value: 0.15
                    }
                },
                active: {
                    filter: {
                        type: 'darken',
                        value: 0.35
                    }
                }
            }
    };

    // Combine New and Republished into Published
    const publishedData = computed(() => {
        return props.submissions_new.map((val, idx) => val + (props.submissions_republished[idx] || 0));
    });

    const series = computed(() => [{
            name: 'Published',
            data: publishedData.value
    }, {
            name: 'Unpublished',
            data: props.submissions_unpublished_chart
    }]);

    // Classification chart colors - one for each classification type
    // These match the original distributed bar colors
    const classificationColors = ['#276749', '#38a169', '#68d391', '#63b3ed', '#fc8181', '#e53e3e', '#f6ad55', '#718096', '#a0aec0'];

    // Chart options for grouped bars (current vs pending)
    const options2 = computed(() => ({
            chart: {
                id: 'classification-chart',
                type: 'bar',
                stacked: false
            },
            xaxis: {
                categories: ['Definitive', 'Strong', 'Moderate', 'Supportive', 'Limited', 'Disputed', 'Refuted', 'Animal', "NKDR"]
            },
            title: {
                text: props.has_pending_changes ? "Classifications (Current vs After Pending)" : "Classifications"
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                    // distributed: true gives each bar its own color (for single series)
                    // distributed: false groups bars by series (for comparison)
                    distributed: !props.has_pending_changes,
                    dataLabels: {
                        position: 'top'
                    }
                },
            },
            dataLabels: {
                enabled: true,
                formatter: function (val, opts) {
                    if (!props.has_pending_changes) {
                        return val > 0 ? val : '';
                    }

                    // Two-series mode: Show single combined label on the taller bar
                    const currentVal = props.classifications[opts.dataPointIndex] || 0;
                    const pendingVal = props.pending_classifications[opts.dataPointIndex] || 0;
                    const diff = pendingVal - currentVal;

                    // Determine which bar is taller (or equal - show on current)
                    const currentIsTaller = currentVal >= pendingVal;

                    if (opts.seriesIndex === 0) {
                        // Current Live bar
                        if (currentIsTaller) {
                            // Show combined label: "999" or "999(+50)" or "999(-2)"
                            if (diff === 0) {
                                return currentVal > 0 ? currentVal : '';
                            }
                            const diffStr = diff > 0 ? `+${diff}` : diff.toString();
                            return currentVal > 0 ? `${currentVal}(${diffStr})` : '';
                        }
                        return ''; // Don't show label on shorter bar
                    } else {
                        // After Pending bar
                        if (!currentIsTaller && diff !== 0) {
                            // Pending bar is taller - show combined label
                            const diffStr = diff > 0 ? `+${diff}` : diff.toString();
                            return currentVal > 0 ? `${currentVal}(${diffStr})` : `0(${diffStr})`;
                        }
                        return ''; // Don't show label on shorter bar or if no change
                    }
                },
                offsetY: -20,
                style: {
                    fontSize: '11px',
                    colors: ['#304758']
                }
            },
            legend: {
                show: props.has_pending_changes,
                position: 'top'
            },
            // Use different colors for current vs pending
            colors: props.has_pending_changes
                ? ['#3b82f6', '#93c5fd']  // Blue (current) and light blue (pending)
                : classificationColors,
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val, opts) {
                        if (props.has_pending_changes && opts.seriesIndex === 1) {
                            // For pending series, show the actual pending total and breakdown
                            const idx = opts.dataPointIndex;
                            const currentVal = props.classifications[idx] || 0;
                            const pendingVal = props.pending_classifications[idx] || 0;

                            // If no change, don't show anything for this series
                            if (pendingVal === currentVal) {
                                return null;
                            }

                            // Build breakdown string
                            const newCount = props.delta_new_counts?.[idx] || 0;
                            const republishCount = props.delta_republish_unpublished_counts?.[idx] || 0;
                            const unpublishCount = props.delta_unpublish_counts?.[idx] || 0;

                            let parts = [];
                            if (newCount > 0) parts.push(`+${newCount} new`);
                            if (republishCount > 0) parts.push(`+${republishCount} republish`);
                            if (unpublishCount > 0) parts.push(`-${unpublishCount} unpublish`);

                            const diff = pendingVal - currentVal;
                            const diffStr = diff > 0 ? `+${diff}` : diff.toString();

                            if (parts.length > 0) {
                                return `${pendingVal} (${parts.join(', ')} = ${diffStr})`;
                            }
                            return `${pendingVal} (${diffStr})`;
                        }
                        return val;
                    }
                }
            }
    }));

    // Series data - show both current and pending if there are changes
    const classificationSeries = computed(() => {
        // Calculate totals
        const currentTotal = props.classifications?.reduce((sum, val) => sum + (val || 0), 0) || 0;
        const pendingTotal = props.pending_classifications?.reduce((sum, val) => sum + (val || 0), 0) || 0;

        if (props.has_pending_changes) {
            // For "After Pending" series, only show bars where there's a difference
            const pendingData = props.pending_classifications.map((pending, index) => {
                const current = props.classifications[index] || 0;
                // Return the pending value if different, otherwise 0 (no bar shown)
                return pending !== current ? pending : 0;
            });

            return [
                {
                    name: `Current Live (${currentTotal.toLocaleString()})`,
                    data: props.classifications
                },
                {
                    name: `After Pending (${pendingTotal.toLocaleString()})`,
                    data: pendingData
                }
            ];
        }
        // No pending changes - show single series with distributed colors
        return [{
            name: `Live Submissions (${currentTotal.toLocaleString()})`,
            data: props.classifications
        }];
    });

    // Computed property for status tag styling
    const statusTagSeverity = computed(() => {
        if (!props.unprocessed_job_status) return 'info';

        if (props.unprocessed_job_status === 'draft') {
            return 'warning'; // Orange/amber for draft
        } else if (props.unprocessed_job_status === 'submitted') {
            return 'info'; // Blue for submitted
        }
        return 'info';
    });

    // Toast for notifications
    const toast = useToast();
    const isCreatingJob = ref(false);
    const isUploadingSubmissions = ref(false);

    // Create a new job and navigate to job detail page
    async function createNewJob() {
        isCreatingJob.value = true;
        try {
            console.log('[Dashboard] Creating new job...');
            const response = await axios.get('/api/jobs/create');
            console.log('[Dashboard] Job creation response:', response.data);

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                const jobIdent = response.data.job.ident;
                console.log('[Dashboard] Job created successfully with ident:', jobIdent);

                toast.add({
                    severity: 'success',
                    summary: 'Job Created',
                    detail: `Draft job ${response.data.job.slug} created successfully`,
                    life: 3000
                });

                // Redirect to the job detail page
                console.log('[Dashboard] Redirecting to job detail page:', `/jobs/${jobIdent}`);
                router.visit(`/jobs/${jobIdent}`);
            } else {
                console.error('[Dashboard] Job creation failed with response:', response.data);
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: 'Failed to create job',
                    life: 3000
                });
            }
        } catch (error) {
            console.error('[Dashboard] Job creation error:', error);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: error.response?.data?.message || 'Failed to create job',
                life: 5000
            });
        } finally {
            isCreatingJob.value = false;
        }
    }

    // Create job (or use existing draft) and trigger upload flow
    async function uploadSubmissions() {
        isUploadingSubmissions.value = true;
        try {
            // If there's an existing draft job (unprocessed_job_status === 'draft'), use it
            if (props.unprocessed_job_status === 'draft' && props.unprocessed_job_ident) {
                console.log('[Dashboard Upload] Found existing draft job:', props.unprocessed_job_ident);
                // Set flag to auto-trigger upload
                sessionStorage.setItem('autoTriggerUpload', 'true');
                console.log('[Dashboard Upload] Set autoTriggerUpload flag in sessionStorage');
                console.log('[Dashboard Upload] Redirecting to existing job:', `/jobs/${props.unprocessed_job_ident}`);
                router.visit(`/jobs/${props.unprocessed_job_ident}`);
                return;
            }

            // Otherwise, create a new job
            console.log('[Dashboard Upload] Creating new job for upload...');
            const response = await axios.get('/api/jobs/create');
            console.log('[Dashboard Upload] Job creation response:', response.data);

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                // Job created successfully, get the job ident
                const jobIdent = response.data.job.ident;
                console.log('[Dashboard Upload] Job created successfully with ident:', jobIdent);

                // Set a flag in sessionStorage to auto-trigger upload on the job page
                sessionStorage.setItem('autoTriggerUpload', 'true');
                console.log('[Dashboard Upload] Set autoTriggerUpload flag in sessionStorage');

                toast.add({
                    severity: 'success',
                    summary: 'Job Created',
                    detail: `Draft job ${response.data.job.slug} created successfully`,
                    life: 3000
                });

                // Redirect to the job detail page
                console.log('[Dashboard Upload] Redirecting to job detail page:', `/jobs/${jobIdent}`);
                router.visit(`/jobs/${jobIdent}`);
            } else {
                console.error('[Dashboard Upload] Job creation failed with response:', response.data);
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: 'Failed to create job',
                    life: 3000
                });
            }
        } catch (error) {
            console.error('[Dashboard Upload] Job creation error:', error);
            console.error('[Dashboard Upload] Error response:', error.response?.data);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: error.response?.data?.message || 'Failed to create job',
                life: 5000
            });
        } finally {
            isUploadingSubmissions.value = false;
        }
    }

    // Check if draft job can be submitted
    const canSubmitJob = computed(() => {
        if (props.unprocessed_job_status !== 'draft') return false;
        if (!props.unprocessed_job_ident) return false;
        // Has at least one submission
        const totalSubmissions = (props.unprocessed_new_count || 0) +
                                 (props.unprocessed_republish_count || 0) +
                                 (props.unprocessed_unpublish_count || 0);
        if (totalSubmissions === 0) return false;
        // No errors
        if ((props.unprocessed_error_count || 0) > 0) return false;
        return true;
    });

    const isSubmittingJob = ref(false);

    async function submitJob() {
        if (!props.unprocessed_job_slug) return;

        isSubmittingJob.value = true;
        try {
            const response = await axios.post('/api/jobs/submit/' + props.unprocessed_job_slug);

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                toast.add({
                    severity: 'success',
                    summary: 'Job Submitted',
                    detail: 'Job has been submitted successfully',
                    life: 3000
                });
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
        } finally {
            isSubmittingJob.value = false;
        }
    }

</script>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">
            <!--<ApplicationLogo class="block h-12 w-auto" /> -->

            <!-- API Token expiration warning - only show if 60 days or less -->
            <div v-if="token_days <= 60" :class="token_days <= 30 ? 'bg-red-100 border-red-700 text-red-800' : 'bg-amber-100 border-amber-700 text-amber-800'" class="border-l-4 p-4 mb-2" role="alert">
                <p class="font-bold">API Token expires {{ token_expire_date }}
                <span class="ml-4">({{ token_days }} days)</span>
                </p>
            </div>

            <Divider v-if="token_days <= 60" />
            <Divider v-else class="mt-0" />

            <div class="grid grid-cols-2 gap-4">
                <div class="">
                    <Fieldset legend="Submission Statistics" :pt="{ content: { class: 'pt-0' } }">
                        <div class="m-0">
                            <!-- Header row with total unique SGCs -->
                            <div class="text-center mb-2">
                                <span class="font-semibold">Total Unique SGCs:</span>
                                <span class="text-xl font-bold text-blue-600 ml-2">{{ total_unique_sids }}</span>
                            </div>

                            <!-- Three-column table: Released | Pending | Historic -->
                            <table class="w-full border-collapse text-sm">
                                <thead>
                                    <tr class="border-b-2 border-gray-300">
                                        <th class="text-left py-2 px-2 font-semibold text-green-700">
                                            Released ({{ released_total }})
                                        </th>
                                        <th class="text-left py-2 px-2 font-semibold text-amber-700 border-l border-gray-200">
                                            Pending ({{ awaiting_total }})
                                        </th>
                                        <th class="text-left py-2 px-2 font-semibold text-gray-600 border-l border-gray-200">
                                            Historic ({{ archived_unique_total }})
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <!-- Released Column -->
                                        <td class="py-1 px-2 align-top">
                                            <div class="space-y-1">
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Published:</span>
                                                    <span>{{ released_first_version_count + released_republish_count }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Unpublished:</span>
                                                    <span>{{ released_unpublish_count }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <!-- Pending Column -->
                                        <td class="py-1 px-2 align-top border-l border-gray-200">
                                            <div class="space-y-1">
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">New (v1):</span>
                                                    <span>{{ awaiting_first_version_count }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Republish:</span>
                                                    <span>{{ awaiting_republish_count }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Unpublish:</span>
                                                    <span>{{ awaiting_unpublish_count }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <!-- Historic Column -->
                                        <td class="py-1 px-2 align-top border-l border-gray-200">
                                            <div class="space-y-1">
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Published:</span>
                                                    <span>{{ archived_first_version_unique + archived_republish_unique }}</span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-600">Unpublished:</span>
                                                    <span>{{ archived_unpublish_unique }}</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Fieldset>
                </div>
                <div class="">
                    <Fieldset legend="Pending Job">
                        <div v-if="unprocessed_job_status" class="m-0 relative">
                            <!-- Submit button in upper right -->
                            <div v-if="canSubmitJob" class="absolute top-0 right-0 z-10">
                                <Button
                                    label="Submit"
                                    icon="pi pi-send"
                                    severity="info"
                                    size="small"
                                    :loading="isSubmittingJob"
                                    @click="submitJob"
                                    v-tooltip.top="'Submit job for processing'"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-6">
                            <!-- First Column: Job Info -->
                            <div class="space-y-2">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-semibold">Job:</span>
                                    <Link :href="'/jobs/' + unprocessed_job_ident" class="font-mono text-blue-600 hover:text-blue-800 hover:underline">{{ unprocessed_job_slug }}</Link>
                                    <i v-if="unprocessed_job_is_publishing" class="pi pi-spin pi-spinner text-blue-500 ml-1" title="Publishing..."></i>
                                    <i v-if="unprocessed_job_is_processing" class="pi pi-spin pi-spinner text-amber-500 ml-1" title="Processing upload..."></i>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold">Status:</span>
                                    <Tag :severity="statusTagSeverity" :value="unprocessed_job_status" />
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="font-semibold">Date:</span>
                                    <span class="text-gray-600">{{ unprocessed_job_date }}</span>
                                </div>
                            </div>

                            <!-- Second Column: Counts -->
                            <div class="space-y-2">
                                <div class="flex items-baseline gap-2">
                                    <span class="font-semibold">New (v1):</span>
                                    <span>{{ unprocessed_job_status === 'submitted' ? active_new_count : unprocessed_new_count }}</span>
                                    <i v-if="unprocessed_new_count > 0 && unprocessed_new_error_count > 0" class="pi pi-exclamation-circle text-red-600 ml-1" v-tooltip.top="`${unprocessed_new_error_count} error(s)`"></i>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="font-semibold">Republish:</span>
                                    <span>{{ unprocessed_job_status === 'submitted' ? active_republish_count : unprocessed_republish_count }}</span>
                                    <i v-if="unprocessed_republish_count > 0 && unprocessed_republish_error_count > 0" class="pi pi-exclamation-circle text-red-600 ml-1" v-tooltip.top="`${unprocessed_republish_error_count} error(s)`"></i>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="font-semibold">Unpublish:</span>
                                    <span>{{ unprocessed_job_status === 'submitted' ? active_unpublish_count : unprocessed_unpublish_count }}</span>
                                    <i v-if="unprocessed_unpublish_count > 0 && unprocessed_unpublish_error_count > 0" class="pi pi-exclamation-circle text-red-600 ml-1" v-tooltip.top="`${unprocessed_unpublish_error_count} error(s)`"></i>
                                </div>
                            </div>
                            </div>
                        </div>
                        <div v-else class="m-0">
                            <div v-if="has_submitter">
                                <div class="text-gray-600 mb-4">
                                    No pending job. Get started by creating a new draft job:
                                </div>
                                <div class="flex gap-3">
                                    <Button
                                        label="Upload Submissions"
                                        icon="pi pi-upload"
                                        severity="success"
                                        size="small"
                                        :loading="isUploadingSubmissions"
                                        @click="uploadSubmissions"
                                        title="Upload a spreadsheet to create a new job"
                                    />
                                    <Button
                                        label="Create a New Job"
                                        icon="pi pi-plus-circle"
                                        severity="info"
                                        size="small"
                                        :loading="isCreatingJob"
                                        @click="createNewJob"
                                        title="Create a new empty job"
                                    />
                                </div>
                            </div>
                            <div v-else class="text-gray-600">
                                No pending job.
                            </div>
                        </div>
                    </Fieldset>
                </div>
            </div>

            <Divider />

            <div class="grid grid-cols-2 gap-4">
                <div class="">
                    <VueApexCharts width="500" type="bar" :options="options" :series="series"></VueApexCharts>
                </div>
                <div class="">
                    <VueApexCharts width="500" type="bar" :options="options2" :series="classificationSeries"></VueApexCharts>
                </div>
            </div>


        </div>            

    </div>
</template>
