<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue'
    import ChangeSubmitterInfo from '@/Components/ChangeSubmitterInfo.vue'
    import MarkdownDisplay from '@/Components/MarkdownDisplay.vue'
    import { useToast } from "primevue/usetoast"
    import Tag from 'primevue/tag'

    const props = defineProps(['submitter', 'members', 'allSubmitters', 'allUsers', 'authUserId'])

    const toast = useToast()
    const showEdit = ref(false)
    const showDeactivateDialog = ref(false)

    const canPermanentlyDelete = computed(() => {
        return (props.submitter.jobs_count || 0) === 0
            && (props.submitter.submissions_count || 0) === 0
            && (!props.members || props.members.length === 0)
    })

    const currentContact = computed(() => {
        return props.members?.find(m => m.is_contact) || null
    })

    const statusLabel = (status) => {
        switch (status) {
            case 0: return 'Initializing'
            case 1: return 'Active'
            case 9: return 'Removed'
            default: return 'Unknown'
        }
    }

    async function updateSubmitter(obj) {
        try {
            const formData = new FormData()
            formData.append('name', obj.name)
            if (obj.description) formData.append('description', obj.description)
            if (obj.website) formData.append('website', obj.website)
            if (obj.assertion) formData.append('assertion', obj.assertion)
            if (obj.logo) formData.append('logo', obj.logo)
            if (obj.remove_logo) formData.append('remove_logo', '1')
            if (obj.contact_id !== undefined) formData.append('contact_id', obj.contact_id || '')

            const response = await axios.post('/api/submitters/' + props.submitter.id, formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            })

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                showEdit.value = false
                toast.add({ severity: 'success', summary: 'Submitter Updated', detail: 'Submitter information has been updated successfully.', life: 3000 })
                router.reload()
            } else {
                toast.add({ severity: 'error', summary: 'Update Failed', detail: response.data.message || 'An error occurred.', life: 5000 })
            }
        } catch (error) {
            console.error(error)
            toast.add({ severity: 'error', summary: 'Update Failed', detail: 'An error occurred while updating submitter information.', life: 5000 })
        }
    }

    function goToUser(userId) {
        router.visit(route('admin.users.show', userId))
    }

    // Add member dialog
    const showAddMemberDialog = ref(false)
    const selectedUserId = ref(null)
    const addingMember = ref(false)

    const availableUsers = computed(() => {
        if (!props.allUsers) return []
        const memberIds = (props.members || []).map(m => m.id)
        return props.allUsers.filter(u => !memberIds.includes(u.id) && u.id !== props.authUserId)
    })

    async function addMember() {
        if (!selectedUserId.value) return
        addingMember.value = true
        try {
            const response = await axios.post('/api/admin/submitters/' + props.submitter.id + '/members', {
                user_id: selectedUserId.value,
            })
            if (response.data.success) {
                showAddMemberDialog.value = false
                selectedUserId.value = null
                toast.add({ severity: 'success', summary: 'Member Added', detail: response.data.message, life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to add member.', life: 5000 })
        } finally {
            addingMember.value = false
        }
    }

    // Deactivate submitter
    async function deactivateSubmitter() {
        try {
            const response = await axios.delete('/api/admin/submitters/' + props.submitter.id)
            if (response.data.success) {
                showDeactivateDialog.value = false
                toast.add({ severity: 'success', summary: 'Submitter Deactivated', detail: response.data.message, life: 3000 })
                if (response.data.deleted) {
                    router.visit(route('admin.submitters'))
                } else {
                    router.reload()
                }
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to deactivate submitter.', life: 5000 })
        }
    }

    // Reactivate submitter
    async function reactivateSubmitter() {
        try {
            const response = await axios.put('/api/admin/submitters/' + props.submitter.id, {
                name: props.submitter.name,
                status: 1,
            })
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Submitter Reactivated', detail: 'Submitter has been reactivated.', life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to reactivate submitter.', life: 5000 })
        }
    }

    async function removeMember(userId) {
        try {
            const response = await axios.delete('/api/admin/submitters/' + props.submitter.id + '/members/' + userId)
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Member Removed', detail: response.data.message, life: 3000 })
                router.reload()
            }
        } catch (error) {
            toast.add({ severity: 'error', summary: 'Error', detail: error.response?.data?.message || 'Failed to remove member.', life: 5000 })
        }
    }
</script>

<template>
    <AppLayout title="Submitter Detail">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-4xl text-white leading-tight">
                    Submitter: {{ submitter.name }}
                </h2>
                <Button icon="pi pi-arrow-left" label="Back to Submitters" severity="secondary" @click="router.visit(route('admin.submitters'))" class="bg-white/20 text-white border-white/40 hover:bg-white/30" />
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

                        <div class="grid grid-cols-12 mt-4 gap-0">
                            <div class="col-span-12">
                                <div class="grid grid-cols-12 gap-0">

                                    <div class="col-span-2 pt-3 text-right pr-3">GenCC ID:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-bold">{{ submitter.curie }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-bold">{{ submitter.name }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Status:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <Tag :value="statusLabel(submitter.status)" :severity="submitter.status === 1 ? 'success' : 'danger'" />
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Logo:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="submitter.logo" class="flex items-center">
                                            <img :src="submitter.logo" alt="Submitter logo" class="h-16 w-auto object-contain" />
                                        </div>
                                        <span v-else class="text-gray-500">No logo uploaded</span>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Description:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <MarkdownDisplay v-if="submitter.description" :content="submitter.description" />
                                        <span v-else class="text-gray-500">Not provided</span>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Website:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <a v-if="submitter.website" :href="submitter.website" target="_blank" class="text-blue-600 hover:underline">
                                            {{ submitter.website }}
                                        </a>
                                        <span v-else class="text-gray-500">Not provided</span>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Assertion Criteria:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <a v-if="submitter.assertion" :href="submitter.assertion" target="_blank" class="text-blue-600 hover:underline">
                                            {{ submitter.assertion }}
                                        </a>
                                        <span v-else class="text-gray-500">Not provided</span>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Statistics:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <span class="mr-4">Jobs: <strong>{{ submitter.jobs_count }}</strong></span>
                                        <span>Submissions: <strong>{{ submitter.submissions_count }}</strong></span>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">Contact:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="currentContact" class="font-bold">
                                            {{ currentContact.name }}
                                            <span class="text-gray-500 font-normal ml-2">({{ currentContact.email }})</span>
                                        </div>
                                        <div v-else class="text-gray-500">No contact designated</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Members:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="members && members.length > 0">
                                            <div v-for="member in members" :key="member.id" class="py-1 flex items-center">
                                                <a @click="goToUser(member.id)" class="font-bold text-blue-600 hover:underline cursor-pointer">{{ member.name }}</a>
                                                <span class="text-gray-500 ml-2">({{ member.email }})</span>
                                                <span v-if="member.is_contact" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">Contact</span>
                                                <Button icon="pi pi-times" severity="danger" text rounded size="small" class="ml-2" @click="removeMember(member.id)" />
                                            </div>
                                        </div>
                                        <div v-else class="text-gray-500">No members</div>
                                        <Button icon="pi pi-plus" label="Add Member" severity="info" text size="small" class="mt-2" @click="showAddMemberDialog = true" />
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3"></div>
                                    <div class="col-span-10 py-1 my-2 pl-4 flex gap-3">
                                        <Button icon="pi pi-pencil" label="Edit Submitter Information" @click="showEdit = true" />
                                        <Button v-if="submitter.status === 0" icon="pi pi-check" label="Activate" severity="success" outlined @click="reactivateSubmitter" />
                                        <Button v-if="submitter.status !== 9" icon="pi pi-ban" :label="canPermanentlyDelete ? 'Delete' : 'Deactivate'" severity="danger" outlined @click="showDeactivateDialog = true" />
                                        <Button v-if="submitter.status === 9" icon="pi pi-replay" label="Reactivate" severity="success" outlined @click="reactivateSubmitter" />
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <ChangeSubmitterInfo
            v-model:visible="showEdit"
            :input="submitter"
            :members="members"
            @input_dialog_close="showEdit = false"
            @input_submitter_item="updateSubmitter"
        />

        <!-- Add Member Dialog -->
        <Dialog v-model:visible="showAddMemberDialog" modal header="Add Member" :style="{ width: '30rem' }">
            <p class="text-sm text-gray-500 mb-4">Adding a user will automatically switch them from any current submitter or admin team association.</p>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select User</label>
                <Dropdown
                    v-model="selectedUserId"
                    :options="availableUsers"
                    optionLabel="name"
                    optionValue="id"
                    placeholder="Search for a user..."
                    class="w-full"
                    filter
                >
                    <template #option="slotProps">
                        <div>
                            <span>{{ slotProps.option.name }}</span>
                            <span class="text-gray-500 ml-2">({{ slotProps.option.email }})</span>
                        </div>
                    </template>
                </Dropdown>
            </div>

            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showAddMemberDialog = false" />
                <Button label="Add Member" icon="pi pi-check" @click="addMember" :loading="addingMember" :disabled="!selectedUserId" />
            </template>
        </Dialog>

        <!-- Delete / Deactivate Confirmation -->
        <Dialog v-model:visible="showDeactivateDialog" modal :header="canPermanentlyDelete ? 'Confirm Deletion' : 'Confirm Deactivation'" :style="{ width: '30rem' }">
            <p>Are you sure you want to {{ canPermanentlyDelete ? 'permanently delete' : 'deactivate' }} <strong>{{ submitter.name }}</strong>?</p>
            <p v-if="canPermanentlyDelete" class="text-sm text-orange-600 mt-2">
                This submitter has no jobs, submissions, or members and will be permanently deleted.
            </p>
            <p v-else class="text-sm text-gray-500 mt-2">
                This submitter has associated data and will be deactivated (status set to Removed).
            </p>

            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showDeactivateDialog = false" />
                <Button :label="canPermanentlyDelete ? 'Delete' : 'Deactivate'" icon="pi pi-ban" severity="danger" @click="deactivateSubmitter" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
