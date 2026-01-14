<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    // component side validations
    const schema = yup.object({
        description: yup.string().nullable(true).label('Description')
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [description] = defineField('description');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_description_close', 'input_description_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        // Preserve existing version fields, only update description
        emit('input_description_item',
            { public: props.input?.display, private: props.input?.internal, reasons: props.input?.reasons, description: description.value });
        emit('input_description_close');

    }

    function initializeInput()
    {
        description.value = props.input?.description;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" @show="initializeInput" modal header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">Edit Description of Change</span>
                </div>
            </template>

            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Description</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <Textarea v-model="description" rows="5" cols="30" class="w-full" />
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
