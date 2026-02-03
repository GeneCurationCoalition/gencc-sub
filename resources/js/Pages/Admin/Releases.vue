<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import { FilterMatchMode } from 'primevue/api'
    import Tag from 'primevue/tag'

    const props = defineProps(['releases'])

    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS }
    })

    const formatDate = (iso) => {
        if (!iso) return '—'
        const d = new Date(iso)
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
            + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    }

    const formatDuration = (seconds) => {
        if (!seconds) return '—'
        if (seconds < 60) return `${seconds}s`
        const m = Math.floor(seconds / 60)
        const s = seconds % 60
        return s > 0 ? `${m}m ${s}s` : `${m}m`
    }

    const goToRelease = (event) => {
        router.visit(route('admin.releases.show', event.data.id))
    }
</script>

<template>
    <AppLayout title="Releases">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Releases
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">

                        <div class="flex justify-between items-center mb-4">
                            <InputText v-model="filters['global'].value" placeholder="Search releases..." class="w-80" />
                        </div>

                        <DataTable
                            :value="releases"
                            :filters="filters"
                            filterDisplay="menu"
                            :globalFilterFields="['slug', 'user_name']"
                            :paginator="releases.length > 20"
                            :rows="20"
                            sortField="released_at"
                            :sortOrder="-1"
                            :rowHover="true"
                            class="cursor-pointer"
                            @row-click="goToRelease"
                            stripedRows
                        >
                            <Column field="slug" header="Release ID" sortable style="min-width: 8rem">
                                <template #body="{ data }">
                                    <span class="font-mono font-semibold">{{ data.slug }}</span>
                                </template>
                            </Column>
                            <Column field="released_at" header="Date" sortable style="min-width: 12rem">
                                <template #body="{ data }">
                                    {{ formatDate(data.released_at) }}
                                </template>
                            </Column>
                            <Column field="user_name" header="Triggered By" sortable style="min-width: 8rem" />
                            <Column field="new_count" header="New" sortable style="min-width: 4rem" />
                            <Column field="republish_count" header="Republish" sortable style="min-width: 5rem" />
                            <Column field="unpublish_count" header="Unpublish" sortable style="min-width: 5rem" />
                            <Column field="failed_count" header="Failed" sortable style="min-width: 4rem">
                                <template #body="{ data }">
                                    <Tag v-if="data.failed_count > 0" :value="String(data.failed_count)" severity="danger" />
                                    <span v-else>0</span>
                                </template>
                            </Column>
                            <Column field="total_count" header="Total" sortable style="min-width: 4rem">
                                <template #body="{ data }">
                                    <span class="font-semibold">{{ data.total_count }}</span>
                                </template>
                            </Column>
                            <Column field="jobs_count" header="Jobs" sortable style="min-width: 4rem" />
                            <Column field="duration_seconds" header="Duration" sortable style="min-width: 5rem">
                                <template #body="{ data }">
                                    {{ formatDuration(data.duration_seconds) }}
                                </template>
                            </Column>

                            <template #empty>
                                <div class="text-center text-gray-500 py-4">No releases found.</div>
                            </template>
                        </DataTable>

                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
