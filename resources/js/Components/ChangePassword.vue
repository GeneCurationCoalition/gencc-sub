<script setup>
    import { ref, watch, computed } from 'vue'

    const props = defineProps(['visible']);

    const oldpasswd = ref('');
    const newpasswd = ref('');
    const confirmpw = ref('');

    const localVisible = defineModel('visible')

    watch(() => props.visible, (first, second) => {
        localVisible.value = first;
        // Clear fields when dialog opens
        if (first) {
            oldpasswd.value = '';
            newpasswd.value = '';
            confirmpw.value = '';
        }
    });

    const emit = defineEmits(['input_dialog_close', 'input_password_item'])

    // Password validation rules
    const MIN_LENGTH = 8;

    const passwordErrors = computed(() => {
        const errors = [];
        if (newpasswd.value === '') return errors;

        if (newpasswd.value.length < MIN_LENGTH) {
            errors.push(`Must be at least ${MIN_LENGTH} characters`);
        }
        if (!/[A-Z]/.test(newpasswd.value)) {
            errors.push('Must contain at least one uppercase letter');
        }
        if (!/[a-z]/.test(newpasswd.value)) {
            errors.push('Must contain at least one lowercase letter');
        }
        if (!/[0-9]/.test(newpasswd.value)) {
            errors.push('Must contain at least one number');
        }
        if (oldpasswd.value !== '' && newpasswd.value === oldpasswd.value) {
            errors.push('New password cannot be the same as old password');
        }
        return errors;
    });

    const isPasswordValid = computed(() => {
        return newpasswd.value !== '' && passwordErrors.value.length === 0;
    });

    // Show error message when passwords don't match (only if both have values)
    const passwordMismatch = computed(() => {
        return newpasswd.value !== '' &&
               confirmpw.value !== '' &&
               newpasswd.value !== confirmpw.value;
    });

    // Disable Update button if fields are empty, passwords don't match, or validation fails
    const disabled = computed(() => {
        return oldpasswd.value === '' ||
               newpasswd.value === '' ||
               confirmpw.value === '' ||
               newpasswd.value !== confirmpw.value ||
               !isPasswordValid.value;
    });

    function closeCallback()
    {
        // send selection back to the parent - don't close dialog here
        // parent will close it after successful password change
        emit('input_password_item', {
            'old': oldpasswd.value,
            'new': newpasswd.value
        });
    }
</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="localVisible" modal header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">Change Password</span>
                </div>
            </template>

            
            <div class="grid grid-cols-5">
                <div class="flex items-center col-span-2 gap-3 mb-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Enter Old Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <InputText type="password" v-model="oldpasswd" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center col-span-2 gap-3 mb-1">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Enter New Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-1">
                    <InputText type="password" v-model="newpasswd" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center col-span-2 gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3 mb-3">
                    <div v-if="passwordErrors.length > 0" class="text-xs text-red-600">
                        <div v-for="error in passwordErrors" :key="error">{{ error }}</div>
                    </div>
                    <div v-else-if="newpasswd !== ''" class="text-xs text-green-600">
                        Password meets requirements
                    </div>
                </div>
                <div class="flex items-center col-span-2 gap-3 mb-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Confirm New Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <InputText type="password" v-model="confirmpw" class="flex-auto" autocomplete="off" required/>
                </div>
                <div class="flex items-center col-span-2 gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small v-if="passwordMismatch" class="text-red-600">Passwords do not match</small>
                </div>
            </div>
                
            <template #footer>
                <div class="flex gap-8 bg-slate-50 m-0 p-4">
                    <Button label="Update" @click="closeCallback" outlined severity="info" class="ml-4 p-3" :disabled="disabled" />
                    <Button label="Cancel" @click="localVisible = false" severity="secondary" class="mr-4 p-3" />
                </div>
            </template>              
        </Dialog>
    </div>
</template>