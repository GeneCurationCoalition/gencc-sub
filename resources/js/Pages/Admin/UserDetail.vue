<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import { useToast } from "primevue/usetoast"
    import Checkbox from 'primevue/checkbox'
    import RadioButton from 'primevue/radiobutton'
    import Tag from 'primevue/tag'

    const props = defineProps(['user', 'allSubmitters', 'isSelf'])

    const toast = useToast()

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

    // Edit user dialog
    const showEditDialog = ref(false)
    const editForm = ref({})

    function openEdit() {
        editForm.value = {
            name: props.user.name,
            email: props.user.email,
            title: props.user.title || '',
            phone: props.user.phone || '',
            status: props.user.status,
            must_change_password: props.user.must_change_password || false,
            is_admin: props.user.is_admin || false,
            submitter_id: props.user.submitter?.id || null,
            is_contact: props.user.submitter?.is_contact || false,
        }
        showEditDialog.value = true
    }

    async function saveUser() {
        try {
            // Save user fields
            const { is_admin, submitter_id, is_contact, ...userFields } = editForm.value
            const response = await axios.put('/api/admin/users/' + props.user.id, userFields)

            // Save association (type + submitter) — skip for self (cannot change own type)
            if (!props.isSelf) {
                await axios.post('/api/admin/users/' + props.user.id + '/association', {
                    is_admin,
                    submitter_id,
                    is_contact,
                })
            }

            if (response.data.success) {
                showEditDialog.value = false
                toast.add({ severity: 'success', summary: 'User Updated', detail: 'User information has been updated.', life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Update Failed', detail: error.response?.data?.message || 'An error occurred.', life: 5000 })
        }
    }

    // Reactivate user
    async function reactivateUser() {
        try {
            const response = await axios.put('/api/admin/users/' + props.user.id, { status: 1 })
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'User Reactivated', detail: 'User has been reactivated.', life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to reactivate user.', life: 5000 })
        }
    }

    // Delete / Deactivate user
    const showDeactivateDialog = ref(false)

    const canPermanentlyDelete = computed(() => {
        return (props.user.jobs_count || 0) === 0
            && (props.user.submissions_count || 0) === 0
            && !props.user.submitter
            && !props.user.is_admin
    })

    async function deactivateUser() {
        try {
            const response = await axios.delete('/api/admin/users/' + props.user.id)
            if (response.data.success) {
                showDeactivateDialog.value = false
                const summary = response.data.deleted ? 'User Deleted' : 'User Deactivated'
                toast.add({ severity: 'success', summary, detail: response.data.message, life: 3000 })
                router.visit(route('admin.users'))
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to remove user.', life: 5000 })
        }
    }

    function goToSubmitter(submitterId) {
        router.visit(route('admin.submitters.show', submitterId))
    }
</script>

<template>
    <AppLayout title="User Detail">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-4xl text-white leading-tight">
                    User: {{ user.name }}
                </h2>
                <Button icon="pi pi-arrow-left" label="Back to Users" severity="secondary" @click="router.visit(route('admin.users'))" class="bg-white/20 text-white border-white/40 hover:bg-white/30" />
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

                        <div class="grid grid-cols-12 mt-4 gap-0">
                            <div class="col-span-12">
                                <div class="grid grid-cols-12 gap-0">

                                    <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-bold">{{ user.name }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Email:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div>{{ user.email }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">ClinGen ID:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div>{{ user.clingen_id || '—' }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Title:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div>{{ user.title || '—' }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Phone:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div>{{ user.phone || '—' }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Status:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <Tag :value="statusLabel(user.status)" :severity="user.status === 1 ? 'success' : 'danger'" />
                                        <Tag v-if="user.must_change_password" value="Password Change Required" severity="warning" class="ml-2" />
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Created:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div>{{ user.created_at ? new Date(user.created_at).toLocaleDateString() : '—' }}</div>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">Association:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="user.is_admin" class="flex items-center">
                                            <Tag value="Admin Team" severity="info" />
                                        </div>
                                        <div v-else-if="user.submitter" class="flex items-center">
                                            <a @click="goToSubmitter(user.submitter.id)" class="font-bold text-blue-600 hover:underline cursor-pointer">{{ user.submitter.name }}</a>
                                            <span class="text-gray-500 ml-2">({{ user.submitter.curie }})</span>
                                            <span v-if="user.submitter.is_contact" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">Contact</span>
                                        </div>
                                        <div v-else class="text-gray-500">No association</div>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3"></div>
                                    <div class="col-span-10 py-1 my-2 pl-4 flex gap-3">
                                        <Button icon="pi pi-pencil" label="Edit User" @click="openEdit" />
                                        <Button v-if="user.status !== 9 && !isSelf" icon="pi pi-ban" :label="canPermanentlyDelete ? 'Delete' : 'Deactivate'" severity="danger" outlined @click="showDeactivateDialog = true" />
                                        <Button v-if="user.status === 9" icon="pi pi-replay" label="Reactivate" severity="success" outlined @click="reactivateUser" />
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Edit User Dialog -->
        <Dialog v-model:visible="showEditDialog" modal header="Edit User" :style="{ width: '40rem' }">
            <div class="grid grid-cols-4 gap-y-4">
                <div class="flex items-center"><label class="font-semibold">Name</label></div>
                <div class="col-span-3">
                    <InputText v-model="editForm.name" class="w-full" />
                </div>

                <div class="flex items-center"><label class="font-semibold">Email</label></div>
                <div class="col-span-3">
                    <InputText v-model="editForm.email" class="w-full" />
                </div>

                <div class="flex items-center"><label class="font-semibold">Title</label></div>
                <div class="col-span-3">
                    <InputText v-model="editForm.title" class="w-full" />
                </div>

                <div class="flex items-center"><label class="font-semibold">Phone</label></div>
                <div class="col-span-3">
                    <InputText v-model="editForm.phone" class="w-full" />
                </div>

                <div class="flex items-center"><label class="font-semibold">Type</label></div>
                <div class="col-span-3">
                    <div class="flex gap-4 pt-1">
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="editForm.is_admin" :value="false" inputId="edit_type_submitter" name="edit_type" :disabled="isSelf" />
                            <label for="edit_type_submitter">Submitter</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioButton v-model="editForm.is_admin" :value="true" inputId="edit_type_admin" name="edit_type" :disabled="isSelf" />
                            <label for="edit_type_admin">Admin</label>
                        </div>
                    </div>
                    <small v-if="isSelf" class="text-gray-500">You cannot change your own type.</small>
                </div>

                <template v-if="!editForm.is_admin">
                    <div class="flex items-center"><label class="font-semibold">Submitter</label></div>
                    <div class="col-span-3">
                        <Dropdown
                            v-model="editForm.submitter_id"
                            :options="allSubmitters"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Select a submitter"
                            class="w-full"
                            filter
                        >
                            <template #option="slotProps">
                                <div class="flex items-center">
                                    <span>{{ slotProps.option.name }}</span>
                                    <span class="text-gray-500 ml-2">({{ slotProps.option.curie }})</span>
                                </div>
                            </template>
                        </Dropdown>
                    </div>

                    <div class="flex items-center"><label class="font-semibold">Contact</label></div>
                    <div class="col-span-3 flex items-center gap-2">
                        <Checkbox v-model="editForm.is_contact" :binary="true" inputId="is_contact" />
                        <label for="is_contact" class="text-sm text-gray-600">Designate as submitter contact</label>
                    </div>
                </template>

                <div class="flex items-center"><label class="font-semibold">Status</label></div>
                <div class="col-span-3">
                    <Dropdown v-model="editForm.status" :options="[
                        { label: 'Active', value: 1 },
                        { label: 'Removed', value: 9 }
                    ]" optionLabel="label" optionValue="value" class="w-full" :disabled="isSelf" />
                    <small v-if="isSelf" class="text-gray-500">You cannot change your own status.</small>
                </div>

                <div class="flex items-center"><label class="font-semibold">Password</label></div>
                <div class="col-span-3">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="editForm.must_change_password" :binary="true" inputId="must_change_pw" />
                        <label for="must_change_pw" class="text-sm">Reset password and require change on next login</label>
                    </div>
                    <small v-if="editForm.must_change_password && !user.must_change_password" class="text-orange-600">A new temporary password will be generated and emailed to the user.</small>
                </div>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showEditDialog = false" />
                <Button label="Save" icon="pi pi-check" @click="saveUser"
                    :disabled="!editForm.is_admin && !editForm.submitter_id" />
            </template>
        </Dialog>

        <!-- Delete / Deactivate Confirmation -->
        <Dialog v-model:visible="showDeactivateDialog" modal :header="canPermanentlyDelete ? 'Confirm Deletion' : 'Confirm Deactivation'" :style="{ width: '30rem' }">
            <p>Are you sure you want to {{ canPermanentlyDelete ? 'permanently delete' : 'deactivate' }} <strong>{{ user.name }}</strong>?</p>
            <p v-if="canPermanentlyDelete" class="text-sm text-orange-600 mt-2">
                This user has no jobs, submissions, or associations and will be permanently deleted.
            </p>
            <p v-else class="text-sm text-gray-500 mt-2">
                This user has associated data and will be deactivated (status set to Removed). They will no longer be able to log in.
            </p>

            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showDeactivateDialog = false" />
                <Button :label="canPermanentlyDelete ? 'Delete' : 'Deactivate'" icon="pi pi-ban" severity="danger" @click="deactivateUser" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
