<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Dashboard from '@/Components/Dashboard.vue';
import Button from 'primevue/button';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

const props = defineProps(['total_jobs_processing', 'total_submissions_processing', 'active_new_count', 'active_republish_count', 'active_unpublish_count', 'token_expire_date', 'total_submissions_published',
                           'token_days', 'job_labels', 'classifications', 'pending_classifications', 'has_pending_changes', 'submissions_new', 'submissions_republished', 'submissions_unpublished_chart', 'total_jobs_errors', 'total_submissions_errors',
                           'total_jobs_completed', 'total_submissions_unpublished',
                           'unprocessed_job_status', 'unprocessed_job_date', 'unprocessed_job_slug', 'unprocessed_job_ident', 'unprocessed_job_is_publishing', 'unprocessed_job_is_processing',
                           'unprocessed_new_count', 'unprocessed_republish_count', 'unprocessed_unpublish_count', 'unprocessed_error_count', 'has_submitter', 'submitter_curie',
                           'total_unique_sids',
                           // Section 1: Submissions Released
                           'released_first_version_count', 'released_republish_count', 'released_unpublish_count', 'released_total',
                           // Section 2: Submissions Awaiting Release
                           'awaiting_first_version_count', 'awaiting_republish_count', 'awaiting_unpublish_count', 'awaiting_total',
                           // Section 3: Submissions Archived
                           'archived_first_version_unique', 'archived_republish_unique', 'archived_unpublish_unique',
                           'archived_first_version_total', 'archived_republish_total', 'archived_unpublish_total',
                           'archived_unique_total', 'archived_total',
                           // Admin-specific data
                           'is_admin', 'all_pending_jobs', 'submitted_jobs_count', 'pubmed_status', 'disease_status', 'gene_status', 'admin_logs',
                           'needs_release_repair', 'release_repair_reason'])

const toast = useToast();
const isSyncingClingen = ref(false);

// Check if user is ClinGen submitter
const isClingenSubmitter = computed(() => {
    return props.submitter_curie === 'GENCC:000102';
});

// Run ClinGen GCI Sync and download zip file
async function syncClingenSubmissions() {
    isSyncingClingen.value = true;
    try {
        console.log('[Dashboard] Starting ClinGen sync...');
        const response = await axios.post('/clingen/sync');
        console.log('[Dashboard] Sync response:', response.data);

        if (response.data.success) {
            toast.add({
                severity: 'success',
                summary: 'Sync Complete',
                detail: 'ClinGen submissions synced successfully',
                life: 3000
            });

            // Trigger file download
            const downloadUrl = response.data.download_url;
            console.log('[Dashboard] Downloading file from:', downloadUrl);

            // Create a temporary link element and trigger download
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = response.data.filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else {
            console.error('[Dashboard] Sync failed:', response.data.message);
            toast.add({
                severity: 'error',
                summary: 'Sync Failed',
                detail: response.data.message || 'Failed to sync ClinGen submissions',
                life: 5000
            });
        }
    } catch (error) {
        console.error('[Dashboard] Sync error:', error);
        console.error('[Dashboard] Error response:', error.response?.data);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'Failed to sync ClinGen submissions',
            life: 5000
        });
    } finally {
        isSyncingClingen.value = false;
    }
}

// Auto-refresh dashboard when page becomes visible
let visibilityHandler = null;

onMounted(() => {
    visibilityHandler = () => {
        if (!document.hidden) {
            // Reload dashboard data when user returns to the page
            router.reload({ only: [
                'total_jobs_processing', 'total_submissions_processing',
                'total_jobs_errors', 'total_submissions_errors',
                'total_jobs_completed', 'total_submissions_published',
                'total_jobs_window', 'total_submissions_window',
                'window_job_percentage', 'window_submission_percentage',
                'window_job_daily_avg', 'window_submission_daily_avg'
            ]});
        }
    };

    document.addEventListener('visibilitychange', visibilityHandler);
});

onUnmounted(() => {
    if (visibilityHandler) {
        document.removeEventListener('visibilitychange', visibilityHandler);
    }
});

</script>

<template>
    <AppLayout title="Dashboard">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-4xl text-white leading-tight">
                    Dashboard
                </h2>
                <!-- ClinGen Sync Button (only for ClinGen submitter) -->
                <Button
                    v-if="isClingenSubmitter"
                    label="GCI Sync Submissions"
                    icon="pi pi-sync"
                    severity="success"
                    :loading="isSyncingClingen"
                    @click="syncClingenSubmissions"
                    title="Run ClinGen GCI sync pipeline and download results"
                />
            </div>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <Dashboard :total_submissions_processing="total_submissions_processing" :total_jobs_processing="total_jobs_processing" :active_new_count="active_new_count" :active_republish_count="active_republish_count" :active_unpublish_count="active_unpublish_count" :token_expire_date="token_expire_date"
                                :job_labels="job_labels" :token_days="token_days" :classifications="classifications" :pending_classifications="pending_classifications" :has_pending_changes="has_pending_changes" :submissions_new="submissions_new" :submissions_republished="submissions_republished" :submissions_unpublished_chart="submissions_unpublished_chart"
                                :total_submissions_errors="total_submissions_errors"  :total_jobs_errors="total_jobs_errors"
                                :total_submissions_published="total_submissions_published" :total_submissions_unpublished="total_submissions_unpublished" :total_jobs_completed="total_jobs_completed"
                                :unprocessed_job_status="unprocessed_job_status" :unprocessed_job_date="unprocessed_job_date" :unprocessed_job_slug="unprocessed_job_slug" :unprocessed_job_ident="unprocessed_job_ident" :unprocessed_job_is_publishing="unprocessed_job_is_publishing" :unprocessed_job_is_processing="unprocessed_job_is_processing"
                                :unprocessed_new_count="unprocessed_new_count" :unprocessed_republish_count="unprocessed_republish_count"
                                :unprocessed_unpublish_count="unprocessed_unpublish_count" :unprocessed_error_count="unprocessed_error_count"
                                :has_submitter="has_submitter"
                                :total_unique_sids="total_unique_sids"
                                :released_first_version_count="released_first_version_count" :released_republish_count="released_republish_count"
                                :released_unpublish_count="released_unpublish_count" :released_total="released_total"
                                :awaiting_first_version_count="awaiting_first_version_count" :awaiting_republish_count="awaiting_republish_count"
                                :awaiting_unpublish_count="awaiting_unpublish_count" :awaiting_total="awaiting_total"
                                :archived_first_version_unique="archived_first_version_unique" :archived_republish_unique="archived_republish_unique"
                                :archived_unpublish_unique="archived_unpublish_unique" :archived_first_version_total="archived_first_version_total"
                                :archived_republish_total="archived_republish_total" :archived_unpublish_total="archived_unpublish_total"
                                :archived_unique_total="archived_unique_total" :archived_total="archived_total"
                                :is_admin="is_admin" :all_pending_jobs="all_pending_jobs"
                                :submitted_jobs_count="submitted_jobs_count" :pubmed_status="pubmed_status"
                                :disease_status="disease_status" :gene_status="gene_status"
                                :admin_logs="admin_logs"
                                :needs_release_repair="needs_release_repair"
                                :release_repair_reason="release_repair_reason" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
