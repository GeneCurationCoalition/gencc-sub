<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input', 'title', 'label', 'apiError']);

    // component side validations
    const schema = yup.object({
        gene: yup.string().required().label('HGNC ID').max(248).matches(/^HGNC:[0-9]+$/g, 'Please enter a valid HGNC ID'),
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [gene] = defineField('gene');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
        })

    // other local vars
    const visible = defineModel('visible');
    const cardTitle = ref('');
    const cardBody = ref('Enter an HGNC ID (Example: HGNC:18149) and click on search to view gene information before updating.');

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    /**
     * Query for gene information
     */
    async function checkEntry() {

        try {
            const response = await axios.get('/api/lookup/gene/' + gene.value);

            const nodata = response.data.hasOwnProperty('status_code');

            if ( !nodata )
            {
                cardTitle.value = response.data.symbol + '   (' + response.data.hgnc_id + ')';
                cardBody.value = response.data.description;
                gene.value = response.data.hgnc_id;
            }

        } catch (error) {
            console.error(error);
        }
    }

    const emit = defineEmits(['input_dialog_close', 'input_gene_item', 'clear_api_error'])

    // Watch for value changes to clear API error
    watch(gene, () => {
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
        emit('input_gene_item', gene.value);
        // Note: Do NOT emit input_dialog_close here - parent handles closing
        // This allows the dialog to stay open and show errors from API call

    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        gene.value = props.input;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible"  @show="initializeInput" modal header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">{{ title }}</span>
                </div>
            </template>

            <!-- API Error Display -->
            <div v-if="apiError" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p class="font-bold">Duplicate Submission</p>
                <p class="text-sm">{{ apiError }}</p>
                <p class="text-sm mt-2 italic">Consider modifying the existing submission or selecting a different gene.</p>
            </div>

            <div class="grid grid-cols-4">
                <div class="flex items-center gap-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText type="text" v-model="gene" class="flex-auto" autocomplete="off" />
                    <Button icon="pi pi-search" @click="checkEntry()"/>
                </div>

                <div class="flex items-center gap-3 mb-3">
                    &nbsp;
                </div>
                <div v-if="!disabled" class="flex items-center col-span-3 gap-3 mb-3">
                    <div class="font-sm ml-2 italic">&nbsp;</div>
                </div>
                <div v-else class="flex items-center col-span-3">
                    <small id="username-help" class="text-red-600">{{ errors.gene }}</small>
                </div>
            </div>

            <div class="mt-3">
                <Card>
                    <template #title>{{ cardTitle }}</template>
                    <template #content>
                        <p class="m-0">
                            {{ cardBody }}
                        </p>
                    </template>
                </Card>
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