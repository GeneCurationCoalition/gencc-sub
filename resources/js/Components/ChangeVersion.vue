<script setup>
    import { ref, watch, computed } from 'vue'
    import Chips from 'primevue/chips';
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        private_no: yup.string().label('Display').max(248),
        public_no: yup.string().label('Internal').max(248),
        description: yup.string().nullable(true).label('Description'),
        reasons: yup.array().of(yup.string()).label('Reasons')
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [private_no] = defineField('private_no');
    const [public_no] = defineField('public_no');
    const [description] = defineField('description');
    const [reasons] = defineField('reasons');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_version_close', 'input_version_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        emit('input_version_item', 
            { public: public_no.value, private: private_no.value, reasons: reasons.value, description: description.value });
        emit('input_version_close');

    }

    function initializeInput()
    {
        console.log(props.input);

        public_no.value = props.input.display;
        private_no.value = props.input.internal;
        reasons.value = props.input.reasons;
        description.value = props.input.description;
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
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Display</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText type="text" v-model="public_no" class="flex-auto" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.public_no }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Internal</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <InputText type="text" v-model="private_no" class="flex-auto" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.private_no }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Reasons</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <Chips v-model="reasons" class="flex-auto"  autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.reasons }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Description</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <Textarea v-model="description" rows="5" cols="30" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.description }}</small>
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