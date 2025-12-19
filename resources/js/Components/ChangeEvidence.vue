<script setup>
    import { ref, watch, computed } from 'vue'
    import InputGroup from 'primevue/inputgroup';
    import Listbox from 'primevue/listbox';
    import IconField from 'primevue/iconfield';
    import InputIcon from 'primevue/inputicon';
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        name: yup.string().label('ID').max(248).matches(/^(PMID:)?[0-9]*$/g, 'Enter valid PMID (Example: 123456)'),
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [name] = defineField('name');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');
    const selected = ref('');
    const list = ref([]);
    const dlabel = ref('Select to Delete');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    /**
     * Open pubmed id in a separate tab
     */
    function searchPubmed() {

        if (name.value == "")
            return;

        if (name.value.toLowerCase().includes('pmid:', 0))
            name.value = name.value.substring(5);

        let url = 'https://pubmed.ncbi.nlm.nih.gov/' + name.value;

        window.open(url, '_pubmed');
    }

    /**
     * Select an ID from the list
     */
    function selectPubmed() {

        name.value = selected.value.pmid;

        dlabel.value = 'Delete ' + selected.value.pmid;
    }

    /**
     * Add an ID to the list
     */
    function addPubmed() {

        const id = name.value;

        if (name.value.toLowerCase().includes('pmid:', 0))
            name.value = name.value.substring(5);

        const temp = list.value.filter((element)=>{
                 return element.pmid === id
        })

        if (temp.length != 0)
            return;

        list.value.push({pmid: name.value, code: name.value})

    }

    /**
     * Remove an ID from the list
     */
    function removePubmed() {

        if (selected.value == undefined)
            return;

        const id = selected.value.pmid;

        const temp = list.value.filter((element)=>{
                 return element.pmid !== id
        })

        console.log(temp);
        
        list.value = temp  

        dlabel.value = 'Select to Delete';
    }


    const emit = defineEmits(['input_notes_close', 'input_evidence_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        emit('input_evidence_item', list.value);
        emit('input_evidence_close');

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        let items = [];

        // make sure its not an empty object
        for (const item of props.input)
            if (item.pmid)
                items.push({ pmid: item.pmid, code: item.pmid});

        name.value = '';
        list.value = items;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" @show="initializeInput" modal header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold  text-2xl">{{ title }}</span>
                </div>
            </template>

            <div class="grid grid-cols-4">
                <div class="flex items-center gap-">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">PMID</label>
                </div>
                <div class="flex items-center col-span-3">
                    <InputGroup>
                        <IconField class="w-full">
                            <InputIcon>
                                <i class="pi pi-times-circle" @click="name = ''"/>
                            </InputIcon>
                            <InputText v-model="name" placeholder="Enter PMID number" />
                        </IconField>
                        <Button icon="pi pi-search" @click="searchPubmed()" severity="info" />
                        <Button icon="pi pi-plus" @click="addPubmed()" severity="success" />
                    </InputGroup>
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.name }}</small>
                </div>

                <div class="flex items-center gap-3 mb-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">PMIDS</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <Listbox v-model="selected" :options="list" @click="selectPubmed()" optionLabel="pmid" class="w-full" listStyle="max-height:250px" />
                </div>
                <div class="flex items-center gap-3 mb-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <Button icon="pi pi-trash"  :label="dlabel" @click="removePubmed()" severity="danger" class="w-full" />
                </div>

            </div>
            
            <template #footer>
                <div class="flex gap-8 bg-slate-50 m-0 p-4">
                    <Button label="Update" @click="closeCallback" outlined severity="info" class="ml-4 p-3" :disabled="disabled" />
                    <Button label="Cancel" @click="visible = false" severity="secondary" class="mr-4 p-3" />
                </div>
            </template>              
        </Dialog>
    </div>
</template>