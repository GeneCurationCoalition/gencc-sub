<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';
    import Checkbox from 'primevue/checkbox';

    const props = defineProps(['visible', 'input', 'members']);

    // Logo specifications
    const LOGO_WIDTH = 800;
    const LOGO_HEIGHT = 400;
    const LOGO_MAX_SIZE = 500 * 1024; // 500KB

    // component side validations
    const schema = yup.object({
        name: yup.string().required().label('Name').max(248),
        description: yup.string().nullable().label('Description').max(1000),
        website: yup.string().nullable().label('Website').url('Must be a valid URL'),
        assertion: yup.string().nullable().label('Assertion Criteria').url('Must be a valid URL'),
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [name] = defineField('name');
    const [description] = defineField('description');
    const [website] = defineField('website');
    const [assertion] = defineField('assertion');

    // Logo handling
    const logoFile = ref(null);
    const logoPreview = ref(null);
    const logoError = ref(null);
    const logoValidated = ref(false);
    const logoDimensions = ref(null);
    const removeLogo = ref(false);
    const currentLogo = ref(null);

    // Contact selection
    const selectedContact = ref(null);

    // Allow submissions and downloadable flags
    const allowSubmissions = ref(true);
    const downloadable = ref(false);

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0 || !name.value || (logoFile.value && !logoValidated.value)
    })

    // local vars
    const visible = defineModel('visible')

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_dialog_close', 'input_submitter_item'])

    /**
     * Validate logo file
     */
    async function validateLogo(file) {
        logoError.value = null;
        logoValidated.value = false;
        logoDimensions.value = null;

        // Check file type
        if (file.type !== 'image/png') {
            logoError.value = 'Logo must be a PNG image';
            return false;
        }

        // Check file size
        if (file.size > LOGO_MAX_SIZE) {
            logoError.value = `Logo must be less than ${LOGO_MAX_SIZE / 1024}KB. Current size: ${Math.round(file.size / 1024)}KB`;
            return false;
        }

        // Check dimensions
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                logoDimensions.value = { width: img.width, height: img.height };

                if (img.width !== LOGO_WIDTH || img.height !== LOGO_HEIGHT) {
                    logoError.value = `Logo must be exactly ${LOGO_WIDTH}x${LOGO_HEIGHT} pixels. Uploaded image is ${img.width}x${img.height} pixels.`;
                    resolve(false);
                } else {
                    logoValidated.value = true;
                    resolve(true);
                }
            };
            img.onerror = () => {
                logoError.value = 'Could not read image';
                resolve(false);
            };
            img.src = URL.createObjectURL(file);
        });
    }

    /**
     * Handle logo file selection
     */
    async function onLogoSelect(event) {
        const file = event.files[0];
        if (file) {
            logoFile.value = file;
            removeLogo.value = false;

            // Validate the logo
            const isValid = await validateLogo(file);

            if (isValid) {
                // Create preview URL
                const reader = new FileReader();
                reader.onload = (e) => {
                    logoPreview.value = e.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                logoPreview.value = null;
            }
        }
    }

    /**
     * Handle logo removal from file upload component
     */
    function onLogoClear() {
        logoFile.value = null;
        logoPreview.value = null;
        logoError.value = null;
        logoValidated.value = false;
        logoDimensions.value = null;
    }

    /**
     * Mark current logo for removal
     */
    function markLogoForRemoval() {
        removeLogo.value = true;
        currentLogo.value = null;
        logoFile.value = null;
        logoPreview.value = null;
        logoError.value = null;
        logoValidated.value = false;
    }

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value === true)
            return;

        // send selection back to the parent
        emit('input_submitter_item', {
            'name': name.value,
            'description': description.value || null,
            'website': website.value || null,
            'assertion': assertion.value || null,
            'logo': logoFile.value,
            'remove_logo': removeLogo.value,
            'contact_id': selectedContact.value?.id || null,
            'allow_submissions': allowSubmissions.value,
            'downloadable': downloadable.value
        });
    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        name.value = props.input.name;
        description.value = props.input.description || '';
        website.value = props.input.website || '';
        assertion.value = props.input.assertion || '';
        currentLogo.value = props.input.logo || null;
        logoFile.value = null;
        logoPreview.value = null;
        logoError.value = null;
        logoValidated.value = false;
        logoDimensions.value = null;
        removeLogo.value = false;
        // Set current contact
        selectedContact.value = props.members?.find(m => m.is_contact) || null;
        // Set allow_submissions and downloadable flags
        allowSubmissions.value = props.input.allow_submissions !== false;  // Default to true
        downloadable.value = props.input.downloadable || false;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" modal @show="initializeInput" header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">Edit Submitter Information</span>
                </div>
            </template>

            <div class="flex flex-col gap-3">
                <!-- Name -->
                <div class="flex items-center gap-3">
                    <label for="nameInput" class="font-semibold w-32 shrink-0">Name</label>
                    <div class="flex-1">
                        <InputText id="nameInput" type="text" v-model="name" class="w-full" autocomplete="off" required />
                        <small v-if="errors.name" class="text-red-600">{{ errors.name }}</small>
                    </div>
                </div>

                <!-- Description -->
                <div class="flex items-start gap-3">
                    <label for="descriptionInput" class="font-semibold w-32 shrink-0 pt-2">Description</label>
                    <div class="flex-1">
                        <Textarea id="descriptionInput" v-model="description" class="w-full" rows="2" autocomplete="off" />
                        <small class="text-gray-500">Markdown supported</small>
                        <small v-if="errors.description" class="text-red-600 block">{{ errors.description }}</small>
                    </div>
                </div>

                <!-- Website & Assertion side by side -->
                <div class="flex gap-4">
                    <div class="flex items-center gap-3 flex-1">
                        <label for="websiteInput" class="font-semibold w-32 shrink-0">Website</label>
                        <div class="flex-1">
                            <InputText id="websiteInput" type="text" v-model="website" class="w-full" autocomplete="off" placeholder="https://example.org" />
                            <small v-if="errors.website" class="text-red-600">{{ errors.website }}</small>
                        </div>
                    </div>
                </div>

                <div class="flex gap-4">
                    <div class="flex items-center gap-3 flex-1">
                        <label for="assertionInput" class="font-semibold w-32 shrink-0">Assertion Criteria</label>
                        <div class="flex-1">
                            <InputText id="assertionInput" type="text" v-model="assertion" class="w-full" autocomplete="off" placeholder="https://example.org/criteria" />
                            <small v-if="errors.assertion" class="text-red-600">{{ errors.assertion }}</small>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="flex items-center gap-3">
                    <label for="contactInput" class="font-semibold w-32 shrink-0">Contact</label>
                    <Dropdown
                        id="contactInput"
                        v-model="selectedContact"
                        :options="members"
                        optionLabel="name"
                        placeholder="Select a contact"
                        class="flex-1"
                        showClear
                    >
                        <template #option="slotProps">
                            <div class="flex items-center">
                                <span>{{ slotProps.option.name }}</span>
                                <span class="text-gray-500 ml-2">({{ slotProps.option.email }})</span>
                            </div>
                        </template>
                        <template #value="slotProps">
                            <div v-if="slotProps.value" class="flex items-center">
                                <span>{{ slotProps.value.name }}</span>
                            </div>
                            <span v-else>{{ slotProps.placeholder }}</span>
                        </template>
                    </Dropdown>
                </div>

                <!-- Checkboxes side by side -->
                <div class="flex gap-6 mt-2">
                    <div class="flex items-center gap-2">
                        <Checkbox id="allowSubmissionsInput" v-model="allowSubmissions" :binary="true" />
                        <label for="allowSubmissionsInput" class="text-gray-700">Allow submissions</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox id="downloadableInput" v-model="downloadable" :binary="true" />
                        <label for="downloadableInput" class="text-gray-700">Include in downloads</label>
                    </div>
                </div>

                <!-- Logo -->
                <div class="flex items-start gap-3 mt-2 pt-2 border-t">
                    <label class="font-semibold w-32 shrink-0">Logo</label>
                    <div class="flex-1 flex flex-wrap items-center gap-3">
                        <!-- Current logo display -->
                        <div v-if="currentLogo && !removeLogo" class="flex items-center gap-2">
                            <img :src="currentLogo" alt="Current logo" class="h-12 w-auto object-contain border rounded p-1" />
                            <Button icon="pi pi-times" severity="danger" text rounded size="small" @click="markLogoForRemoval" title="Remove logo" />
                        </div>

                        <!-- New logo preview -->
                        <div v-if="logoPreview && logoValidated" class="flex items-center gap-2">
                            <img :src="logoPreview" alt="New logo preview" class="h-12 w-auto object-contain border rounded p-1" />
                            <span class="text-sm text-green-600"><i class="pi pi-check-circle mr-1"></i>Valid</span>
                        </div>

                        <!-- File upload -->
                        <FileUpload
                            mode="basic"
                            accept=".png"
                            :maxFileSize="500000"
                            @select="onLogoSelect"
                            @clear="onLogoClear"
                            chooseLabel="Select Logo"
                            class="p-button-outlined p-button-sm"
                        />

                        <!-- Logo error -->
                        <div v-if="logoError" class="text-red-600 text-sm w-full"><i class="pi pi-times-circle mr-1"></i>{{ logoError }}</div>
                        <small class="text-gray-500 w-full">PNG, 800x400px, max 500KB</small>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button label="Cancel" @click="visible = false" severity="secondary" />
                    <Button label="Update" @click="closeCallback" severity="info" :disabled="disabled" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
