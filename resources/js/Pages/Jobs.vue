<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue';
import JobsListing from '@/Components/JobsListing.vue';
import { useToast } from "primevue/usetoast";
import FileUpload from 'primevue/fileupload';

const props = defineProps(['jobs', 'favorites', 'canCreateJob', 'canUploadToExisting', 'blockReason'])

// Compute message for why job creation is blocked
const blockMessage = computed(() => {
    if (props.blockReason === 'submitted') {
        return null; // Message is now shown in JobsListing component
    } else if (props.blockReason === 'draft_exists') {
        return null; // No message needed for draft_exists
    }
    return null;
})

const toast = useToast();
const axios = window.axios;
const fileUploadRef = ref(null);
const createdJobIdent = ref(null);

// Auto-refresh configuration
const REFRESH_INTERVAL = 15000; // 15 seconds in milliseconds
let refreshTimer = null;

// Create a new job and navigate to job detail page
async function createJob() {
    try {
        const response = await axios.get('/api/jobs/create');

        if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
            const jobIdent = response.data.job.ident;

            toast.add({
                severity: 'success',
                summary: 'Job Created',
                detail: `Draft job ${response.data.job.slug} created successfully`,
                life: 3000
            });

            // Navigate to the job detail page
            router.visit(`/jobs/${jobIdent}`);
            return true;
        } else {
            console.log(response);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to create job',
                life: 3000
            });
        }
    } catch (error) {
        console.error(error);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to create job',
            life: 3000
        });
    }

    return false;
}

// Create job and redirect to job detail page for upload
async function createJobAndUpload() {
    try {
        // If there's an existing draft job without a document, navigate to it instead
        if (props.canUploadToExisting) {
            const draftJob = props.jobs.find(job =>
                job.status === 'draft' &&
                (!job.documents || job.documents.length === 0)
            );

            if (draftJob) {
                console.log('[Upload] Found existing draft job without document:', draftJob.ident);
                sessionStorage.setItem('autoTriggerUpload', 'true');
                console.log('[Upload] Set autoTriggerUpload flag in sessionStorage');
                console.log('[Upload] Redirecting to existing job:', `/jobs/${draftJob.ident}`);
                router.visit(`/jobs/${draftJob.ident}`);
                return;
            }
        }

        console.log('[Upload] Creating new job for upload...');
        const response = await axios.get('/api/jobs/create');
        console.log('[Upload] Job creation response:', response.data);

        if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
            // Job created successfully, get the job ident
            const jobIdent = response.data.job.ident;
            console.log('[Upload] Job created successfully with ident:', jobIdent);

            // Set a flag in sessionStorage to auto-trigger upload on the job page
            sessionStorage.setItem('autoTriggerUpload', 'true');
            console.log('[Upload] Set autoTriggerUpload flag in sessionStorage');

            // Redirect to the job detail page
            console.log('[Upload] Redirecting to job detail page:', `/jobs/${jobIdent}`);
            router.visit(`/jobs/${jobIdent}`);
        } else {
            console.error('[Upload] Job creation failed with response:', response.data);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to create job',
                life: 3000
            });
        }
    } catch (error) {
        console.error('[Upload] Job creation error:', error);
        console.error('[Upload] Error response:', error.response?.data);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to create job',
            life: 3000
        });
    }
}


const createJobDirectly = () => {
    // Create job without confirmation
    createJob();
};

// Setup auto-refresh when component mounts
onMounted(() => {
    refreshTimer = setInterval(() => {
        router.reload({ only: ['jobs'] }); // Only reload jobs data, not the entire page
    }, REFRESH_INTERVAL);
});

// Clear timer when component unmounts
onUnmounted(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
});

</script>

<template>
    <AppLayout title="Submissions">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Jobs
                <div v-if="canCreateJob || canUploadToExisting" class="float-right mt-2 flex gap-3">
                    <Button label="Upload Submissions" severity="success" @click="createJobAndUpload" icon="pi pi-upload"/>
                    <Button v-if="canCreateJob" label="Create a New Job" severity="info" icon="pi pi-plus-circle" @click="createJobDirectly"/>
                </div>
            </h2>
        </template>

        <Toast />

        <!-- Hidden file upload component for Upload Spreadsheet button -->
        <FileUpload
            ref="fileUploadRef"
            mode="basic"
            name="file"
            accept=".xlsx"
            :maxFileSize="10000000"
            @select="onFileSelect"
            :auto="false"
            chooseLabel="Select File"
            style="display: none;"
        />

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <JobsListing :jobs="jobs" :favorites="favorites" :blockMessage="blockMessage" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
