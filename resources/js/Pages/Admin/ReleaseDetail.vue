<script setup>
    import { computed } from 'vue'
    import { Link } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import Tag from 'primevue/tag'

    const props = defineProps(['release'])

    const formatDate = (iso) => {
        if (!iso) return '—'
        const d = new Date(iso)
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
            + ' at ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    }

    const formatDuration = (seconds) => {
        if (!seconds) return '—'
        if (seconds < 60) return `${seconds} seconds`
        const m = Math.floor(seconds / 60)
        const s = seconds % 60
        return s > 0 ? `${m}m ${s}s` : `${m} minutes`
    }

    const hasErrors = computed(() => props.release.errors && props.release.errors.length > 0)
    const hasJobs = computed(() => props.release.jobs_processed && props.release.jobs_processed.length > 0)
    const hasBySubmitter = computed(() => props.release.by_submitter && Object.keys(props.release.by_submitter).length > 0)
    const hasCumulativeStats = computed(() => props.release.cumulative_stats && Object.keys(props.release.cumulative_stats).length > 0)

    const bySubmitterRows = computed(() => {
        if (!props.release.by_submitter) return []
        return Object.entries(props.release.by_submitter).map(([name, stats]) => ({
            name,
            new: stats.new || 0,
            republish: stats.republish || 0,
            unpublish: stats.unpublish || 0,
        }))
    })
</script>

<template>
    <AppLayout title="Release Detail">
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.releases')" class="text-white hover:text-gray-200">
                    <i class="pi pi-arrow-left"></i>
                </Link>
                <h2 class="font-semibold text-4xl text-white leading-tight">
                    Release {{ release.slug }}
                </h2>
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Summary Card -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6">
                            <div>
                                <div class="text-sm text-gray-500">Release ID</div>
                                <div class="text-lg font-mono font-semibold">{{ release.slug }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Date</div>
                                <div class="text-lg">{{ formatDate(release.released_at) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Triggered By</div>
                                <div class="text-lg">{{ release.user_name }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Duration</div>
                                <div class="text-lg">{{ formatDuration(release.duration_seconds) }}</div>
                            </div>
                        </div>

                        <!-- Counts -->
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <div class="bg-green-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-green-700">{{ release.new_count }}</div>
                                <div class="text-sm text-green-600">New</div>
                            </div>
                            <div class="bg-blue-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-blue-700">{{ release.republish_count }}</div>
                                <div class="text-sm text-blue-600">Republished</div>
                            </div>
                            <div class="bg-orange-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-orange-700">{{ release.unpublish_count }}</div>
                                <div class="text-sm text-orange-600">Unpublished</div>
                            </div>
                            <div :class="release.failed_count > 0 ? 'bg-red-50' : 'bg-gray-50'" class="rounded-lg p-4 text-center">
                                <div :class="release.failed_count > 0 ? 'text-red-700' : 'text-gray-700'" class="text-2xl font-bold">{{ release.failed_count }}</div>
                                <div :class="release.failed_count > 0 ? 'text-red-600' : 'text-gray-600'" class="text-sm">Failed</div>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-gray-700">{{ release.total_count }}</div>
                                <div class="text-sm text-gray-600">Total</div>
                            </div>
                        </div>

                        <!-- Files -->
                        <div v-if="release.release_notes_file || release.submissions_csv_file" class="mt-6 pt-4 border-t border-gray-200">
                            <h3 class="text-sm font-medium text-gray-500 mb-2">Generated Files</h3>
                            <div class="flex gap-4 text-sm">
                                <span v-if="release.release_notes_file" class="text-gray-700">
                                    <i class="pi pi-file mr-1"></i> {{ release.release_notes_file }}
                                </span>
                                <span v-if="release.submissions_csv_file" class="text-gray-700">
                                    <i class="pi pi-file mr-1"></i> {{ release.submissions_csv_file }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jobs Processed -->
                <div v-if="hasJobs" class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-lg font-semibold mb-4">Jobs Processed</h3>
                        <DataTable
                            :value="release.jobs_processed"
                            :paginator="release.jobs_processed.length > 10"
                            :rows="10"
                            stripedRows
                        >
                            <Column field="slug" header="Job ID" style="min-width: 8rem">
                                <template #body="{ data }">
                                    <span class="font-mono">{{ data.slug }}</span>
                                </template>
                            </Column>
                            <Column field="submitter_name" header="Submitter" style="min-width: 14rem" />
                            <Column field="submission_count" header="Submissions" style="min-width: 6rem" />
                            <Column field="failed_count" header="Failed" style="min-width: 4rem">
                                <template #body="{ data }">
                                    <Tag v-if="data.failed_count > 0" :value="String(data.failed_count)" severity="danger" />
                                    <span v-else>0</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </div>

                <!-- Errors -->
                <div v-if="hasErrors" class="bg-white overflow-hidden shadow-xl sm:rounded-lg border-l-4 border-red-500">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-lg font-semibold text-red-700 mb-4">
                            <i class="pi pi-exclamation-triangle mr-2"></i>
                            Errors ({{ release.errors.length }})
                        </h3>
                        <DataTable
                            :value="release.errors"
                            :paginator="release.errors.length > 10"
                            :rows="10"
                            stripedRows
                        >
                            <Column field="submission_sid" header="Submission" style="min-width: 8rem">
                                <template #body="{ data }">
                                    <span class="font-mono">{{ data.submission_sid }}</span>
                                </template>
                            </Column>
                            <Column field="submitter_name" header="Submitter" style="min-width: 10rem" />
                            <Column field="error_message" header="Error" style="min-width: 14rem">
                                <template #body="{ data }">
                                    <span class="text-red-600 text-sm">{{ data.error_message }}</span>
                                </template>
                            </Column>
                            <Column field="original_job_slug" header="Original Job" style="min-width: 8rem">
                                <template #body="{ data }">
                                    <span class="font-mono">{{ data.original_job_slug }}</span>
                                </template>
                            </Column>
                            <Column field="draft_job_slug" header="New Draft Job" style="min-width: 8rem">
                                <template #body="{ data }">
                                    <span v-if="data.draft_job_slug" class="font-mono">{{ data.draft_job_slug }}</span>
                                    <span v-else class="text-gray-400">—</span>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </div>

                <!-- By Submitter Breakdown -->
                <div v-if="hasBySubmitter" class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-lg font-semibold mb-4">Breakdown by Submitter</h3>
                        <DataTable
                            :value="bySubmitterRows"
                            :paginator="bySubmitterRows.length > 10"
                            :rows="10"
                            sortField="name"
                            :sortOrder="1"
                            stripedRows
                        >
                            <Column field="name" header="Submitter" sortable style="min-width: 14rem" />
                            <Column field="new" header="New" sortable style="min-width: 4rem" />
                            <Column field="republish" header="Republish" sortable style="min-width: 5rem" />
                            <Column field="unpublish" header="Unpublish" sortable style="min-width: 5rem" />
                        </DataTable>
                    </div>
                </div>

                <!-- Cumulative Stats -->
                <div v-if="hasCumulativeStats" class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-lg font-semibold mb-4">Cumulative Statistics at Release</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div v-for="(value, key) in release.cumulative_stats" :key="key" class="bg-gray-50 rounded-lg p-3">
                                <div class="text-xl font-bold text-gray-700">{{ typeof value === 'number' ? value.toLocaleString() : value }}</div>
                                <div class="text-xs text-gray-500">{{ key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
