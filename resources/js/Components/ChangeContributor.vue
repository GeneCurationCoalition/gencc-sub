<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        name: yup.string().label('Name').nullable(true).max(248),
        epid: yup.string().label('ID').max(248).matches(/^[a-zA-Z0-9_\-:]*$/g, 'Only letters, numbers, underscore, or dash allowed').nullable(true)
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [name] = defineField('name');
    const [epid] = defineField('epid');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // local vars
    const visible = defineModel('visible');

    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_contributor_close', 'input_contributor_item'])

    function closeCallback()
    {
        // need to get this back up to the parent somehow
        emit('input_contributor_item', { id: epid.value, name: name.value });
        emit('input_cobtributor_close');

    }

    function initializeInput()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        name.value = props.input.name;
        epid.value = props.input.id;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" @show="initializeInput" modal header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">{{ title }}</span>
                </div>
            </template>

            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }} Name</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText type="text" v-model="name" class="flex-auto" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.name }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }} ID</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <InputText type="text" v-model="epid" class="flex-auto" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.epid }}</small>
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