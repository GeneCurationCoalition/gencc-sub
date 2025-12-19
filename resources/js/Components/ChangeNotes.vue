<script setup>
    import { ref, watch, computed } from 'vue'
    import Textarea from 'primevue/textarea';
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        public_note: yup.string().nullable(true).label('Public'),
        private_note: yup.string().nullable(true).label('Private')
       
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [public_note] = defineField('public_note');
    const [private_note] = defineField('private_note');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_notes_close', 'input_notes_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        emit('input_notes_item', { public: public_note.value, private: private_note.value });
        emit('input_notes_close');

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        public_note.value = props.input?.display ?? '';
        private_note.value = props.input?.private ?? '';
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" @show="initializeInput" modal header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '60rem' }">
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
                    <Textarea v-model="public_note" class="flex-auto" rows="10" cols="30" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.public_note }}</small>
                </div>

                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Private</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <Textarea v-model="private_note" class="flex-auto" rows="10" cols="30" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.private_note }}</small>
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