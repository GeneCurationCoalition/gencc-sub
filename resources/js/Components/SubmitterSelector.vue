<template>
    <div class="ms-3 relative" v-if="$page.isGenccAdmin">
        <Dropdown align="right" width="60">
            <template #trigger>
                <span class="inline-flex rounded-md">
                    <button
                        type="button"
                        class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none focus:bg-gray-50 active:bg-gray-50 transition ease-in-out duration-150"
                    >
                        <i class="pi pi-users me-2"></i>
                        {{ selectedSubmitterName }}

                        <svg class="ms-2 -me-0.5 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                        </svg>
                    </button>
                </span>
            </template>

            <template #content>
                <div class="w-60">
                    <div class="block px-4 py-2 text-xs text-gray-400">
                        Select Submitter to View
                    </div>

                    <div class="border-t border-gray-200" />

                    <!-- Scrollable submitter list with max height -->
                    <div class="max-h-96 overflow-y-auto">
                        <template v-for="submitter in $page.submitters" :key="submitter.id">
                            <button
                                @click="selectSubmitter(submitter)"
                                class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition duration-150 ease-in-out"
                                :class="{ 'bg-gray-50 text-gray-900': isSelected(submitter) }"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ submitter.name }}</div>
                                        <div class="text-xs text-gray-500">{{ submitter.curie }}</div>
                                    </div>
                                    <svg
                                        v-if="isSelected(submitter)"
                                        class="h-5 w-5 text-green-400"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </button>
                        </template>
                    </div>

                    <div class="border-t border-gray-200 mt-2" />

                    <button
                        v-if="$page.selectedSubmitter"
                        @click="clearSelection"
                        class="block w-full text-left px-4 py-2 text-sm text-gray-500 hover:bg-gray-100 transition duration-150 ease-in-out"
                    >
                        <i class="pi pi-times me-2"></i>
                        Clear Selection
                    </button>
                </div>
            </template>
        </Dropdown>

        <!-- Warning Modal -->
        <ConfirmationModal :show="showWarning" @close="cancelSubmitterChange">
            <template #title>
                Unsaved Changes Warning
            </template>

            <template #content>
                <p class="mb-4">
                    You are about to change the submitter while not on the Dashboard page.
                </p>
                <p class="mb-4">
                    Changing the submitter will navigate you to the Dashboard and <strong>any unsaved work on the current page will be lost</strong>.
                </p>
                <div v-if="pendingSubmitter" class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-sm font-medium text-gray-900 mb-1">New submitter:</p>
                    <p class="font-medium text-gray-900">{{ pendingSubmitter.name }}</p>
                    <p class="text-sm text-gray-600">{{ pendingSubmitter.curie }}</p>
                </div>
                <p class="mt-4 text-sm text-gray-600">
                    Do you want to continue?
                </p>
            </template>

            <template #footer>
                <SecondaryButton @click="cancelSubmitterChange" class="me-3">
                    Cancel
                </SecondaryButton>

                <DangerButton @click="confirmSubmitterChange">
                    Continue & Change Submitter
                </DangerButton>
            </template>
        </ConfirmationModal>

        <!-- Confirmation Modal -->
        <Modal :show="showConfirmation" @close="closeConfirmation" max-width="md">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="flex-shrink-0">
                        <svg class="h-8 w-8 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-lg font-medium text-gray-900">
                            Submitter Selected
                        </h3>
                    </div>
                </div>

                <div class="mt-2">
                    <p class="text-sm text-gray-600">
                        You have successfully selected:
                    </p>
                    <div class="mt-3 p-4 bg-gray-50 rounded-lg">
                        <div class="font-medium text-gray-900">{{ confirmationSubmitter?.name }}</div>
                        <div class="text-sm text-gray-500">{{ confirmationSubmitter?.curie }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <PrimaryButton type="button" @click="closeConfirmation">
                        Okay
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const { props: $page } = usePage();

const showConfirmation = ref(false);
const confirmationSubmitter = ref(null);
const showWarning = ref(false);
const pendingSubmitter = ref(null);

const selectedSubmitterName = computed(() => {
    const selected = $page.selectedSubmitter;
    if (selected) {
        return selected.name;
    }
    return 'Select Submitter';
});

const isOnDashboard = computed(() => {
    return window.location.pathname === '/dashboard' || window.location.pathname === '/';
});

const selectSubmitter = (submitter) => {
    // If on dashboard, proceed immediately
    if (isOnDashboard.value) {
        performSubmitterChange(submitter);
    } else {
        // Show warning dialog
        pendingSubmitter.value = submitter;
        showWarning.value = true;
    }
};

const performSubmitterChange = (submitter) => {
    // Create URL with query parameters for GET request
    const url = new URL(window.route('user.select-submitter'), window.location.origin);
    url.searchParams.append('submitter_id', submitter.id);
    url.searchParams.append('redirect_to', '/dashboard');

    // Use window.location to perform a full page redirect
    console.log('Changing submitter to:', submitter.name);
    window.location.href = url.toString();
};

const confirmSubmitterChange = () => {
    showWarning.value = false;
    if (pendingSubmitter.value) {
        performSubmitterChange(pendingSubmitter.value);
        pendingSubmitter.value = null;
    }
};

const cancelSubmitterChange = () => {
    showWarning.value = false;
    pendingSubmitter.value = null;
};

const clearSelection = () => {
    // Create URL with query parameters for GET request
    const url = new URL(window.route('user.select-submitter'), window.location.origin);
    url.searchParams.append('clear', '1');
    url.searchParams.append('redirect_to', '/dashboard');

    // Use window.location to perform a full page redirect
    console.log('Clearing submitter selection');
    window.location.href = url.toString();
};

const isSelected = (submitter) => {
    return $page.selectedSubmitter && $page.selectedSubmitter.id === submitter.id;
};

const closeConfirmation = () => {
    showConfirmation.value = false;
    confirmationSubmitter.value = null;
};
</script>
