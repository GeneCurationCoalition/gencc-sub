<script setup>
import { ref, watch, computed } from 'vue'
import AutoComplete from 'primevue/autocomplete';
import Checkbox from 'primevue/checkbox';
import { useForm } from 'vee-validate';
import * as yup from 'yup';

const props = defineProps(['visible', 'input', 'coptions', 'header', 'title', 'label']);

// Validation schema - URL is required, name is optional
const schema = yup.object({
    name: yup.string().nullable().label('Name').max(248),
    url: yup.string().required().label('URL').max(248)
});

const { defineField, handleSubmit, resetForm, errors } = useForm({
    validationSchema: schema,
});

const [name] = defineField('name');
const [url] = defineField('url');

// Disable submit button if there are validation errors
const disabled = computed(() => {
    return Object.keys(errors.value).length !== 0
});

// Local vars
const visible = defineModel('visible');
const checked = ref(false);
const filteredItems = ref([]);
const selectedAlias = ref(null);

// Watch for visibility changes from parent
watch(() => props.visible, (newValue) => {
    visible.value = newValue;
});

// When user selects an alias from autocomplete, auto-fill the URL
watch(() => selectedAlias.value, (newValue) => {
    if (newValue && typeof newValue === 'object') {
        // User selected a saved alias
        name.value = newValue.key;
        url.value = newValue.value;
    } else if (typeof newValue === 'string') {
        // User typed a custom name - just update the name field
        name.value = newValue;
        // Don't clear the URL field
    } else if (newValue === null || newValue === undefined) {
        // Field was cleared - clear name but keep URL
        name.value = '';
    }
});

const emit = defineEmits(['input_criteria_close', 'input_criteria_item'])

/**
 * Initialize form fields when dialog is shown
 */
function initializeInput()
{
    url.value = props.input?.url || '';
    name.value = props.input?.name || '';
    checked.value = false;

    // Initialize selectedAlias based on existing input
    if (props.input?.name && props.input?.url) {
        selectedAlias.value = {
            key: props.input.name,
            value: props.input.url
        };
    } else {
        selectedAlias.value = null;
    }
}

/**
 * Search/filter function for autocomplete
 */
function searchAliases(event) {
    const query = event.query.trim().toLowerCase();

    // Convert coptions to the format we need
    const allAliases = (props.coptions || []).map(option => ({
        key: option.key || '',
        value: option.value || ''
    }));

    if (!query) {
        // Show all saved aliases
        filteredItems.value = allAliases;
    } else {
        // Filter aliases by name (key)
        filteredItems.value = allAliases.filter(alias =>
            alias.key.toLowerCase().includes(query)
        );
    }
}

/**
 * Handle form submission
 */
function closeCallback()
{
    // Check for validation errors
    if (disabled.value === true) {
        return;
    }

    // Trim and check if name is empty/whitespace only
    const trimmedName = (name.value || '').trim();
    const finalName = trimmedName.length > 0 ? trimmedName : null;

    emit('input_criteria_item', {
        url: url.value,
        name: finalName,
        remember: checked.value
    });
    emit('input_criteria_close');
}

/**
 * Handle cancel
 */
function cancelCallback()
{
    visible.value = false;
    emit('input_criteria_close');
}

</script>

<template>
    <div class="col-span-12">
        <Dialog v-model:visible="visible" @show="initializeInput" modal :header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">{{ title }}</span>
                </div>
            </template>

            <div class="grid grid-cols-4 gap-y-2">
                <!-- Name Field with AutoComplete -->
                <div class="flex items-center gap-3">
                    <label for="criteriaName" class="font-semibold w-6rem">Name</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <AutoComplete
                        id="criteriaName"
                        v-model="selectedAlias"
                        :suggestions="filteredItems"
                        @complete="searchAliases"
                        optionLabel="key"
                        :forceSelection="false"
                        class="flex-auto"
                        placeholder="Optional - type to search saved aliases or enter custom name"
                    />
                    <Checkbox v-model="checked" :binary="true" inputId="rememberCheck" />
                    <label for="rememberCheck">Remember</label>
                </div>

                <!-- Name Error Message -->
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small v-if="errors.name" class="text-red-600">{{ errors.name }}</small>
                </div>

                <!-- URL Field -->
                <div class="flex items-center gap-3 mt-3">
                    <label for="criteriaUrl" class="font-semibold w-6rem">{{ label }}</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mt-3">
                    <InputText
                        id="criteriaUrl"
                        type="text"
                        v-model="url"
                        class="flex-auto"
                        autocomplete="off"
                        placeholder="Required - enter URL"
                    />
                </div>

                <!-- URL Error Message -->
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small v-if="errors.url" class="text-red-600">{{ errors.url }}</small>
                </div>
            </div>

            <template #footer>
                <div class="flex gap-8 bg-slate-50 m-0 p-4">
                    <Button
                        label="Update"
                        @click="closeCallback"
                        outlined
                        severity="info"
                        class="ml-4 p-3"
                        :disabled="disabled"
                    />
                    <Button
                        label="Cancel"
                        @click="cancelCallback"
                        severity="secondary"
                        class="mr-4 p-3"
                    />
                </div>
            </template>
        </Dialog>
    </div>
</template>
