<script setup>
    import { ref, watch, computed } from 'vue'
    import { useForm } from 'vee-validate';
    import * as yup from 'yup';

    const props = defineProps(['visible', 'input']);

    // component side validations
    const schema = yup.object({
        name: yup.string().required().label('Name').max(248),
        title: yup.string().nullable().label('Title').max(100),
        email: yup.string().required().email().label('Email').max(248),
        phone: yup.string().nullable().label('Phone').max(50),
    });

    const { defineField, handleSubmit, resetForm, errors } = useForm({
        validationSchema: schema,
    });

    const [name] = defineField('name');
    const [title] = defineField('title');
    const [email] = defineField('email');
    const [phone] = defineField('phone');

    const disabled = computed(() => {
        return Object.keys(errors.value).length !== 0
    })

    // local vars
    const visible = defineModel('visible')

    // both the child and parent can trigger visibility, so we need a watcher
    watch(() => props.visible, (first, second) => {
        visible.value = first;
    });

    const emit = defineEmits(['input_dialog_close', 'input_profile_item'])

    /**
     * Callback function when user clicks on the update button
     */
    function closeCallback()
    {
        // one last check for errors
        if (disabled.value === true)
            return;

        // send selection back to the parent
        emit('input_profile_item', {
            'name': name.value,
            'title': title.value || null,
            'email': email.value,
            'phone': phone.value || null
        });
        emit('input_dialog_close');
    }

    /**
     * Function to initialize the local models used in child components with props values
     */
    function initializeInput()
    {
        name.value = props.input?.name || '';
        title.value = props.input?.title || '';
        email.value = props.input?.email || '';
        phone.value = props.input?.phone || '';
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="visible" modal @show="initializeInput" header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">Edit Profile</span>
                </div>
            </template>

            <div class="grid grid-cols-4 gap-y-4">
                <!-- Name -->
                <div class="flex items-center gap-3">
                    <label for="nameInput" class="flex items-center font-semibold w-6rem">Name</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText id="nameInput" type="text" v-model="name" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small class="text-red-600">{{ errors.name }}</small>
                </div>

                <!-- Title/Role -->
                <div class="flex items-center gap-3">
                    <label for="titleInput" class="flex items-center font-semibold w-6rem">Title</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText id="titleInput" type="text" v-model="title" class="flex-auto" autocomplete="off" placeholder="e.g., Coordinator, Director, Curator" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small class="text-red-600">{{ errors.title }}</small>
                </div>

                <!-- Email -->
                <div class="flex items-center gap-3">
                    <label for="emailInput" class="flex items-center font-semibold w-6rem">Email</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText id="emailInput" type="email" v-model="email" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small class="text-red-600">{{ errors.email }}</small>
                </div>

                <!-- Phone -->
                <div class="flex items-center gap-3">
                    <label for="phoneInput" class="flex items-center font-semibold w-6rem">Phone</label>
                </div>
                <div class="flex items-center col-span-3 gap-3">
                    <InputText id="phoneInput" type="text" v-model="phone" class="flex-auto" autocomplete="off" placeholder="e.g., 949-900-5500" />
                </div>
                <div class="flex items-center gap-3">
                    &nbsp;
                </div>
                <div class="flex items-center col-span-3">
                    <small class="text-red-600">{{ errors.phone }}</small>
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
