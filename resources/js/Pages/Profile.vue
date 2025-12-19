<script setup>
    import { ref, watch } from 'vue'
    import { router } from '@inertiajs/vue3'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import ChangePassword from '@/Components/ChangePassword.vue';
    import ChangeName from '@/Components/ChangeName.vue';
    import { useToast } from "primevue/usetoast";
    import Checkbox from 'primevue/checkbox';
    import { Link } from '@inertiajs/vue3'

    const props = defineProps(['user', 'submitter']);

    const showPassword = ref(false);
    const showName = ref(false);
    const toast = useToast();
    const notify = ref(false);

    if (props.user.preferences !== null) {
        if (props.user.preferences.hasOwnProperty('notify'))
            notify.value = (props.user.preferences.notify == "true");
    }

    async function updateNotify() {

        try {
            const response = await axios.post('/api/users/' + props.user.id, {
                type: 'notify',
                new: !notify.value
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

            }
        } catch (error) {
            console.error(error);
        }     
    }


    async function updatePassword(obj) {

        console.log(obj)
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
                    // reload the server data
                    router.reload();
                    
                    // close the dialog
                    showSelectDialog.value = true;
                }
            } catch (error) {
                console.error(error);
            }     
        }
    }

    async function updateName(obj) {

        if (obj.name != '') {
            try {
                const response = await axios.post('/api/users/' + props.user.id, {
                    type: 'name',
                    name: obj.name,
                    credentials: obj.credentials
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
                    showSelectDialog.value = true;
                }
            } catch (error) {
                console.error(error);
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
                Name: <span class="border-2 px-2 ml-3">{{ user.name }}</span>
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

                        <!-- entries -->
                        <div class="grid grid-cols-12 mt-4 gap-0">
                            <div class="col-span-12">
                                <div class="grid grid-cols-12 gap-0">

                                    <div class="col-span-2 pt-3 text-right pr-3">Email:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.email }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">GenCC ID:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.clingen_id }}</div>
                                    </div>

                                    <div class="col-span-2 pt-3 text-right pr-3">Submitter:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ submitter.name }} ({{ submitter.curie }})</div>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">
                                            {{  user.name }}
                                            <span class= "pl-4"><Button icon="pi pi-user-edit" @click="showName = true" severity="success" text raised rounded/></span>
                                        </div>
                                    </div>
                                    <ChangeName v-model:visible="showName" :input="user" @input_dialog_close="showName = false" @input_name_item="updateName" ></ChangeName>



                                    <div class="col-span-2 pt-3 text-right pr-3">Credentials:</div>
                                    <div class="col-span-10 py-1 my-2 border-l-8 pl-3">
                                        <div class="font-normal font-bold">{{ user.credentials }}</div>
                                    </div>

                                    <hr class="col-span-12 my-4" />

                                    <div class="col-span-2 pt-3 text-right pr-3"></div>
                                    <div class="col-span-10 py-1 my-2 pl-4">
                                        <Button icon="pi pi-file-edit" label="Change Password" @click="showPassword = true"/>
                                        <div class="flex items-center float-right mr-24">
                                            <Checkbox v-model="notify" inputId="notifyid" name="notify" :binary="true" @click="updateNotify()" />
                                            <label for="notifyid" class="ml-2"> Notify on status changes</label>
                                        </div>
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
