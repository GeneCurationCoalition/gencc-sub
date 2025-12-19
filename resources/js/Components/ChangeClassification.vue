<script setup>

    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['input', 'visible', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        classification: yup.string().required().label('Classification')
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [classification] = defineField('classification');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    const options = [
        {value: "GENCC:100001", label: "Definitive"},
        {value: "GENCC:100002", label: "Strong"},
        {value: "GENCC:100003", label: "Moderate"},
        {value: "GENCC:100004", label: "Limited"},
        {value: "GENCC:100005", label: "Disputed Evidence"},
        {value: "GENCC:100006", label: "Refuted Evidence"},
        {value: "GENCC:100007", label: "Animal Model Only"},
        {value: "GENCC:100008", label: "No Known Disease Relationship"},
        {value: "GENCC:100009", label: "Supportive"}
    ];

     // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['select_dialog_close', 'select_classification_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        emit('select_classification_item', classification.value);
        emit('select_dialog_close');

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
     function initializeInput()
    {
        classification.value = props.input;
    }

</script>

<template>
    <div class="col-span-12">
        <Dialog v-model:visible="visible" modal @show="initializeInput" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">{{ title }}</span>
                </div>
            </template>

            <div class="grid grid-cols-4 mt-5">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <Dropdown v-model="classification" :options="options" optionLabel="label" optionValue="value" placeholder="Select a classification" class="w-full md:w-14rem" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.classification }}</small>
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