<script setup>
    import { ref, watch, computed, onMounted } from 'vue'
    import { router, usePage } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import ChangePassword from '@/Components/ChangePassword.vue';
    import ChangeProfile from '@/Components/ChangeProfile.vue';
    import { useToast } from "primevue/usetoast";
    import { Link } from '@inertiajs/vue3'

    const props = defineProps(['user', 'submitters', 'adminTeam']);

    const page = usePage();
    const mustChangePassword = computed(() => page.props.mustChangePassword);

    const showPassword = ref(false);
    const showProfile = ref(false);
    const toast = useToast();

    onMounted(() => {
        if (mustChangePassword.value) {
            showPassword.value = true;
        }
    });

    // Get primary submitter (first one) for header display
    const primarySubmitter = computed(() => {
        return props.submitters?.[0] || null;
    });

    async function updatePassword(obj) {

        if (obj.old != '') {
            try {
                const response = await axios.post('/api/users/' + props.user.id, {
                    type: 'passwd',
                    old: obj.old,
                    new: obj.new
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // Success - close dialog and show message
                    showPassword.value = false;
                    toast.add({ severity: 'success', summary: 'Password Updated', detail: 'Your password has been changed successfully.', life: 3000 });
                    router.reload();
                }
                else if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 3004 )
                {
                    // Wrong old password - keep dialog open
                    toast.add({ severity: 'error', summary: 'Password Update Failed', detail: response.data.message || 'The current password you entered is incorrect.', life: 5000 });
                }
                else if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 3005 )
                {
                    // Password validation failed - keep dialog open
                    toast.add({ severity: 'error', summary: 'Password Requirements Not Met', detail: response.data.message, life: 5000 });
                }
                else
                {
                    // Other error
                    toast.add({ severity: 'error', summary: 'Password Update Failed', detail: response.data.message || 'An error occurred.', life: 5000 });
                }
            } catch (error) {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Password Update Failed', detail: 'An error occurred while updating your password.', life: 5000 });
            }
        }
    }

    async function updateProfile(obj) {
        if (obj.name != '' && obj.email != '') {
            try {
                const response = await axios.post('/api/users/' + props.user.id, {
                    type: 'profile',
                    name: obj.name,
                    title: obj.title,
                    email: obj.email,
                    phone: obj.phone
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // reload the server data
                    router.reload();

                    // close the dialog
                    showProfile.value = false;
                    toast.add({ severity: 'success', summary: 'Profile Updated', detail: 'Your profile has been updated successfully.', life: 3000 });
                }
                else
                {
                    toast.add({ severity: 'error', summary: 'Update Failed', detail: response.data.message || 'An error occurred.', life: 5000 });
                }
            } catch (error) {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Update Failed', detail: 'An error occurred while updating your profile.', life: 5000 });
            }
        }
    }

    async function renewToken()
    {
        try {
            const response = await axios.post('/api/users/' + props.user.id, {
                type: 'refresh_token',
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();
                toast.add({ severity: 'success', summary: 'Token Renewed', detail: '', life: 3000 });

            }
        } catch (error) {
            console.log(error);
        }
    }


</script>

<template>
    <AppLayout title="Profile">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Profile: {{ user.name }}
                <span v-if="primarySubmitter" class="text-2xl">({{ primarySubmitter.name }})</span>
                <span v-if="primarySubmitter?.is_contact" class="ml-2 text-lg bg-blue-200 text-blue-900 px-2 py-0.5 rounded">Contact</span>
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

                <div v-if="mustChangePassword" class="mb-4 p-4 bg-yellow-50 border border-yellow-300 rounded-lg flex items-center gap-3">
                    <i class="pi pi-exclamation-triangle text-yellow-600 text-xl"></i>
                    <div>
                        <span class="font-semibold text-yellow-800">Password change required.</span>
                        <span class="text-yellow-700"> An administrator has requested that you change your password before continuing.</span>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

                        <!-- entries -->
                        <div class="grid grid-cols-12 mt-4 gap-0">
                            <div class="col-span-12">
                                <div class="grid grid-cols-12 gap-0">

                                    <div class="col-span-2 pt-3 text-right pr-3">GenCC ID:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.clingen_id }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Submitter:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div v-if="primarySubmitter">
                                            <span class="font-bold">{{ primarySubmitter.name }}</span>
                                            <span class="text-gray-500 ml-2">({{ primarySubmitter.curie }})</span>
                                            <span v-if="primarySubmitter.is_contact" class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">Contact</span>
                                        </div>
                                        <div v-else-if="!adminTeam" class="text-gray-500">Not associated with any submitter</div>
                                        <div v-else class="text-gray-500">—</div>
                                    </div>

                                    <template v-if="adminTeam">
                                        <div class="col-span-2 pt-3 text-right pr-3">Team:</div>
                                        <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                            <div class="py-1">
                                                <Link :href="route('team.show')" class="font-bold text-blue-600 hover:underline">{{ adminTeam.name }}</Link>
                                                <Tag value="Admin" severity="info" class="ml-2" />
                                            </div>
                                        </div>
                                    </template>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">
                                            {{ user.name }}<span v-if="user.title">, {{ user.title }}</span>
                                            <span class="pl-4"><Button icon="pi pi-user-edit" @click="showProfile = true" severity="success" text raised rounded/></span>
                                        </div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Title:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.title || '—' }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Email:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.email }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Phone:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.phone || '—' }}</div>
                                    </div>

                                    <ChangeProfile v-model:visible="showProfile" :input="user" @input_dialog_close="showProfile = false" @input_profile_item="updateProfile" ></ChangeProfile>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3"></div>
                                    <div class="col-span-10 py-1 my-2 pl-4">
                                        <Button icon="pi pi-file-edit" label="Change Password" @click="showPassword = true"/>
                                    </div>

                                    <ChangePassword v-model:visible="showPassword" @input_dialog_close="showPassword = false" @input_password_item="updatePassword" ></ChangePassword>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">API Token:</div>
                                    <div class="col-span-8 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{  user.api_token }}</div>
                                        <div class="text-xs">Last Renewed:  {{ new Date(Date.parse(user.api_token_renewed_at)).toISOString().split('T')[0] }}</div>
                                    </div>
                                    <div class="col-span-2 pt-3 pr-3">
                                        <Button icon="pi pi-refresh" label="Renew" @click="renewToken()"/>
                                        <Toast />
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
