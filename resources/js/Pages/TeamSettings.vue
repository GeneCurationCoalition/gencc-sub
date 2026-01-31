<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import ChangeTeamInfo from '@/Components/ChangeTeamInfo.vue';
    import { useToast } from "primevue/usetoast";

    const props = defineProps(['team', 'members', 'canEdit', 'allUsers', 'isAdminTeam', 'authUserId']);

    const showEdit = ref(false);
    const toast = useToast();

    async function updateTeam(obj) {
        try {
            const response = await axios.post('/api/teams/' + props.team.id, {
                name: obj.name
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                showEdit.value = false;
                toast.add({ severity: 'success', summary: 'Team Updated', detail: 'Team information has been updated successfully.', life: 3000 });
                router.reload();
            } else {
                toast.add({ severity: 'error', summary: 'Update Failed', detail: response.data.message || 'An error occurred.', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Update Failed', detail: 'An error occurred while updating team information.', life: 5000 });
        }
    }

    // Add member
    const showAddMember = ref(false)
    const selectedUserId = ref(null)
    const addingMember = ref(false)

    // Filter out users who are already members
    const availableUsers = computed(() => {
        if (!props.allUsers) return []
        const memberIds = (props.members || []).map(m => m.id)
        return props.allUsers.filter(u => !memberIds.includes(u.id))
    })

    async function addMember() {
        if (!selectedUserId.value) return
        addingMember.value = true
        try {
            const response = await axios.post('/api/teams/' + props.team.id + '/members', {
                user_id: selectedUserId.value
            })
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Member Added', detail: response.data.message, life: 3000 })
                showAddMember.value = false
                selectedUserId.value = null
                router.reload()
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to add member', life: 5000 })
            }
        } catch (error) {
            const msg = error.response?.data?.message || 'Failed to add member'
            toast.add({ severity: 'error', summary: 'Error', detail: msg, life: 5000 })
        } finally {
            addingMember.value = false
        }
    }

    // Remove member
    const removingMemberId = ref(null)

    async function removeMember(memberId) {
        removingMemberId.value = memberId
        try {
            const response = await axios.delete('/api/teams/' + props.team.id + '/members/' + memberId)
            if (response.data.success) {
                toast.add({ severity: 'success', summary: 'Member Removed', detail: response.data.message, life: 3000 })
                router.reload()
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to remove member', life: 5000 })
            }
        } catch (error) {
            const msg = error.response?.data?.message || 'Failed to remove member'
            toast.add({ severity: 'error', summary: 'Error', detail: msg, life: 5000 })
        } finally {
            removingMemberId.value = null
        }
    }

    function isOwner(memberId) {
        return props.team.owner && props.team.owner.id === memberId
    }
</script>

<template>
    <AppLayout title="Team Settings">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Team: {{ team.name }}
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

                        <div class="grid grid-cols-12 mt-4 gap-0">
                            <div class="col-span-12">
                                <div class="grid grid-cols-12 gap-0">

                                    <div class="col-span-2 pt-3 text-right pr-3">Team Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">
                                            {{ team.name }}
                                            <span v-if="canEdit && !isAdminTeam" class="pl-4">
                                                <Button icon="pi pi-pencil" @click="showEdit = true" severity="success" text raised rounded/>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Owner:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ team.owner?.name || '—' }}</div>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">Members:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="members && members.length > 0">
                                            <div v-for="member in members" :key="member.id" class="py-1 flex items-center">
                                                <span class="font-bold">{{ member.name }}</span>
                                                <span class="text-gray-500 ml-2">({{ member.email }})</span>
                                                <Tag v-if="isOwner(member.id)" value="Owner" severity="info" class="ml-2" />
                                                <Button
                                                    v-if="canEdit && !isOwner(member.id) && member.id !== authUserId"
                                                    icon="pi pi-times"
                                                    severity="danger"
                                                    text
                                                    rounded
                                                    size="small"
                                                    class="ml-2"
                                                    :loading="removingMemberId === member.id"
                                                    @click="removeMember(member.id)"
                                                    v-tooltip.top="'Remove from team'"
                                                />
                                            </div>
                                        </div>
                                        <div v-else class="text-gray-500">No team members</div>

                                        <div v-if="canEdit" class="mt-3">
                                            <Button label="Add Member" icon="pi pi-plus" size="small" @click="showAddMember = true" />
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <ChangeTeamInfo
            v-model:visible="showEdit"
            :input="team"
            @input_dialog_close="showEdit = false"
            @input_team_item="updateTeam"
        />

        <!-- Add Member Dialog -->
        <Dialog v-model:visible="showAddMember" header="Add Team Member" :modal="true" :style="{ width: '450px' }">
            <div class="flex flex-col gap-4">
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
                        <template #option="{ option }">
                            <div>
                                <span class="font-medium">{{ option.name }}</span>
                                <span class="text-gray-500 ml-2">({{ option.email }})</span>
                            </div>
                        </template>
                    </Dropdown>
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" @click="showAddMember = false" :disabled="addingMember" />
                <Button label="Add Member" icon="pi pi-check" @click="addMember" :loading="addingMember" :disabled="!selectedUserId" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
