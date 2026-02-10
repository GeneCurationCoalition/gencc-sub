<script setup>
    import { ref, computed, onMounted } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import { FilterMatchMode } from 'primevue/api'
    import { useToast } from "primevue/usetoast"
    import { useConfirm } from "primevue/useconfirm"
    import ConfirmDialog from 'primevue/confirmdialog'
    import Checkbox from 'primevue/checkbox'
    import Tag from 'primevue/tag'

    const props = defineProps(['submitters'])

    // Refresh data if returning from detail page after an edit
    onMounted(() => {
        if (sessionStorage.getItem('submitterUpdated')) {
            sessionStorage.removeItem('submitterUpdated')
            router.reload({ only: ['submitters'] })
        }
    })

    const confirm = useConfirm()

    const showInactive = ref(false)

    const filteredSubmitters = computed(() => {
        if (showInactive.value) return props.submitters
        return props.submitters.filter(s => s.status !== 9)
    })

    const toast = useToast()

    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS }
    })

    const statusLabel = (status) => {
        switch (status) {
            case 0: return 'Initializing'
            case 1: return 'Active'
            case 9: return 'Inactive'
            default: return 'Unknown'
        }
    }

    const statusSeverity = (status) => {
        switch (status) {
            case 1: return 'success'
            case 9: return 'danger'
            default: return 'warning'
        }
    }

    const goToSubmitter = (event) => {
        router.visit(route('admin.submitters.show', event.data.id))
    }

    // Add Submitter dialog
    const showAddDialog = ref(false)
    const newSubmitter = ref({ name: '', description: '', website: '', assertion: '', downloadable: true })
    const addErrors = ref({})

    async function addSubmitter() {
        addErrors.value = {}
        try {
            const response = await axios.post('/api/admin/submitters', newSubmitter.value)
            if (response.data.success) {
                showAddDialog.value = false
                newSubmitter.value = { name: '', description: '', website: '', assertion: '', downloadable: true }
                toast.add({ severity: 'success', summary: 'Submitter Created', detail: response.data.message, life: 3000 })
                router.reload()
            }
        } catch (error) {
            if (error.response?.status === 422) {
                addErrors.value = error.response.data.errors || {}
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to create submitter', life: 5000 })
            }
        }
    }

    // Check if submitter can be permanently deleted (no jobs)
    function canDelete(submitter) {
        return (submitter.jobs_count || 0) === 0
    }

    // Navigate to submitter detail page for editing
    function editSubmitter(submitter) {
        router.visit(route('admin.submitters.show', submitter.id))
    }

    // Show confirmation for permanent delete
    function confirmDelete(submitter) {
        confirm.require({
            group: 'headless',
            message: `Are you sure you want to permanently delete "${submitter.name}"?\n\nThis action cannot be undone.`,
            header: 'Delete Submitter',
            accept: () => {
                deleteSubmitter(submitter)
            }
        })
    }

    // Permanently delete submitter
    async function deleteSubmitter(submitter) {
        try {
            const response = await axios.delete('/api/admin/submitters/' + submitter.id)
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Submitter Deleted', detail: response.data.message, life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to delete submitter', life: 5000 })
        }
    }

    // Show confirmation for deactivate
    function confirmDeactivate(submitter) {
        confirm.require({
            group: 'headless',
            message: `Are you sure you want to deactivate "${submitter.name}"?\n\nThe submitter will be marked as Removed but data will be preserved.`,
            header: 'Deactivate Submitter',
            accept: () => {
                deactivateSubmitter(submitter)
            }
        })
    }

    // Deactivate submitter (set status to Removed)
    async function deactivateSubmitter(submitter) {
        try {
            const response = await axios.put('/api/admin/submitters/' + submitter.id, {
                name: submitter.name,
                status: 9,  // STATUS_REMOVED
            })
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Submitter Deactivated', detail: 'Submitter has been deactivated.', life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to deactivate submitter', life: 5000 })
        }
    }

    // Reactivate submitter (set status to Active)
    async function reactivateSubmitter(submitter) {
        try {
            const response = await axios.put('/api/admin/submitters/' + submitter.id, {
                name: submitter.name,
                status: 1,  // STATUS_ACTIVE
            })
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Submitter Reactivated', detail: 'Submitter has been reactivated.', life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to reactivate submitter', life: 5000 })
        }
    }
</script>

