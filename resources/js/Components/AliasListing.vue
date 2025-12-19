<script setup>

    import { ref } from 'vue'
    import { router } from '@inertiajs/vue3'
    import { FilterMatchMode, FilterOperator } from "primevue/api";
    import ConfirmDialog from 'primevue/confirmdialog';
    import { useConfirm } from "primevue/useconfirm";
    import { useToast } from "primevue/usetoast";
    import ChangeAlias from './ChangeAlias.vue';


    defineProps(['aliases'])

    const confirm = useConfirm();
    const toast = useToast();

    const showAliasDialog = ref(false);
    const localInput = ref();
    const dialogTitle = defineModel('dialogTitle');
    const dialogLabel = defineModel('dialogLabel');


    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    })

    const displayOptions = ref([
        { name: 'Show All', option: '1' },
        { name: 'Show Criteria Specification', option: '2' },
        { name: 'Show Reason Code', option: '3' }
    ]);

    const selectedDisplay = ref('1');

    const axios = window.axios;

    /**
     * 
     * @param data 
     */
    function showDialog(data) {

        if (data == '')
            dialogTitle.value = 'Add a Term';
        else
            dialogTitle.value = 'Edit a Term';

        dialogLabel.value = 'Name';
        showAliasDialog.value = true;
        localInput.value = data;
    }


    /**
     * 
     * @param aid 
     */
    function removeAlias(aid) {
    
        if (aid != '') {
                
            axios.delete('/api/aliases/' + aid)
                .then(response => {

                    if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                    {
                        // reload the server data
                        router.reload();
                        toast.add({ severity: 'success', summary: 'Confirmed', detail: 'Alias Removed', life: 3000 });
                        return true;
                    }
                    else
                    {
                        console.log(response);
                    }
                })
                .catch(error => {
                    console.error(error);
                });  
        }

        return false;
    }


    /**
     * 
     * @param aid 
     */
    async function updateAlias(aid) {

        try {
            const response = await axios.post('/api/aliases/' + aid.ident, {
                type: 'modify',
                class: aid.type,
                name: aid.name,
                value: aid.value
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
                showAliasDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }     
    }

    
    const requireConfirmation = (sid) => {
        confirm.require({
            group: 'headless',
            header: 'Please Confirm To Delete',
            message: '',
            accept: () => {
                removeAlias(sid)
                    toast.add({ severity: 'info', summary: 'Confirmed', detail: 'Term Removed', life: 3000 });
            },
            reject: () => {
                //
            }
        });
    };

    function rowFilter(item)
    {
        if (selectedDisplay.value == 2)
            return (item.type == 1)
        else if (selectedDisplay.value == 3)
            return (item.type == 2)

        return true;
    }

    function rowStyle(data)
    {
        /*if (data.submission_errors == null)
            if (data.status == 20)
                return {'background-color': '#e5e7eb'};
            else
                return null

        return Object.keys(data.submission_errors).length > 0 ? {'background-color': '#fef2f2'} : null;*/
    }

    function displayType(type) {
        switch (type) {
            case 0:
                return 'Initializing';
	 		case 1:
                return 'Criteria Specification';
            case 2:
                return 'Reason Code';
        }
    }

</script>

<style>
table tbody tr:hover {
    background: #F8F8F8;
}
</style>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <ConfirmDialog group="headless">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-red-500 dark:bg-red-400 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-question text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-2 mt-4">
                            <Button label="Delete Alias" @click="acceptCallback" class="bg-red-500 ring-red-500"></Button>
                            <Button label="Cancel" outlined @click="rejectCallback" class="bg-blue-500 ring-blue-500"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>
            <Toast />

            <div class="text-right mb-2">
                <Button class="mr-4" label="Add New Term" @click="showDialog('')" severity="info" />
            </div>

            <DataTable v-model:filters="filters" :value="aliases?.filter(rowFilter)" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100, 250]" sortField="key" :sortOrder="1" 
                    :rowStyle="rowStyle" :globalFilterFields="['type', 'key', 'value']" tableStyle="min-width: 20rem; width: auto;">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-bold">
                            <span class="ml-3">Total of {{ aliases ? aliases.length : 0 }} terms. </span>
                        </span>
                        <div style="text-align: left">
                            <Dropdown v-model="selectedDisplay" :options="displayOptions" optionLabel="name" optionValue="option" placeholder="Display" class="w-full md:w-12rem" />
                        </div>
                        <IconField iconPosition="left">
                            <InputIcon>
                                <i class="pi pi-search" />
                            </InputIcon>
                            <InputText v-model="filters['global'].value" placeholder="Keyword Search" />
                        </IconField>
                    </div>
                </template>
                <Column field="type" header="Type" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ displayType(data.type) }}</div>
                    </template>
                </Column>
                <Column field="key" header="Name" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.key }}</div>
                    </template>
                </Column>
                <Column field="value" header="Value" sortable>
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.value }}</div>
                    </template>
                </Column>
                <Column field="updated_at" header="Last Update" sortable>
                     <template #body="{ data }">
                        {{ new Date(Date.parse(data.updated_at)).toISOString().split('T')[0] }}
                     </template>
                </Column>
                <Column header="Action" style="width: 10%; min-width: 8rem" headerStyle="width: 5rem; text-align: center" bodyStyle="text-align: center; overflow: visible">
                    <template #body="slotProps">
                        <span class="mr-3">
                            <Button @click="requireConfirmation(slotProps.data.ident)" icon="pi pi-trash" severity="danger" text raised rounded></Button>
                        </span>
                        <Button @click="showDialog(slotProps.data)" icon="pi pi-file-edit" text raised rounded></Button>
                    </template>
                 </Column>
                 <template #footer>Total of {{ aliases ? aliases.length : 0 }} terms.</template>
            </DataTable>
        </div>

        <div class="col-span-12 ">
            <ChangeAlias v-model:visible="showAliasDialog" v-bind:input="localInput" @select_dialog_close="showAliasDialog = false" @input_alias_item="updateAlias" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeAlias>
        </div>

    </div>
</template>
