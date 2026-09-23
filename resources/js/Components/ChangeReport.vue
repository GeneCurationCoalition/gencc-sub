<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'input2', 'header', 'title', 'label', 'normalizedDate', 'dateError']);

    // component side validations
    const schema = yup.object({
        url: yup.lazy(value => !value ? yup.string() : yup.string().label('URL').matches(/(http(s)?:\/\/.)?(www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,6}\b([-a-zA-Z0-9@:%_\+.~#?&//=]*)/g, 'Invalid URL')),
        date: yup.date('Invalid date').required('Date is a required field').label('Date'),
    });

    const { defineField, handleSubmit, resetForm, errors, validateField, setFieldError } = useForm({
        validationSchema: schema,
    });

    const [url] = defineField('url');
    const [date] = defineField('date');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_report_close', 'input_report_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value  === true)
            return;

        // The picker hands back a Date; send the plain YYYY-MM-DD the server
        // accepts, read in local time so the day does not shift
        const picked = date.value;
        const ymd = picked instanceof Date
            ? [picked.getFullYear(), String(picked.getMonth() + 1).padStart(2, '0'), String(picked.getDate()).padStart(2, '0')].join('-')
            : picked;

        emit('input_report_item', { url: url.value, date: ymd });
        emit('input_report_close');

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    async function initializeInput()
    {
        url.value = props.input?.ext_url ?? '';
        date.value = props.normalizedDate
            ? String(props.normalizedDate).slice(0, 10)
            : String(props.input?.display_date ?? '');

        await Promise.all([validateField('url'), validateField('date')]);

        // Keep the server's explanation on an imported value that was rejected.
        // Calendar supports string values, so the raw input remains visible
        // until the submitter selects or enters a valid replacement.
        if (props.dateError) {
            setFieldError('date', props.dateError);
        }
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" modal @show="initializeInput" header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">{{ title }}</span>
                </div>
            </template>
            
            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText type="text" v-model="url" class="flex-auto" autocomplete="off" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.url }}</small>
                </div>


                <div class="flex items-center gap-3 mt-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Evaluated Date</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <Calendar v-model="date" showIcon iconDisplay="input" dateFormat="yy-mm-dd" placeholder="Select or enter a date" class="w-full md:w-14rem" :invalid="!!errors.date" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.date }}</small>
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
