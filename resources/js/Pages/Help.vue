<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePage, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const page = usePage();

const props = defineProps({
    isAdmin: {
        type: Boolean,
        default: false,
    },
    documentMeta: {
        type: Object,
        default: () => ({}),
    },
});

// Generate download URLs with cache-busting query parameters
const downloadUrls = computed(() => ({
    'user-guide': `/download/user-guide?v=${props.documentMeta?.['user-guide']?.cacheBuster || Date.now()}`,
    'api-guide': `/download/api-guide?v=${props.documentMeta?.['api-guide']?.cacheBuster || Date.now()}`,
    'spreadsheet': `/download/template?v=${props.documentMeta?.['spreadsheet']?.cacheBuster || Date.now()}`,
}));

// Upload state
const uploading = ref({
    'user-guide': false,
    'api-guide': false,
    'spreadsheet': false,
});

const uploadError = ref(null);
const uploadSuccess = ref(null);

// File input refs
const userGuideInput = ref(null);
const apiGuideInput = ref(null);
const spreadsheetInput = ref(null);

// Trigger file input click
const triggerUpload = (type) => {
    if (type === 'user-guide') userGuideInput.value?.click();
    else if (type === 'api-guide') apiGuideInput.value?.click();
    else if (type === 'spreadsheet') spreadsheetInput.value?.click();
};

