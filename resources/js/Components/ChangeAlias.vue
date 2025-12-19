<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'title', 'errors']);

    // component side validations
    const schema = yup.object({
        selected: yup.string().required().label('Type'),
        name: yup.string().required().label('Name').max(248).matches(/^[a-zA-Z0-9_-]+$/g, 'Only letters, numbers, underscore, or dash allowed'),
        definition: yup.string().required().label('Value').max(248)
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [name] = defineField('name');
    const [selected] = defineField('selected');
    const [definition] = defineField('definition');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');
    const ident = ref('CREATE');

    const options = [
        {value: 1, label: "Criteria Specification"},
        {value: 2, label: "Reason Code"},
    ];

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_alias_close', 'input_alias_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        emit('input_alias_item', { ident: ident.value, name: name.value, value: definition.value, type: selected.value });
        emit('input_alias_close');

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        name.value = props.input.key;
        definition.value = props.input.value;
        selected.value = props.input.type;
        ident.value = props.input.ident;

        if (ident.value == undefined)
            ident.value = 'CREATE';
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" modal @show="initializeInput" header="{{ header }}" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold">{{ title }}</span>
                </div>
            </template>
            
            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Type</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <Dropdown v-model="selected" :options="options" optionLabel="label" optionValue="value" placeholder="Select a Type" class="w-full md:w-14rem" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.selected }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Name</label>
                </div>
                <div class="flex items-center col-span-3 mt-3">
                    <InputText type="text" v-model="name" class="flex-auto" autocomplete="off" :invalid="false" rules="required"/>
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.name }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Value</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <InputText type="text" v-model="definition" class="flex-auto" autocomplete="off" :invalid="false" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.definition }}</small>
                </div>

            </div>
                
            <div class="flex align-items-center gap-3 mt-5">
                <Button label="Update" @click="closeCallback" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10" :disabled="disabled"/>
                <Button label="Cancel" @click="visible = false" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10" />
            </div>              
        </Dialog>
    </div>
</template>