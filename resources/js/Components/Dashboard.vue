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
                               'token_days', 'job_labels', 'classifications', 'submissions_new', 'submissions_republished', 'submissions_unpublished_chart', 'total_jobs_errors', 'total_submissions_errors',
                                'total_jobs_completed', 'total_submissions_unpublished',
                                'unprocessed_job_status', 'unprocessed_job_date', 'unprocessed_job_slug', 'unprocessed_job_ident', 'unprocessed_job_is_publishing', 'unprocessed_job_is_processing',
                                'unprocessed_new_count', 'unprocessed_republish_count', 'unprocessed_unpublish_count', 'unprocessed_error_count', 'unprocessed_new_error_count', 'unprocessed_republish_error_count', 'unprocessed_unpublish_error_count', 'has_submitter'])

    const seriesColors = ['#22c55e', '#3b82f6', '#ef4444'];

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
                text: "Last 5 Processed Jobs"
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

    const series = [{
            name: 'New',
            data: props.submissions_new
    }, {
            name: 'Republished',
            data: props.submissions_republished
    }, {
            name: 'Unpublished',
            data: props.submissions_unpublished_chart
    }];

    const options2 = {
            chart: {
                id: 'fskki'
            },
            xaxis: {
                categories: ['Definitive', 'Strong', 'Moderate', 'Supportive', 'Limited', 'Disputed', 'Refuted', 'Animal', "NKDR"]
            },
            title: {
                text: "Classifications"
            },
            plotOptions: {
                bar: {
                    distributed: true,
                    dataLabels: {
                        position: 'top'
                    }
                },
            },
            dataLabels: {
                enabled: true,
                formatter: function (val) {
                    return val > 0 ? val : '';
                },
                offsetY: -20,
                style: {
                    fontSize: '12px',
                    colors: ['#304758']
                }
            },
            legend: {
                show: false
            },
            colors: ['#276749', '#38a169', '#68d391', '#63b3ed', '#fc8181', '#e53e3e', '#f6ad55', '#718096']
    };

    const classifications = [{
            name: 'classifications',
            data: props.classifications
    }];

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

</script>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">
            <!--<ApplicationLogo class="block h-12 w-auto" /> -->

            <!-- API Token expiration warning - only show if 60 days or less -->
            <div v-if="token_days <= 60" :class="token_days <= 30 ? 'bg-red-100 border-red-500 text-red-700' : 'bg-amber-100 border-amber-500 text-amber-700'" class="border-l-4 p-4 mb-2" role="alert">
                <p class="font-bold">API Token expires {{ token_expire_date }}
                <span class="ml-4">({{ token_days }} days)</span>
                </p>
            </div>

            <Divider v-if="token_days <= 60" />
            <Divider v-else class="mt-0" />

            <div class="grid grid-cols-2 gap-4">
                <div class="">
                    <Fieldset legend="Processed Submission History">
                        <div class="m-0 space-y-2">
                            <div class="flex items-baseline gap-2">
                                <span class="font-semibold">Jobs:</span>
                                <span>{{ total_jobs_completed }}</span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="font-semibold">Published:</span>
                                <span>{{ total_submissions_published }}</span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="font-semibold">Unpublished:</span>
                                <span>{{ total_submissions_unpublished }}</span>
                            </div>
                        </div>
                    </Fieldset>
                </div>
                <div class="">
                    <Fieldset legend="Active Job">
                        <div v-if="unprocessed_job_status" class="m-0 grid grid-cols-2 gap-6">
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
                                    <span class="font-semibold">New:</span>
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
                        <div v-else class="m-0">
                            <div v-if="has_submitter">
                                <div class="text-gray-600 mb-4">
                                    No active job. Get started by creating a new draft job:
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
                                No active job.
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
                    <VueApexCharts width="500" type="bar" :options="options2" :series="classifications"></VueApexCharts>
                </div>
            </div>


        </div>            

    </div>
</template>