<template>
    <AppLayout title="Manage Submitters">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Manage Submitters
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">

                        <div class="flex justify-between items-center mb-4">
                            <div class="flex items-center gap-4">
                                <InputText v-model="filters['global'].value" placeholder="Search submitters..." class="w-80" />
                                <div class="flex items-center gap-2">
                                    <Checkbox v-model="showInactive" :binary="true" inputId="show_inactive" />
                                    <label for="show_inactive" class="text-sm text-gray-600">Show inactive</label>
                                </div>
                            </div>
                            <Button icon="pi pi-plus" label="Add Submitter" @click="showAddDialog = true" />
                        </div>

                        <DataTable
                            :value="filteredSubmitters"
                            :filters="filters"
                            filterDisplay="menu"
                            :globalFilterFields="['name', 'curie']"
                            :paginator="filteredSubmitters.length > 20"
                            :rows="20"
                            sortField="name"
                            :sortOrder="1"
                            :rowHover="true"
                            class="cursor-pointer"
                            @row-click="goToSubmitter"
                            stripedRows
                        >
                            <Column field="name" header="Name" sortable style="min-width: 14rem" />
                            <Column field="curie" header="CURIE" sortable style="min-width: 10rem" />
                            <Column field="status" header="Status" sortable style="min-width: 8rem">
                                <template #body="{ data }">
                                    <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
                                </template>
                            </Column>
                            <Column field="jobs_count" header="Jobs" sortable style="width: 5rem; text-align: center">
                                <template #body="{ data }">
                                    <span class="font-medium">{{ data.jobs_count || 0 }}</span>
                                </template>
                            </Column>
                            <Column field="submissions_count" header="Submissions" sortable style="width: 7rem; text-align: center">
                                <template #body="{ data }">
                                    <span class="font-medium">{{ data.submissions_count || 0 }}</span>
                                </template>
                            </Column>
                            <Column field="allow_submissions" header="Allow submissions" sortable style="min-width: 8rem">
                                <template #body="{ data }">
                                    <i v-if="data.allow_submissions" class="pi pi-check text-green-600"></i>
                                    <i v-else class="pi pi-times text-gray-400"></i>
                                </template>
                            </Column>
                            <Column field="downloadable" header="Include in downloads" sortable style="min-width: 9rem">
                                <template #body="{ data }">
                                    <i v-if="data.downloadable" class="pi pi-check text-green-600"></i>
                                    <i v-else class="pi pi-times text-gray-400"></i>
                                </template>
                            </Column>
                            <Column header="Actions" style="width: 10rem" bodyStyle="text-align: center">
                                <template #body="{ data }">
                                    <div class="flex justify-center gap-1" @click.stop>
                                        <!-- Edit button - always visible -->
                                        <Button
                                            icon="pi pi-pencil"
                                            severity="info"
                                            text
                                            rounded
                                            size="small"
                                            @click="editSubmitter(data)"
                                            v-tooltip.top="'Edit'"
                                        />
                                        <!-- Deactivate button - for active submitters -->
                                        <Button
                                            v-if="data.status !== 9"
                                            icon="pi pi-ban"
                                            severity="warning"
                                            text
                                            rounded
                                            size="small"
                                            @click="confirmDeactivate(data)"
                                            v-tooltip.top="'Deactivate'"
                                        />
                                        <!-- Activate button - for deactivated submitters -->
                                        <Button
                                            v-if="data.status === 9"
                                            icon="pi pi-check-circle"
                                            severity="success"
                                            text
                                            rounded
                                            size="small"
                                            @click="reactivateSubmitter(data)"
                                            v-tooltip.top="'Reactivate'"
                                        />
                                        <!-- Delete button - only for submitters with no jobs -->
                                        <Button
                                            v-if="canDelete(data)"
                                            icon="pi pi-trash"
                                            severity="danger"
                                            text
                                            rounded
                                            size="small"
                                            @click="confirmDelete(data)"
                                            v-tooltip.top="'Delete permanently'"
                                        />
                                    </div>
                                </template>
                            </Column>

                            <template #empty>
                                <div class="text-center text-gray-500 py-4">No submitters found.</div>
                            </template>
                        </DataTable>

                    </div>
                </div>
            </div>
        </div>

        <!-- Add Submitter Dialog -->
        <Dialog v-model:visible="showAddDialog" modal header="Add New Submitter" :style="{ width: '36rem' }">
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <label class="font-semibold w-32 shrink-0">Name</label>
                    <div class="flex-1">
                        <InputText v-model="newSubmitter.name" class="w-full" />
                        <small v-if="addErrors.name" class="text-red-600">{{ addErrors.name[0] }}</small>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <label class="font-semibold w-32 shrink-0 pt-2">Description</label>
                    <Textarea v-model="newSubmitter.description" class="flex-1" rows="2" />
                </div>

                <div class="flex items-center gap-3">
                    <label class="font-semibold w-32 shrink-0">Website</label>
                    <div class="flex-1">
                        <InputText v-model="newSubmitter.website" class="w-full" placeholder="https://example.org" />
                        <small v-if="addErrors.website" class="text-red-600">{{ addErrors.website[0] }}</small>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <label class="font-semibold w-32 shrink-0">Assertion Criteria</label>
                    <div class="flex-1">
                        <InputText v-model="newSubmitter.assertion" class="w-full" placeholder="https://example.org/criteria" />
                        <small v-if="addErrors.assertion" class="text-red-600">{{ addErrors.assertion[0] }}</small>
                    </div>
                </div>

                <div class="flex items-center gap-2 mt-1">
                    <Checkbox v-model="newSubmitter.downloadable" :binary="true" inputId="new_downloadable" />
                    <label for="new_downloadable" class="text-gray-700">Include in downloads</label>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button label="Cancel" severity="secondary" @click="showAddDialog = false" />
                    <Button label="Create" icon="pi pi-check" @click="addSubmitter" />
                </div>
            </template>
        </Dialog>

        <!-- Confirm Dialog for Delete/Deactivate -->
        <ConfirmDialog group="headless">
            <template #container="{ message, acceptCallback, rejectCallback }">
                <div class="flex flex-col items-center p-5 bg-white rounded-md">
                    <div class="rounded-full bg-red-700 text-white inline-flex justify-center items-center h-24 w-24 -mt-12">
                        <i class="pi pi-question text-5xl"></i>
                    </div>
                    <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                    <p class="mb-0 whitespace-pre-line text-center">{{ message.message }}</p>
                    <div class="flex items-center gap-4 mt-4">
                        <Button label="Confirm" @click="acceptCallback" class="!bg-red-700 !ring-red-700 hover:!bg-red-800" />
                        <Button label="Cancel" outlined @click="rejectCallback" severity="secondary" />
                    </div>
                </div>
            </template>
        </ConfirmDialog>

        <Toast />
    </AppLayout>
</template>
