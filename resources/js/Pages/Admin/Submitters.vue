<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import { FilterMatchMode } from 'primevue/api'
    import { useToast } from "primevue/usetoast"
    import Checkbox from 'primevue/checkbox'
    import Tag from 'primevue/tag'

    const props = defineProps(['submitters'])

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
            case 9: return 'Removed'
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
    const newSubmitter = ref({ name: '', description: '', website: '', assertion: '' })
    const addErrors = ref({})

    async function addSubmitter() {
        addErrors.value = {}
        try {
            const response = await axios.post('/api/admin/submitters', newSubmitter.value)
            if (response.data.success) {
                showAddDialog.value = false
                newSubmitter.value = { name: '', description: '', website: '', assertion: '' }
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
                            :globalFilterFields="['name', 'curie', 'website']"
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
                            <Column field="website" header="Website" style="min-width: 12rem">
                                <template #body="{ data }">
                                    <a v-if="data.website" :href="data.website" target="_blank" class="text-blue-600 hover:underline" @click.stop>
                                        {{ data.website }}
                                    </a>
                                    <span v-else class="text-gray-400">—</span>
                                </template>
                            </Column>
                            <Column field="users_count" header="Users" sortable style="min-width: 6rem" />
                            <Column field="member" header="Member" sortable style="min-width: 6rem">
                                <template #body="{ data }">
                                    <i v-if="data.member" class="pi pi-check text-green-600"></i>
                                    <i v-else class="pi pi-times text-gray-400"></i>
                                </template>
                            </Column>
                            <Column field="downloadable" header="Downloadable" sortable style="min-width: 7rem">
                                <template #body="{ data }">
                                    <i v-if="data.downloadable" class="pi pi-check text-green-600"></i>
                                    <i v-else class="pi pi-times text-gray-400"></i>
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
        <Dialog v-model:visible="showAddDialog" modal header="Add New Submitter" :style="{ width: '40rem' }">
            <div class="grid grid-cols-4 gap-y-4">
                <div class="flex items-center"><label class="font-semibold">Name</label></div>
                <div class="col-span-3">
                    <InputText v-model="newSubmitter.name" class="w-full" />
                    <small v-if="addErrors.name" class="text-red-600">{{ addErrors.name[0] }}</small>
                </div>

                <div class="flex items-start pt-2"><label class="font-semibold">Description</label></div>
                <div class="col-span-3">
                    <Textarea v-model="newSubmitter.description" class="w-full" rows="3" />
                </div>

                <div class="flex items-center"><label class="font-semibold">Website</label></div>
                <div class="col-span-3">
                    <InputText v-model="newSubmitter.website" class="w-full" placeholder="https://example.org" />
                    <small v-if="addErrors.website" class="text-red-600">{{ addErrors.website[0] }}</small>
                </div>

                <div class="flex items-center"><label class="font-semibold">Assertion Criteria</label></div>
                <div class="col-span-3">
                    <InputText v-model="newSubmitter.assertion" class="w-full" placeholder="https://example.org/criteria" />
                    <small v-if="addErrors.assertion" class="text-red-600">{{ addErrors.assertion[0] }}</small>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showAddDialog = false" />
                <Button label="Create" icon="pi pi-check" @click="addSubmitter" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