// Handle file selection and upload
const handleFileChange = async (event, type) => {
    const file = event.target.files[0];
    if (!file) return;

    uploading.value[type] = true;
    uploadError.value = null;
    uploadSuccess.value = null;

    const formData = new FormData();
    formData.append('file', file);

    try {
        await axios.post(`/api/admin/documents/${type}`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        uploadSuccess.value = 'Document updated successfully';

        // Refresh the page to show updated metadata
        setTimeout(() => {
            router.reload();
        }, 1000);
    } catch (error) {
        if (error.response?.data?.errors?.file) {
            uploadError.value = error.response.data.errors.file[0];
        } else if (error.response?.data?.message) {
            uploadError.value = error.response.data.message;
        } else {
            uploadError.value = 'Upload failed. Please try again.';
        }
    } finally {
        uploading.value[type] = false;
        // Reset the input so the same file can be selected again
        event.target.value = '';
    }
};
</script>

<template>
    <AppLayout title="Help & Documentation">
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-4xl text-white leading-tight">
                    Help & Documentation
                </h2>
                <span class="text-sm font-normal text-white/70">
                    version: {{ page.props.appVersion }}
                </span>
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

                <!-- Toast notifications -->
                <div v-if="uploadError" class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ uploadError }}</span>
                    <button @click="uploadError = null" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
                    </button>
                </div>

                <div v-if="uploadSuccess" class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ uploadSuccess }}</span>
                    <button @click="uploadSuccess = null" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
                    </button>
                </div>

                <!-- Hidden file inputs -->
                <input type="file" ref="userGuideInput" accept=".pdf" class="hidden" @change="(e) => handleFileChange(e, 'user-guide')" />
                <input type="file" ref="apiGuideInput" accept=".pdf" class="hidden" @change="(e) => handleFileChange(e, 'api-guide')" />
                <input type="file" ref="spreadsheetInput" accept=".xlsx" class="hidden" @change="(e) => handleFileChange(e, 'spreadsheet')" />

                <!-- Downloads Section -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-2xl font-bold text-gray-900 mb-6">Downloads</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- User Guide Card -->
                            <div class="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition-colors">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                        <h4 class="ml-3 text-lg font-semibold text-gray-900">User Guide</h4>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        PDF
                                    </span>
                                </div>

                                <p class="text-gray-600 text-sm mb-3">
                                    Complete guide to using the GenCC Submission Portal, including:
                                </p>
                                <ul class="text-gray-600 text-sm mb-4 ml-4 list-disc space-y-1">
                                    <li>Dashboard overview and navigation</li>
                                    <li>Uploading submissions from spreadsheets</li>
                                    <li>Adding submissions manually</li>
                                    <li>Publishing, unpublishing, and republishing workflows</li>
                                    <li>Troubleshooting common errors</li>
                                </ul>

                                <div class="flex items-center gap-2">
                                    <a :href="downloadUrls['user-guide']"
                                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Download
                                    </a>
                                    <button v-if="isAdmin"
                                            @click="triggerUpload('user-guide')"
                                            :disabled="uploading['user-guide']"
                                            class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150">
                                        <svg v-if="uploading['user-guide']" class="animate-spin h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                        </svg>
                                        Update
                                    </button>
                                </div>
                            </div>

                            <!-- Submission Spreadsheet Card -->
                            <div class="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition-colors">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5" />
                                        </svg>
                                        <h4 class="ml-3 text-lg font-semibold text-gray-900">Submission Spreadsheet Template</h4>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Excel
                                    </span>
                                </div>

                                <p class="text-gray-600 text-sm mb-4">
                                    Official GenCC submission template (Version 2) with built-in guidance. The spreadsheet includes reference lists for Submitter IDs, Classification codes, and Mode of Inheritance codes.
                                </p>

                                <div class="flex items-center gap-2">
                                    <a :href="downloadUrls['spreadsheet']"
                                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Download
                                    </a>
                                    <button v-if="isAdmin"
                                            @click="triggerUpload('spreadsheet')"
                                            :disabled="uploading['spreadsheet']"
                                            class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150">
                                        <svg v-if="uploading['spreadsheet']" class="animate-spin h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                        </svg>
                                        Update
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Documentation Section -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg mb-6">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-2xl font-bold text-gray-900 mb-6">API Documentation</h3>

                        <div class="max-w-md">
                            <!-- API Guide Card -->
                            <div class="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition-colors">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                        </svg>
                                        <h4 class="ml-3 text-lg font-semibold text-gray-900">API Guide</h4>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        PDF
                                    </span>
                                </div>

                                <p class="text-gray-600 text-sm mb-4">
                                    Guide for programmatic interaction with the GenCC Submission Portal API, including authentication, endpoints, data schema, and code examples.
                                </p>

                                <div class="flex items-center gap-2">
                                    <a :href="downloadUrls['api-guide']"
                                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Download
                                    </a>
                                    <button v-if="isAdmin"
                                            @click="triggerUpload('api-guide')"
                                            :disabled="uploading['api-guide']"
                                            class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150">
                                        <svg v-if="uploading['api-guide']" class="animate-spin h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                        </svg>
                                        Update
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Us Section -->
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">
                        <h3 class="text-2xl font-bold text-gray-900 mb-6">Contact Us</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- General Inquiries -->
                            <div class="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition-colors">
                                <div class="flex items-center mb-4">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                    <h4 class="ml-3 text-lg font-semibold text-gray-900">General Inquiries</h4>
                                </div>

                                <p class="text-gray-600 text-sm mb-4">
                                    For scientific questions, data inquiries, or interest in GenCC participation.
                                </p>

                                <div class="flex items-center text-sm text-gray-500 mb-4">
                                    <span class="font-mono bg-gray-100 px-2 py-1 rounded">gencc@thegencc.org</span>
                                </div>

                                <a href="mailto:gencc@thegencc.org"
                                   class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    Send Email
                                </a>
                            </div>

                            <!-- Technical Support -->
                            <div class="border border-gray-200 rounded-lg p-6 hover:border-indigo-300 transition-colors">
                                <div class="flex items-center mb-4">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                                    </svg>
                                    <h4 class="ml-3 text-lg font-semibold text-gray-900">Technical Support</h4>
                                </div>

                                <p class="text-gray-600 text-sm mb-4">
                                    For portal functionality questions, upload issues, or error troubleshooting.
                                </p>

                                <div class="flex items-center text-sm text-gray-500 mb-4">
                                    <span class="font-mono bg-gray-100 px-2 py-1 rounded">gencc-tech@broadinstitute.org</span>
                                </div>

                                <a href="mailto:gencc-tech@broadinstitute.org"
                                   class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    Send Email
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
