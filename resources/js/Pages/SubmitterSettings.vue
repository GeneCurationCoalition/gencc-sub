<script setup>
    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import ChangeSubmitterInfo from '@/Components/ChangeSubmitterInfo.vue';
    import MarkdownDisplay from '@/Components/MarkdownDisplay.vue';
    import { useToast } from "primevue/usetoast";

    const props = defineProps(['submitter', 'members', 'canEdit']);

    const showEdit = ref(false);
    const toast = useToast();

    // Get current contact (first one found, or null)
    const currentContact = computed(() => {
        return props.members?.find(m => m.is_contact) || null;
    });

    async function updateSubmitter(obj) {
        try {
            // Use FormData to support file upload
            const formData = new FormData();
            formData.append('name', obj.name);
            if (obj.description) formData.append('description', obj.description);
            if (obj.website) formData.append('website', obj.website);
            if (obj.assertion) formData.append('assertion', obj.assertion);
            if (obj.logo) formData.append('logo', obj.logo);
            if (obj.remove_logo) formData.append('remove_logo', '1');
            if (obj.contact_id !== undefined) formData.append('contact_id', obj.contact_id || '');

            const response = await axios.post('/api/submitters/' + props.submitter.id, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            });

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                showEdit.value = false;
                toast.add({ severity: 'success', summary: 'Submitter Updated', detail: 'Submitter information has been updated successfully.', life: 3000 });
                router.reload();
            } else {
                toast.add({ severity: 'error', summary: 'Update Failed', detail: response.data.message || 'An error occurred.', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Update Failed', detail: 'An error occurred while updating submitter information.', life: 5000 });
        }
    }
</script>

<template>
    <AppLayout title="Submitter Settings">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Submitter: {{ submitter.name }}
            </h2>
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
                                        <div class="font-normal font-bold">{{ submitter.curie }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ submitter.name }}</div>
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
                                        <div class="font-normal">
                                            <a v-if="submitter.website" :href="submitter.website" target="_blank" class="text-blue-600 hover:underline">
                                                {{ submitter.website }}
                                            </a>
                                            <span v-else>Not provided</span>
                                        </div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Assertion Criteria:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal">
                                            <a v-if="submitter.assertion" :href="submitter.assertion" target="_blank" class="text-blue-600 hover:underline">
                                                {{ submitter.assertion }}
                                            </a>
                                            <span v-else>Not provided</span>
                                        </div>
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
                                            <div v-for="member in members" :key="member.id" class="py-1">
                                                <span class="font-bold">{{ member.name }}</span>
                                                <span class="text-gray-500 ml-2">({{ member.email }})</span>
                                                <span v-if="member.is_contact" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">Contact</span>
                                            </div>
                                        </div>
                                        <div v-else class="text-gray-500">No members</div>
                                    </div>

                                    <template v-if="canEdit">
                                        <hr class="col-span-12 my-4" />

                                        <div class="col-span-2 pt-3 text-right pr-3"></div>
                                        <div class="col-span-10 py-1 my-2 pl-4">
                                            <Button icon="pi pi-pencil" label="Edit Submitter Information" @click="showEdit = true"/>
                                        </div>
                                    </template>

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
        <Toast />
    </AppLayout>
</template>
