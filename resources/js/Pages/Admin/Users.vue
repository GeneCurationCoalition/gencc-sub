<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import { FilterMatchMode } from 'primevue/api'
    import { useToast } from "primevue/usetoast"
    import Checkbox from 'primevue/checkbox'
    import RadioButton from 'primevue/radiobutton'
    import Tag from 'primevue/tag'

    const props = defineProps(['users', 'allSubmitters'])

    const toast = useToast()

    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS }
    })

    const statusLabel = (status) => {
        switch (status) {
            case 0: return 'Initializing'
            case 1: return 'Active'
            case 2: return 'No API'
            case 9: return 'Removed'
            case 20: return 'Locked'
            default: return 'Unknown'
        }
    }

    const statusSeverity = (status) => {
        switch (status) {
            case 1: return 'success'
            case 2: return 'warning'
            case 9: return 'danger'
            case 20: return 'danger'
            default: return 'warning'
        }
    }

    const userType = (user) => {
        if (user.is_admin) return 'Admin'
        if (user.submitters && user.submitters.length > 0) return 'Submitter'
        return ''
    }

    const userTypeSeverity = (user) => {
        if (user.is_admin) return 'info'
        if (user.submitters && user.submitters.length > 0) return 'success'
        return null
    }

    const submitterName = (user) => {
        if (!user.submitters || user.submitters.length === 0) return '—'
        return user.submitters[0].name
    }

    const showAllStatuses = ref(false)

    const filteredUsers = computed(() => {
        if (showAllStatuses.value) return props.users
        return props.users.filter(u => u.status === 1)
    })

    const goToUser = (event) => {
        router.visit(route('admin.users.show', event.data.id))
    }

    // Add User dialog
    const showAddDialog = ref(false)
    const addingUser = ref(false)
    const newUser = ref({
        name: '',
        email: '',
        submitter_id: null,
        is_admin: false,
        title: '',
        phone: '',
    })

    const resetNewUser = () => {
        newUser.value = {
            name: '',
            email: '',
            submitter_id: null,
            is_admin: false,
            title: '',
            phone: '',
        }
    }

    const canAddUser = computed(() => {
        if (!newUser.value.name || !newUser.value.email) return false
        if (!newUser.value.is_admin && !newUser.value.submitter_id) return false
        return true
    })

    const addUser = async () => {
        addingUser.value = true
        try {
            const response = await axios.post('/api/admin/users', newUser.value)
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'User Created', detail: response.data.message, life: 3000 })
                showAddDialog.value = false
                resetNewUser()
                router.reload()
            }
        } catch (error) {
            const data = error.response?.data
            const detail = data?.errors
                ? Object.values(data.errors).flat().join(', ')
                : (data?.message || 'Failed to create user')
            toast.add({ severity: 'error', summary: 'Error', detail, life: 5000 })
        } finally {
            addingUser.value = false
        }
    }
</script>

<template>
    <AppLayout title="Manage Users">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Manage Users
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">

                        <div class="flex justify-between items-center mb-4">
                            <div class="flex items-center gap-4">
                                <InputText v-model="filters['global'].value" placeholder="Search users..." class="w-80" />
                                <div class="flex items-center gap-2">
                                    <Checkbox v-model="showAllStatuses" :binary="true" inputId="show_all" />
                                    <label for="show_all" class="text-sm text-gray-600">Show inactive users</label>
                                </div>
                            </div>
                            <Button label="Add User" icon="pi pi-plus" @click="showAddDialog = true" />
                        </div>

                        <DataTable
                            :value="filteredUsers"
                            :filters="filters"
                            filterDisplay="menu"
                            :globalFilterFields="['name', 'email', 'title', 'clingen_id']"
                            :paginator="filteredUsers.length > 20"
                            :rows="20"
                            sortField="name"
                            :sortOrder="1"
                            :rowHover="true"
                            class="cursor-pointer"
                            @row-click="goToUser"
                            stripedRows
                        >
                            <Column field="name" header="Name" sortable style="min-width: 12rem" />
                            <Column field="email" header="Email" sortable style="min-width: 14rem" />
                            <Column header="Type" sortable :sortFunction="(e) => e.data.sort((a, b) => userType(a).localeCompare(userType(b)) * e.order)" style="min-width: 7rem">
                                <template #body="{ data }">
                                    <Tag v-if="userType(data)" :value="userType(data)" :severity="userTypeSeverity(data)" />
                                    <span v-else class="text-gray-400">—</span>
                                </template>
                            </Column>
                            <Column header="Submitter" style="min-width: 14rem">
                                <template #body="{ data }">
                                    {{ submitterName(data) }}
                                </template>
                            </Column>
                            <Column field="clingen_id" header="ClinGen ID" sortable style="min-width: 10rem" />
                            <Column field="status" header="Status" sortable style="min-width: 7rem">
                                <template #body="{ data }">
                                    <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
                                </template>
                            </Column>

                            <template #empty>
                                <div class="text-center text-gray-500 py-4">No users found.</div>
                            </template>
                        </DataTable>

                    </div>
                </div>
            </div>
        </div>

        <!-- Add User Dialog -->
        <Dialog v-model:visible="showAddDialog" header="Add User" :modal="true" :style="{ width: '500px' }">
            <div class="flex flex-col gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                    <InputText v-model="newUser.name" class="w-full" placeholder="Full name" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                    <InputText v-model="newUser.email" type="email" class="w-full" placeholder="email@example.com" />
                    <small class="text-gray-500">A temporary password will be emailed to this address</small>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Type *</label>
                    <div class="flex gap-4">
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="newUser.is_admin" :value="false" inputId="new_type_submitter" name="new_type" />
                            <label for="new_type_submitter">Submitter</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="newUser.is_admin" :value="true" inputId="new_type_admin" name="new_type" />
                            <label for="new_type_admin">Admin</label>
                        </div>
                    </div>
                </div>

                <div v-if="!newUser.is_admin">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Submitter *</label>
                    <Dropdown
                        v-model="newUser.submitter_id"
                        :options="allSubmitters"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select a submitter"
                        class="w-full"
                        filter
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                    <InputText v-model="newUser.title" class="w-full" placeholder="Job title" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <InputText v-model="newUser.phone" class="w-full" placeholder="Phone number" />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showAddDialog = false" :disabled="addingUser" />
                <Button label="Add User" icon="pi pi-check" @click="addUser" :loading="addingUser"
                    :disabled="!canAddUser" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
