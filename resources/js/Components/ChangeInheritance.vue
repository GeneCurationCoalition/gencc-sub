<script setup>

    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['input', 'visible', 'title', 'label', 'apiError']);

    // component side validations
    const schema = yup.object({
        inheritance: yup.string().required().label('Type'),
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [inheritance] = defineField('inheritance');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    const mois = [
        {value: "HP:0000005", label: "Unknown"},
        {value: "HP:0000006", label: "Autosomal dominant"},
        {value: "HP:0010985", label: "Gonosomal"},
        {value: "HP:0001426", label: "Multifactorial"},
        {value: "HP:0032382", label: "Uniparental disomy"},
        {value: "HP:0001428", label: "Somatic mutation"},
        {value: "HP:0000007", label: "Autosomal recessive"},
        {value: "HP:0001466", label: "Contiguous gene syndrome"},
        {value: "HP:0003743", label: "Genetic anticipation"},
        {value: "HP:0001425", label: "Heterogeneous"},
        {value: "HP:0001427", label: "Mitochondrial"},
        {value: "HP:0032113", label: "Semidominant"},
        {value: "HP:0003745", label: "Sporadic"},
        {value: "HP:0001417", label: "X-linked"},
        {value: "HP:0001419", label: "X-linked recessive"},
        {value: "HP:0001423", label: "X-linked dominant"},
        {value: "HP:0001450", label: "Y-linked inheritance"},
        {value: "HP:0001442", label: "Somatic mosaicism"},
        {value: "HP:0012274", label: "Autosomal dominant inheritance with paternal imprinting"},
        {value: "HP:0010984", label: "Digenic inheritance"},
        {value: "HP:0012275", label: "Autosomal dominant inheritance with maternal imprinting"}

    ];

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['select_dialog_close', 'select_moi_item', 'clear_api_error'])

    // Watch for value changes to clear API error
    watch(inheritance, () => {
        emit('clear_api_error');
    });

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        // Only emit the value - parent will close dialog after successful API call
        emit('select_moi_item', inheritance.value);
        // Note: Do NOT emit select_dialog_close here - parent handles closing
        // This allows the dialog to stay open and show errors from API call

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        inheritance.value = props.input;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" modal @show="initializeInput" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold">{{ title }}</span>
                </div>
            </template>

            <!-- API Error Display -->
            <div v-if="apiError" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p class="font-bold">Duplicate Submission</p>
                <p class="text-sm">{{ apiError }}</p>
                <p class="text-sm mt-2 italic">Consider modifying the existing submission or selecting a different value.</p>
            </div>

            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <Dropdown v-model="inheritance" :options="mois" optionLabel="label" optionValue="value" placeholder="Select an Inheritance" class="w-full md:w-14rem" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.inheritance }}</small>
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