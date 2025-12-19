<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';
import { computed, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useForm, usePage } from '@inertiajs/vue3';

const toast = useToast();
const page = usePage();
const successMsg = computed(() => page.props.flash.success);


const form = useForm({
    first_name: null,
    last_name: null,
    address: null,
    city: null,
    state: null,
    zip: null,
});
const dropdownItem = ref(null);
const dropdownItems = ref([
    { name: 'Option 1', code: 'Option 1' },
    { name: 'Option 2', code: 'Option 2' },
    { name: 'Option 3', code: 'Option 3' }
]);

const onChange = (e) => {
    form.state = e.value.code
}

const submit = () => {
    form.post(route('user-informations.store'), {
        onSuccess: (res) => {
            form.reset();
            toast.add({ severity: 'success', summary: 'Success Message', detail: successMsg, life: 3000 });
        }
    })
}
</script>

<template>
    <AppLayout>
        <Toast />
        <div class="py-4 px-20">
            <div class="card bg-white p-7 rounded-md shadow-sm">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight mb-8">Member Information</h2>
                <div class="p-fluid formgrid grid">
                    <form @submit.prevent="submit">
                    <div class="flex flex-row gap-4">
                        <div class="field col-12 md:col-6 mb-3 w-full">
                            <label for="firstname2">Firstname</label>
                            <InputText 
                                id="first_name" 
                                type="text" 
                                v-model="form.first_name" 
                                class="rounded-md" 
                            />

                            <InputError class="mt-2" :message="form.errors.first_name" />
                        </div>
                        <div class="field col-12 md:col-6 mb-3 w-full">
                            <label for="lastname2">Lastname</label>
                            <InputText 
                                id="last_name" 
                                type="text" 
                                v-model="form.last_name" 
                                class="rounded-md" 
                            />

                            <InputError class="mt-2" :message="form.errors.last_name" />
                        </div>
                    </div>
                    <div class="field col-12 mb-3">
                        <label for="address">Address</label>
                        <Textarea 
                            id="address" 
                            rows="4" 
                            v-model="form.address" 
                            class="rounded-md"
                        />

                        <InputError class="mt-2" :message="form.errors.address" />
                    </div>
                    
                    <div class="flex flex-row gap-4">
                        <div class="field col-12 md:col-6 mb-3 w-full">
                            <label for="city">City</label>
                            <InputText 
                                id="city" 
                                type="text" 
                                v-model="form.city" 
                                class="rounded-md" 
                            />

                            <InputError class="mt-2" :message="form.errors.city" />
                        </div>
                        <div class="field col-12 md:col-3 mb-3 w-full">
                            <label for="state">State</label>
                            <Dropdown 
                                id="state" 
                                v-model="dropdownItem" 
                                :options="dropdownItems" 
                                optionLabel="name" 
                                placeholder="Select One"
                                @change="onChange($event)"
                                class="rounded-md">
                            </Dropdown>

                            <InputError class="mt-2" :message="form.errors.state" />
                        </div>
                        <div class="field col-12 md:col-3 mb-3 w-full">
                            <label for="zip">Zip</label>
                            <InputText 
                            id="zip"
                            v-model="form.zip" 
                            type="text" 
                            class="rounded-md" 
                        />
                        </div>

                        <InputError class="mt-2" :message="form.errors.zip" />
                    </div>
                    <div class="flex items-center justify-end">
                        <PrimaryButton 
                            class="ms-4" 
                            :class="{ 'opacity-25': form.processing }" 
                            :disabled="form.processing">
                                SUBMIT
                        </PrimaryButton>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>