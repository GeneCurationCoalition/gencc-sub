<script setup>
import { computed, ref, onMounted, onUnmounted, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from "primevue/useconfirm";
import SubmissionsListing from './SubmissionsListing.vue';
import FileUpload from 'primevue/fileupload';
import { useToast } from "primevue/usetoast";
import Dialog from 'primevue/dialog';
import ProgressBar from 'primevue/progressbar';
import Card from 'primevue/card';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Button from 'primevue/button';

const props = defineProps(['job', 'submissions', 'errors', 'favorites', 'hasSubmittedJob'])

const newid = ref(0);
const axios = window.axios;

const toast = useToast();
const confirm = useConfirm();
const uploadErrors = ref([]);
const uploadWarnings = ref([]);
const showErrorCard = ref(false);
const uploadedFilename = ref('');
const uploadedDocumentId = ref(null);
const uploadedFileSize = ref(0);
const uploadedFileDate = ref(null);
const showUploadedFilePreview = ref(false);

// Simplified upload state
const uploadState = ref('idle'); // 'idle' | 'validating' | 'uploading'

// Upload progress tracking
const uploadProgress = ref({
  has_document: false,
  upload_state: null,
  processed_submissions: 0,
  total_submissions: 0,
  progress_percent: 0,
  is_processing: false,
  processing_errors: null
});

// Polling interval reference
let pollingInterval = null;

// Dialogs
const validatingDialog = ref({
  visible: false,
  filename: '',
  message: 'Please wait while we validate'
});

const partialUploadWarningDialog = ref({
  visible: false,
  error: null
});

let uploadAbortController = null;

// Match PHP SubmissionFileValidation::MAX_VALIDATION_RESULTS
// Set to 0 to disable the limit and show all validation errors
const MAX_VALIDATION_RESULTS = 0;

const displayStatus = computed(() => {
  switch (props.job.status) {
    case 0: return 'Initializing';
    case 1: return 'Newly Submitted';
    case 2: return 'Processing';
    case 3: return 'Completed';
    case 4: return 'Processing w/ errors';
    case 5: return 'Staged';
    case 9: return 'Removed';
    case 99: return 'Failed';
  }
});

// V2 status display for jobs (simplified 3-state model)
function displayStatusV2(status) {
    const statusMap = {
        'draft': 'Draft',
        'submitted': 'Submitted',
        'released': 'Released',
        'processed': 'Released'  // Backwards compatibility
    };
    return statusMap[status] || status;
}

// Get severity for job status badge
function getStatusSeverity(status) {
    // PrimeVue Tag severities: success, info, warning, danger, secondary, contrast
    const severityMap = {
        'draft': 'warning',       // Yellow
        'submitted': 'info',       // Blue
        'released': 'success',     // Green
        'processed': 'success'     // Green (backwards compatibility)
    };
    return severityMap[status] || 'secondary';
}

// Get custom CSS class for job status badges
function getJobStatusClass(status) {
    const classMap = {
        'draft': 'job-status-draft',
        'submitted': 'job-status-submitted',
        'released': 'job-status-released',
        'processed': 'job-status-released'  // Backwards compatibility
    };
    return classMap[status] || '';
}

// Get the status date based on job status
// Draft: created_at, Submitted: submitted_at, Released: released_at
function getStatusDate(job) {
    if (!job.status) return null;

    let dateStr = null;
    switch (job.status) {
        case 'draft':
            dateStr = job.created_at;
            break;
        case 'submitted':
            dateStr = job.submitted_at || job.created_at;
            break;
        case 'released':
        case 'processed':  // Backwards compatibility
            dateStr = job.released_at || job.submitted_at || job.created_at;
            break;
        default:
            dateStr = job.created_at;
    }

    if (!dateStr) return null;
    return dateStr;
}

// Format date as YYYY-MM-DD
function formatDateTimestamp(dateStr) {
    if (!dateStr) return '';
    const date = new Date(Date.parse(dateStr));
    return date.toISOString().split('T')[0];
}

// Computed property to check if job has any errors (submission errors or invalid file)
const hasErrors = computed(() => {
    // Check if any submissions have errors
    const hasSubmissionErrors = props.submissions && props.submissions.some(s => s.submission_errors);

    // Check if there's an invalid file (validation errors in uploadErrors)
    const hasFileErrors = uploadErrors.value && uploadErrors.value.length > 0;

    return hasSubmissionErrors || hasFileErrors;
});

// Computed: Check if document has validation errors (NOT partial upload - that's different!)
const hasValidationErrors = computed(() => {
    if (!props.job.documents || props.job.documents.length === 0) return false;
    const doc = props.job.documents[0];
    // Validation errors = upload_state is 'validation_failed' OR uploadErrors has real validation errors
    // Note: partial uploads have upload_state = 'upload_partial' which is NOT a validation error
    return doc.upload_state === 'validation_failed' || uploadErrors.value.length > 0;
});

// Computed: Check if document has partial upload (timed out or errored during processing)
const hasPartialUpload = computed(() => {
    if (!props.job.documents || props.job.documents.length === 0) return false;
    const doc = props.job.documents[0];
    return doc.upload_state === 'upload_partial';
});

// Helper to detect file format errors (by flag or by error_type for backwards compatibility)
const isFileFormatError = (err) => {
    // Check the explicit flag first
    if (err.is_file_format_error) return true;
    // Fallback: check error_type for known file format error types
    const fileFormatErrorTypes = [
        'invalid_file_format',
        'missing_header_row',
        'invalid_header_columns',
        'no_header_row_found',      // legacy
        'minimum_rows_requirement'   // legacy
    ];
    return fileFormatErrorTypes.includes(err.error_type);
};

// Generate user-friendly message for file format errors (fallback for old errors without user_message)
const getFileFormatUserMessage = (err) => {
    // If user_message exists and differs from message, use it
    if (err.user_message && err.user_message !== err.message) {
        return err.user_message;
    }
    // Generate a friendly message based on error type
    const defaultMessage = 'The uploaded file does not appear to be a valid GenCC submission template. ' +
        'Please download the official template from the GenCC website and ensure your data is formatted correctly.';
    return defaultMessage;
};

// Computed: Separate file format errors from data row errors
// File format errors are displayed in a clean, user-friendly card
const fileFormatErrors = computed(() => {
    return uploadErrors.value.filter(err => isFileFormatError(err));
});

// Data row errors are displayed in the table format
const dataRowErrors = computed(() => {
    return uploadErrors.value.filter(err => !isFileFormatError(err));
});

// Computed: PMID normalization warnings
const pmidWarnings = computed(() => {
    return uploadWarnings.value.filter(err => err.error_type === 'pmid_normalization_warning');
});

// Computed: Check if document can be cleared (validation failed OR partial upload, but NOT during active processing)
const canClearDocument = computed(() => {
    if (!props.job.documents || props.job.documents.length === 0) return false;
    if (uploadProgress.value.is_processing) return false;
    const doc = props.job.documents[0];
    return doc.upload_state === 'validation_failed' || doc.upload_state === 'upload_partial';
});

const onUpload = () => {
  toast.add({ severity: 'success', summary: 'Success', detail: 'File Uploaded', life: 3000 });
};

function createSubmission() {
  axios.post('/api/submissions/create', { job: props.job.ident })
      .then(response => {
        if (response.data?.status_code === 200) {
          newid.value = response.data.sid;
          router.visit('/submissions/' + newid.value);
        } else {
          console.log(response);
        }
      })
      .catch(console.log);
}

function submit() {
  console.log('Submit function called for job:', props.job.slug);
  axios.post('/api/jobs/submit/' + props.job.slug)
      .then(response => {
        console.log('Submit response:', response);
        if (response.data?.status_code === 200) {
          console.log('Success! Reloading page...');
          router.reload();
        } else {
          console.log('Submit failed with response:', response);
        }
      })
      .catch(error => {
        console.error('Submit error:', error);
        console.error('Error response:', error.response);
      });
}

function unpublish() {
  axios.get('/api/jobs/unpublish/' + props.job.slug)
      .then(response => {
        if (response.data?.status_code === 200) router.reload();
        else console.log(response);
      })
      .catch(console.log);
}

const requireConfirmation = () => {
  confirm.require({
    group: 'confirmsub',
    header: 'Are you sure?',
    message: 'Please confirm to create a new submission.',
    accept: createSubmission
  });
};

const publishConfirmation = () => {
  confirm.require({
    group: 'confirmpub',
    header: 'Submit Job',
    message: "Please confirm that you want to submit this job's submissions for processing. Once submitted, this action cannot be undone.",
    acceptLabel: 'Submit',
    rejectLabel: 'Cancel',
    accept: submit
  });
};

const unpublishConfirmation = () => {
  confirm.require({
    group: 'confirmunpub',
    header: 'Are you sure?',
    message: 'Please confirm to unqueue this job.',
    accept: unpublish
  });
};

const displayErrorCard = (errors) => {
  // Filter out empty/invalid errors and partial_upload errors (those are displayed separately)
  const validErrors = errors.filter(err => {
    // Exclude partial_upload errors - they're NOT validation errors and are displayed separately
    if (err.error_type === 'partial_upload') {
      return false;
    }
    // Keep error if it has a message, or if it's not a validation_error type
    return (err.message && err.message.trim() !== '') ||
           (err.error_type && err.error_type !== 'validation_error');
  });

  // Separate warnings from errors
  const warnings = validErrors.filter(err => err.severity === 'warning');
  const actualErrors = validErrors.filter(err => err.severity !== 'warning');
  uploadWarnings.value = warnings;
  uploadErrors.value = actualErrors;

  // Only show error card if there are valid errors
  if (actualErrors.length > 0) {
    showErrorCard.value = true; // Default to expanded when errors first appear
    expandedErrorRows.value = {}; // Reset expanded state when showing new errors
  } else if (warnings.length > 0) {
    console.log('Validation passed with warnings:', warnings);
  } else {
    console.log('Ignoring empty/invalid errors:', errors);
  }
};

// Track which error rows are expanded (by index)
const expandedErrorRows = ref({});

// Toggle expansion for a specific error row
const toggleRowExpansion = (index) => {
  expandedErrorRows.value[index] = !expandedErrorRows.value[index];
};

// Format rows for display: show first 3, then ellipsis + count if more
// Returns object with display text and metadata
const formatRowsForDisplay = (rowsString, index) => {
  if (!rowsString) return { text: '', hasMore: false, totalCount: 0 };

  const rowsArray = rowsString.split(',').map(r => r.trim());
  const totalCount = rowsArray.length;

  if (totalCount <= 3) {
    return {
      text: rowsArray.join(', '),
      hasMore: false,
      totalCount,
      isExpanded: false
    };
  }

  const isExpanded = expandedErrorRows.value[index];

  if (isExpanded) {
    return {
      text: rowsArray.join(', '),
      hasMore: true,
      totalCount,
      isExpanded: true
    };
  }

  const firstThree = rowsArray.slice(0, 3).join(', ');
  return {
    text: firstThree,
    hasMore: true,
    totalCount,
    isExpanded: false,
    remaining: totalCount - 3
  };
};

const downloadErrors = () => {
  const headers = 'Error Type,Severity,Message,Rows\n';
  const rows = uploadErrors.value.map(error => {
    const errorType = error.error_type || 'validation_error';
    const severity = error.severity || 'error';
    const message = error.message || '';
    const rowsList = error.rows || '';

    return `"${errorType}","${severity}","${message.replace(/"/g, '""')}","${rowsList}"`;
  }).join('\n');

  const csvContent = headers + rows;
  const blob = new Blob([csvContent], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  const baseFilename = uploadedFilename.value ? uploadedFilename.value.replace(/\.[^/.]+$/, '') : 'processing';
  const dateStr = new Date().toISOString().split('T')[0];
  a.download = `${baseFilename}-errors-${dateStr}.csv`;
  a.click();
  window.URL.revokeObjectURL(url);
};

// -----------------------------
// Polling mechanism for upload progress
// -----------------------------
const startPolling = () => {
  console.log('[Upload] Starting polling for job:', props.job.ident);

  // Clear any existing polling interval
  stopPolling();

  // Poll every 1 second
  pollingInterval = setInterval(async () => {
    await pollUploadProgress();
  }, 1000);

  // Do initial poll immediately
  pollUploadProgress();
};

const stopPolling = () => {
  if (pollingInterval) {
    console.log('[Upload] Stopping polling');
    clearInterval(pollingInterval);
    pollingInterval = null;
  }
};

const pollUploadProgress = async () => {
  try {
    const response = await axios.get(`/api/jobs/${props.job.ident}/upload-progress`);
    const data = response.data;

    console.log('[Upload] Poll response:', data);

    // Update progress state for inline display
    uploadProgress.value = data;

    // Check for terminal states
    if (data.upload_state === 'upload_complete') {
      console.log('[Upload] Upload complete - stopping polling and reloading');
      stopPolling();
      uploadState.value = 'idle';
      router.reload();
    } else if (data.upload_state === 'upload_partial') {
      console.log('[Upload] Upload partial - stopping polling');
      stopPolling();
      uploadState.value = 'idle';

      // Show partial upload warning if errors exist
      if (data.processing_errors && data.processing_errors.length > 0) {
        const partialError = data.processing_errors.find(e => e.error_type === 'partial_upload');
        if (partialError) {
          showPartialUploadWarning(partialError);
        }
      }

      router.reload();
    } else if (data.upload_state === 'validation_failed') {
      console.log('[Upload] Validation failed - stopping polling');
      stopPolling();
      uploadState.value = 'idle';

      // Display validation errors
      if (data.processing_errors) {
        displayErrorCard(data.processing_errors);
      }
    } else if (!data.is_processing && data.has_document) {
      // Processing stopped but document exists - might be an error state
      console.log('[Upload] Processing stopped unexpectedly');
      stopPolling();
      uploadState.value = 'idle';
    }

  } catch (error) {
    console.error('[Upload] Polling error:', error);
    // Retry after 5 seconds on network error
    setTimeout(pollUploadProgress, 5000);
  }
};

// Load errors from backend
const loadDocumentErrors = async (documentId) => {
  try {
    console.log('[Upload] Loading errors for document:', documentId);
    const response = await axios.get(`/api/documents/${documentId}/errors`);

    if (response.data.errors && response.data.errors.length > 0) {
      displayErrorCard(response.data.errors);
      console.log('[Upload] Loaded', response.data.errors.length, 'errors from backend');
    }
  } catch (error) {
    console.error('[Upload] Failed to load errors:', error);
  }
};

onMounted(() => {
  // Check if job is currently processing - resume polling if needed
  if (props.job.is_processing) {
    console.log('[Upload] Job is processing - starting polling on mount');
    // Set upload progress for inline display (no modal - user can navigate away)
    uploadProgress.value = {
      is_processing: true,
      processed_submissions: 0,
      total_submissions: props.job.total_submissions || 0,
      progress_percent: 0
    };
    uploadState.value = 'uploading';
    startPolling();
  }

  // Load errors if document has validation errors or upload_state is validation_failed
  if (props.job.documents && props.job.documents.length > 0) {
    const document = props.job.documents[0];

    console.log('[Upload] Document found on mount:', {
      id: document.id,
      upload_state: document.upload_state,
      processing_errors: document.processing_errors
    });

    if (document.upload_state === 'validation_failed' ||
        (document.processing_errors && document.processing_errors.length > 0)) {
      console.log('[Upload] Document has validation errors - loading them');
      uploadedFilename.value = document.file_name;
      uploadedDocumentId.value = document.id;
      loadDocumentErrors(document.id);
    }
  } else {
    console.log('[Upload] No documents found on mount');
  }

  // Check if we should auto-trigger upload (from Jobs listing Upload Spreadsheet button)
  const autoTrigger = sessionStorage.getItem('autoTriggerUpload');
  if (autoTrigger === 'true') {
    sessionStorage.removeItem('autoTriggerUpload');
    // Wait for component to fully mount, then trigger file picker
    nextTick(() => {
      // Find and click the FileUpload input element
      const fileInput = document.querySelector('input[type="file"][accept=".xlsx"]');
      if (fileInput) {
        console.log('[Upload] Auto-triggering file picker from Upload Spreadsheet button');
        fileInput.click();
      }
    });
  }
});

onUnmounted(() => {
  // Clean up polling interval
  stopPolling();
});

// -----------------------------
// Handle file selection from custom upload button
// -----------------------------
const handleFileSelect = (event) => {
  const file = event.target.files[0];
  if (file) {
    // Call uploadFile with the file wrapped in the same format FileUpload used
    uploadFile({ files: [file] });
    // Reset the input so the same file can be selected again if needed
    event.target.value = '';
  }
};

// -----------------------------
// Single-step upload handler with background processing
// -----------------------------
const uploadFile = async (event) => {
  const file = event.files[0];
  uploadedFilename.value = file.name;

  // Clear any previous error card and warnings
  showErrorCard.value = false;
  uploadErrors.value = [];
  uploadWarnings.value = [];
  expandedErrorRows.value = {};

  console.log('[Upload] File selected:', file.name);

  // Show "Validating File" dialog
  validatingDialog.value.visible = true;
  validatingDialog.value.filename = file.name;
  validatingDialog.value.message = 'Please wait while we validate';
  uploadState.value = 'validating';

  // Start upload (validation + dispatch queue job)
  const formData = new FormData();
  formData.append('file', file);

  uploadAbortController = new AbortController();

  try {
    const response = await axios.post(`/api/documents/${props.job.ident}`, formData, {
      signal: uploadAbortController.signal
    });

    console.log('[Upload] Upload response:', response.data);

    // Close validating dialog
    validatingDialog.value.visible = false;

    // Check response status
    if (response.data.status_code === 200 || response.data.success === 'true') {
      // Validation passed and background job dispatched
      const rowCount = response.data.row_count || 0;

      console.log('[Upload] Validation passed - starting background processing for', rowCount, 'rows');

      // Set upload progress for inline display (no modal dialog - user can navigate away)
      uploadProgress.value = {
        is_processing: true,
        processed_submissions: 0,
        total_submissions: rowCount,
        progress_percent: 0
      };
      uploadState.value = 'uploading';

      // Reload the page to show the file with inline progress indicator
      // The polling will resume automatically on mount since job.is_processing will be true
      router.reload({ only: ['job'] });

      // Start polling for progress
      startPolling();

    } else {
      // Unexpected response
      console.error('[Upload] Unexpected response:', response.data);
      uploadState.value = 'idle';
    }

  } catch (error) {
    validatingDialog.value.visible = false;
    uploadState.value = 'idle';

    if (error.name === 'CanceledError') {
      console.log('[Upload] Upload cancelled by user');
    } else if (error.response && error.response.status === 422) {
      // Validation errors (422 response)
      console.log('[Upload] Validation errors found');

      const errorData = error.response.data;

      // Store document info for immediate display (no reload needed)
      if (errorData.document_id) {
        uploadedDocumentId.value = errorData.document_id;
        uploadedFileSize.value = file.size;
        uploadedFileDate.value = new Date();
        showUploadedFilePreview.value = true;
        console.log('[Upload] Stored file info for immediate display');
      }

      // Display validation errors
      if (errorData.errors && Array.isArray(errorData.errors)) {
        displayErrorCard(errorData.errors);
      }

      // Also handle warnings if present
      if (errorData.warnings && errorData.warnings.length > 0) {
        uploadWarnings.value = errorData.warnings;
      }

      if (!errorData.errors || !Array.isArray(errorData.errors)) {
        // Fallback error display
        displayErrorCard([{
          error_type: 'validation_error',
          severity: 'error',
          message: errorData.message || 'Validation failed',
          rows: ''
        }]);
      }

      // NO RELOAD - everything displays immediately
      console.log('[Upload] Errors and file info displayed immediately');
    } else {
      // Other errors
      console.error('[Upload] Upload error:', error);
      displayErrorCard([{
        error_type: 'upload_error',
        severity: 'error',
        message: error.response?.data?.message || error.message || 'Upload failed',
        rows: ''
      }]);
    }
  }
};

const cancelUpload = () => {
  console.log('[Upload] Cancel button clicked');
  if (uploadAbortController && uploadState.value === 'validating') {
    uploadAbortController.abort();
    validatingDialog.value.visible = false;
    uploadState.value = 'idle';
  }
};

// Show partial upload warning dialog
const showPartialUploadWarning = (error) => {
  partialUploadWarningDialog.value.error = error;
  partialUploadWarningDialog.value.visible = true;
};

// Clear document and submissions
const clearDocument = (event) => {
  console.log('[Upload] Clear document button clicked');

  // Prevent event bubbling
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }

  // Get document ID from either the uploaded document or the stored ID after validation error
  let documentId = null;
  let isPartialUpload = false;

  if (props.job.documents && props.job.documents.length > 0) {
    documentId = props.job.documents[0].id;
    isPartialUpload = props.job.documents[0].upload_state === 'upload_partial';
  } else if (uploadedDocumentId.value) {
    documentId = uploadedDocumentId.value;
  }

  console.log('[Upload] Document ID to clear:', documentId, 'isPartialUpload:', isPartialUpload);

  if (!documentId) {
    console.error('[Upload] No document ID found to clear');
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'No document found to clear',
      life: 3000
    });
    return;
  }

  // Different confirmation message and endpoint for partial uploads vs validation errors
  const confirmMessage = isPartialUpload
    ? 'Are you sure you want to remove this file? The submissions that were partially uploaded from this file will also be deleted.'
    : 'Are you sure you want to remove this file? Note: Submissions will NOT be deleted and must be managed separately.';

  // Show PrimeVue confirmation dialog
  confirm.require({
    group: 'confirmclear',
    header: 'Remove File',
    message: confirmMessage,
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: 'Remove File',
    rejectLabel: 'Cancel',
    accept: async () => {
      try {
        console.log('[Upload] Deleting document:', documentId, 'isPartialUpload:', isPartialUpload);

        // Use different endpoint for partial uploads to also delete submissions
        if (isPartialUpload) {
          await axios.delete(`/api/documents/${documentId}/clear-valid`);
        } else {
          await axios.delete(`/api/documents/${documentId}`);
        }

        console.log('[Upload] Document deleted successfully');

        // Clear local state
        showErrorCard.value = false;
        uploadErrors.value = [];
        uploadedDocumentId.value = null;
        uploadedFilename.value = '';
        uploadedFileSize.value = 0;
        uploadedFileDate.value = null;
        showUploadedFilePreview.value = false;

        // Reload page to show updated state
        router.reload();

      } catch (error) {
        console.error('[Upload] Clear document error:', error);
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: error.response?.data?.message || 'Failed to clear file',
          life: 5000
        });
      }
    }
  });
};

const clearValidDocument = (event) => {
  console.log('[Upload] Clear valid document button clicked');

  // Prevent event bubbling
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }

  // Get document ID from the uploaded document
  let documentId = null;

  if (props.job.documents && props.job.documents.length > 0) {
    documentId = props.job.documents[0].id;
  }

  console.log('[Upload] Valid document ID to clear:', documentId);

  if (!documentId) {
    console.error('[Upload] No document ID found to clear');
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'No document found to clear',
      life: 3000
    });
    return;
  }

  // Show PrimeVue confirmation dialog
  confirm.require({
    group: 'confirmclearvalid',
    header: 'Remove File and Handle Submissions',
    message: 'Are you sure you want to remove this file?\n\nThis will delete all draft submissions created from this upload.',
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: 'Remove File',
    rejectLabel: 'Cancel',
    accept: async () => {
      try {
        console.log('[Upload] Deleting valid document:', documentId);

        const response = await axios.delete(`/api/documents/${documentId}/clear-valid`);

        console.log('[Upload] Valid document deleted successfully', response.data);

        // Reload page to show updated state
        router.reload();

      } catch (error) {
        console.error('[Upload] Clear valid document error:', error);
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: error.response?.data?.message || 'Failed to clear file',
          life: 5000
        });
      }
    }
  });
};

// Helper function to format file size
const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

// Helper function to format date
const formatDate = (dateString) => {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
  if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
  if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;

  return date.toLocaleDateString();
};
</script>


<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <!-- header - V2 Status Banners -->
            <!-- Upload processing banner - job is read-only while processing -->
            <div v-if="uploadProgress.is_processing"
                 class="bg-yellow-100 border-l-4 border-yellow-700 text-yellow-800 p-4 mb-2" role="alert">
                <p class="font-bold flex items-center gap-2">
                    <i class="pi pi-spin pi-spinner"></i>
                    Uploading submissions in background ({{ uploadProgress.processed_submissions || 0 }} of {{ uploadProgress.total_submissions || 0 }}) - You can navigate away, the job will continue uploading.
                </p>
            </div>
            <!-- Draft status: show submit button if no errors, not processing, and not partial upload -->
            <div v-else-if="job.status === 'draft' && job.type == 0 && submissions.length > 0 && !hasErrors && !hasPartialUpload && !job.is_processing"
                 class="bg-yellow-100 border-l-4 border-yellow-700 text-yellow-800 p-4 mb-2" role="alert">
                <p class="font-bold">There are no errors.
                <Button class="float-right !bg-amber-600 !ring-amber-600 hover:!bg-amber-700" label="Submit" icon="pi pi-send" @click="publishConfirmation()" raised /></p>
            </div>

            <!-- Submitted status: show submitted message, no unstage button -->
            <div v-if="job.status === 'submitted'" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mb-2" role="alert">
                <p class="font-bold">Job has been submitted and will be processed automatically.</p>
            </div>

            <!-- Released status -->
            <div v-if="job.status === 'released' || job.status === 'processed'" class="bg-green-100 border-l-4 border-green-700 text-green-800 p-4 mb-2" role="alert">
                <p class="font-bold">Job has been released.</p>
            </div>

            <!-- Legacy status banners (for backwards compatibility) -->
            <div v-if="!job.status && (job.status == 2 && job.type == 0) && !uploadProgress.is_processing" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mb-2" role="alert">
                <p class="font-bold">There are no errors.
                <Button v-if="submissions.length > 0" class="float-right !bg-amber-600 !ring-amber-600 hover:!bg-amber-700" label="Submit" @click="publishConfirmation()" raised /></p>
            </div>
            <div v-if="!job.status && (job.status == 5 && job.type == 0)" class="bg-slate-100 border-l-4 border-slate-700 text-slate-800 p-4 mb-2" role="alert">
                <p class="font-bold">Job is staged for Publication.
                <Button class="float-right" label="Unstage" @click="unpublishConfirmation()" severity="secondary" raised /></p>
            </div>
            <div v-if="!job.status && (job.status == 3)" class="bg-slate-100 border-l-4 border-slate-700 text-slate-800 p-4 mb-2" role="alert">
                <p class="font-bold">Job has been Completed.</p>
            </div>

            <!-- Error banner (applies to both V2 and legacy) -->
            <div v-if="hasErrors" class="bg-amber-100 border-l-4 border-amber-700 text-amber-800 p-4 mt-2" role="alert">
                <p class="font-bold">There are submission errors present</p>
            </div>
            
            <ConfirmDialog group="confirmsub">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-amber-600 dark:bg-amber-500 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-question text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button label="Add A Submission" @click="acceptCallback" class="!bg-amber-600 !ring-amber-600 hover:!bg-amber-700"></Button>
                            <Button label="Cancel" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <!-- Validating Dialog -->
            <Dialog v-if="validatingDialog.visible"
                    :visible="true"
                    modal
                    :closable="false"
                    header="Validating File"
                    :style="{ width: '500px' }"
                    data-validating-dialog>
              <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                  <!-- Pure CSS spinner that won't stop on re-render -->
                  <div class="spinner" style="width: 30px; height: 30px; border: 3px solid #f3f3f3; border-top: 3px solid #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                  <p class="mb-0">{{ validatingDialog.message }} <strong>{{ validatingDialog.filename }}</strong>...</p>
                </div>
              </div>
              <template #footer>
                <Button label="Cancel"
                        @click="cancelUpload"
                        severity="secondary" />
              </template>
            </Dialog>

            <!-- Uploading Dialog - REMOVED: Now using inline progress display in file area -->
            <!-- User can navigate away during background processing -->

            <!-- Partial Upload Warning Dialog -->
            <Dialog v-model:visible="partialUploadWarningDialog.visible"
                    modal
                    :header="partialUploadWarningDialog.error?.severity === 'error' ? 'Upload Failed - Partial Results' : 'Partial Upload Warning'"
                    :style="{ width: '600px' }">
              <template v-if="partialUploadWarningDialog.error">
                <div :class="['p-4 rounded mb-4 border-l-4',
                              partialUploadWarningDialog.error.severity === 'error' ? 'bg-red-50 border-red-500' : 'bg-amber-50 border-amber-500']">
                  <div class="flex items-start gap-3">
                    <i :class="['pi text-2xl mt-1',
                                partialUploadWarningDialog.error.severity === 'error' ? 'pi-times-circle text-red-600' : 'pi-exclamation-triangle text-orange-600']"></i>
                    <div class="flex-1">
                      <div class="text-sm mb-3" :class="partialUploadWarningDialog.error.severity === 'error' ? 'text-red-800' : 'text-orange-800'">
                        {{ partialUploadWarningDialog.error.message }}
                      </div>
                      <div class="grid grid-cols-2 gap-3 text-sm" :class="partialUploadWarningDialog.error.severity === 'error' ? 'text-red-700' : 'text-orange-700'">
                        <div>
                          <span class="font-semibold">Processed:</span> {{ partialUploadWarningDialog.error.processed_rows }} submissions
                        </div>
                        <div>
                          <span class="font-semibold">Total:</span> {{ partialUploadWarningDialog.error.total_rows }} submissions
                        </div>
                        <div v-if="partialUploadWarningDialog.error.elapsed_seconds">
                          <span class="font-semibold">Duration:</span> {{ partialUploadWarningDialog.error.elapsed_seconds }}s
                        </div>
                        <div>
                          <span class="font-semibold">Remaining:</span> {{ partialUploadWarningDialog.error.total_rows - partialUploadWarningDialog.error.processed_rows }} submissions
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
              <template #footer>
                <Button label="Close" @click="partialUploadWarningDialog.visible = false" severity="secondary" />
              </template>
            </Dialog>

            <ConfirmDialog group="confirmunpub">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-red-700 dark:bg-red-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-question text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button label="Unstage Job" @click="acceptCallback" class="!bg-red-700 !ring-red-700 hover:!bg-red-800"></Button>
                            <Button label="Cancel" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <ConfirmDialog group="confirmpub">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-amber-600 dark:bg-amber-500 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-question text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button :label="message.acceptLabel || 'Submit'" @click="acceptCallback" class="!bg-amber-600 !ring-amber-600 hover:!bg-amber-700"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <ConfirmDialog group="confirmclear">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-red-700 dark:bg-red-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-exclamation-triangle text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0 text-center">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button :label="message.acceptLabel || 'Remove'" @click="acceptCallback" severity="danger"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <ConfirmDialog group="confirmclearvalid">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <div class="rounded-full bg-red-700 dark:bg-red-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-exclamation-triangle text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0 text-center whitespace-pre-line">{{ message.message }}</p>
                        <div class="flex items-center gap-8 mt-4">
                            <Button :label="message.acceptLabel || 'Remove'" @click="acceptCallback" severity="danger"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <Toast />

            <!-- entries -->
            <div class="grid grid-cols-12 mt-4 gap-0">
                <div class="col-span-12">
                    <div class="grid grid-cols-12 gap-0">

                        <div class="col-span-2 pt-3 text-right pr-3">Submitter:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal font-bold">{{ job.submitter.name }}</div>
                            <div class="text-xs">{{ job.submitter.curie }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Submitter by:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal font-bold">{{ job.user.name }}</div>
                            <div class="text-xs">{{ job.user.email }}</div>
                        </div>
                        <div class="col-span-2 py-1 my-2 mr-5">
                            <div v-show="job.status === 'draft' || (!job.status && (job.status == 2 || job.status == 4))" class="text-left"><Button label="New" @click="requireConfirmation()" icon="pi pi-plus" class="w-28 !bg-amber-600 !ring-amber-600 hover:!bg-amber-700" /></div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Status Date:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <div class="">{{ formatDateTimestamp(getStatusDate(job)) }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Status:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <Tag v-if="job.status" :value="displayStatusV2(job.status)" :severity="getStatusSeverity(job.status)" :class="['status-tag', getJobStatusClass(job.status)]" />
                            <span v-else>{{ displayStatus }}</span>
                        </div>
                        <div class="col-span-2 py-1 my-2 mr-5">
                            <!-- Only show upload button if job is draft AND has no documents AND no preview -->
                            <!-- Using custom Button + hidden input since FileUpload doesn't support color customization -->
                            <div v-show="(job.status === 'draft' || (!job.status && (job.status == 2 || job.status == 4))) && job.documents_count === 0 && !showUploadedFilePreview">
                                <input type="file" ref="fileInput" accept=".xlsx" @change="handleFileSelect" class="hidden" />
                                <Button label="Upload" icon="pi pi-upload" class="w-28 !bg-amber-600 !ring-amber-600 hover:!bg-amber-700" @click="$refs.fileInput.click()" />
                            </div>
                        </div>

                        <!-- Show uploaded file preview when validation fails (before reload) -->
                        <template v-if="showUploadedFilePreview && !job.documents.length">
                            <div class="col-span-2 pt-3 text-right pr-3">Uploaded File:</div>
                            <div class="col-span-5 py-1 my-2 border-l-8 pl-3 border-red-500">
                                <div class="flex items-center gap-2">
                                    <a v-if="uploadedDocumentId"
                                       :href="'/api/documents/' + uploadedDocumentId + '/download'"
                                       class="text-blue-600 hover:text-blue-800 hover:underline flex items-center gap-2">
                                        <i class="pi pi-download text-sm"></i>
                                        <span>{{ uploadedFilename }}</span>
                                    </a>
                                    <span v-else class="text-gray-700">{{ uploadedFilename }}</span>
                                    <!-- Error indicator badge -->
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-800 border border-red-300"
                                          title="File has validation errors">
                                        <i class="pi pi-exclamation-circle text-xs"></i>
                                        Invalid
                                    </span>
                                    <!-- Clear File icon button immediately adjacent to Invalid badge -->
                                    <button @click="clearDocument"
                                            class="p-1.5 rounded-full text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors cursor-pointer"
                                            type="button"
                                            title="Remove this file (submissions will be preserved)">
                                        <i class="pi pi-trash text-sm"></i>
                                    </button>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ formatFileSize(uploadedFileSize) }} • Uploaded {{ formatDate(uploadedFileDate) }}
                                </div>
                            </div>
                        </template>

                        <!-- Show uploaded filename as download link if a document exists -->
                        <template v-if="job.documents && job.documents.length > 0">
                            <div class="col-span-2 pt-3 text-right pr-3">Uploaded File:</div>
                            <div class="col-span-5 py-1 my-2 border-l-8 pl-3" :class="{'border-red-500': job.status === 'draft' && hasValidationErrors, 'border-orange-400': job.status === 'draft' && hasPartialUpload && !hasValidationErrors, 'border-yellow-400': job.status === 'draft' && uploadProgress.is_processing && !hasValidationErrors && !hasPartialUpload, 'border-green-500': job.status === 'released' || job.status === 'processed'}">
                                <div class="flex items-center gap-2">
                                    <a :href="'/api/documents/' + job.documents[0].ident + '/download'" class="text-blue-600 hover:text-blue-800 hover:underline flex items-center gap-2">
                                        <i class="pi pi-download text-sm"></i>
                                        <span>{{ job.documents[0].file_name }}</span>
                                    </a>
                                    <!-- Draft-only badges and buttons -->
                                    <template v-if="job.status === 'draft'">
                                        <!-- Uploading indicator badge with progress -->
                                        <span v-if="uploadProgress.is_processing"
                                              class="inline-flex items-center gap-2 px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-800 border border-yellow-300"
                                              title="Uploading submissions in background - you can navigate away">
                                            <i class="pi pi-spin pi-spinner text-xs"></i>
                                            Uploading {{ uploadProgress.processed_submissions || 0 }} of {{ uploadProgress.total_submissions || 0 }}
                                        </span>
                                        <!-- Error indicator badge - ONLY for validation errors, NOT partial uploads -->
                                        <span v-else-if="hasValidationErrors"
                                              class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-800 border border-red-300"
                                              title="File has validation errors">
                                            <i class="pi pi-exclamation-circle text-xs"></i>
                                            Invalid
                                        </span>
                                        <!-- Partial upload badge - NOT a validation error, just incomplete -->
                                        <span v-else-if="hasPartialUpload"
                                              class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-orange-100 text-orange-800 border border-orange-300"
                                              title="Upload partially completed - some submissions were processed">
                                            <i class="pi pi-exclamation-triangle text-xs"></i>
                                            Partial
                                        </span>
                                        <!-- Clear File icon button for failed/partial uploads (validation errors or partial upload) -->
                                        <button v-if="canClearDocument"
                                                @click.stop.prevent="clearDocument"
                                                :class="['p-1.5 rounded-full transition-colors cursor-pointer',
                                                         hasValidationErrors ? 'text-red-600 hover:bg-red-50 hover:text-red-700' : 'text-orange-600 hover:bg-orange-50 hover:text-orange-700']"
                                                type="button"
                                                :title="hasValidationErrors ? 'Remove this file (submissions will be preserved)' : 'Remove this file and its partial submissions'">
                                            <i class="pi pi-trash text-sm"></i>
                                        </button>
                                        <!-- Clear File icon button for valid/complete files -->
                                        <button v-else-if="!uploadProgress.is_processing && !canClearDocument"
                                                @click.stop.prevent="clearValidDocument"
                                                class="p-1.5 rounded-full text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors cursor-pointer"
                                                type="button"
                                                title="Remove this file and delete all draft submissions">
                                            <i class="pi pi-trash text-sm"></i>
                                        </button>
                                    </template>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ formatFileSize(job.documents[0].size) }} • Uploaded {{ formatDate(job.documents[0].created_at) }}
                                </div>

                                <!-- Draft-only: Inline progress bar during upload -->
                                <div v-if="job.status === 'draft' && uploadProgress.is_processing" class="mt-2">
                                    <div class="flex items-center gap-2 text-xs text-blue-700 mb-1">
                                        <span>{{ Math.round(uploadProgress.progress_percent || 0) }}% complete</span>
                                        <span class="text-gray-400">•</span>
                                        <span class="text-gray-500">You can navigate away - uploading will continue in background</span>
                                    </div>
                                    <ProgressBar :value="uploadProgress.progress_percent || 0" :showValue="false" class="h-2" />
                                </div>

                                <!-- Draft-only: Show compact partial upload badge if present -->
                                <template v-if="job.status === 'draft' && job.documents[0].processing_errors && job.documents[0].processing_errors.length > 0">
                                    <div v-for="(error, index) in job.documents[0].processing_errors" :key="index">
                                        <div v-if="error.error_type === 'partial_upload'" class="mt-2">
                                            <button
                                                @click="showPartialUploadWarning(error)"
                                                :class="['inline-flex items-center gap-2 px-3 py-1.5 rounded text-sm font-medium cursor-pointer hover:opacity-80 transition-opacity',
                                                         error.severity === 'error' ? 'bg-red-100 text-red-800 border border-red-300' : 'bg-orange-100 text-orange-800 border border-orange-300']">
                                                <i :class="['pi text-sm', error.severity === 'error' ? 'pi-times-circle' : 'pi-exclamation-triangle']"></i>
                                                <span>{{ error.severity === 'error' ? 'Upload Failed' : 'Partial Upload' }}: {{ error.processed_rows }} of {{ error.total_rows }} processed</span>
                                                <i class="pi pi-info-circle text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                    </div>
                </div>
            </div>

            <!-- File Format Error Display - Clean, user-friendly message for template/format issues -->
            <div v-if="fileFormatErrors.length > 0" class="mt-4">
                <div v-for="(error, index) in fileFormatErrors" :key="'format-' + index"
                     class="bg-red-50 border-2 border-red-400 rounded-lg p-6 mb-4">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0">
                            <i class="pi pi-file-excel text-4xl text-red-500"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-red-800 mb-2">
                                {{ error.user_title || 'Invalid File Format' }}
                            </h3>
                            <p class="text-red-700 mb-4">
                                {{ getFileFormatUserMessage(error) }}
                            </p>
                            <div class="flex flex-wrap gap-3">
                                <a href="/download/template"
                                   class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                                    <i class="pi pi-download"></i>
                                    Download GenCC Template
                                </a>
                                <a href="/submission-directions"
                                   target="_blank"
                                   class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
                                    <i class="pi pi-book"></i>
                                    View Submission Guide
                                </a>
                            </div>
                            <!-- Technical details (collapsed by default) - only show if different from user message -->
                            <details class="mt-4 text-sm text-gray-600">
                                <summary class="cursor-pointer hover:text-gray-800">Technical Details</summary>
                                <div class="mt-2 p-3 bg-gray-100 rounded font-mono text-xs whitespace-pre-wrap">{{ error.message }}</div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Row Error Display Card - For row-level validation errors -->
            <Card v-if="dataRowErrors.length > 0"
                  class="mt-4 border-2 border-red-500"
                  :class="{'collapsed-card': !showErrorCard}"
                  :pt="{
                      root: { class: 'p-0' },
                      body: { class: 'p-0' },
                      content: { class: !showErrorCard ? 'hidden' : 'p-3' },
                      title: { class: 'p-3' },
                      header: { class: 'p-0' }
                  }">
                <template #title>
                    <div class="flex justify-between items-center text-red-700">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-exclamation-triangle text-2xl"></i>
                            <div class="flex flex-col">
                                <span>{{ dataRowErrors.length }} Data Validation Error(s){{ uploadedFilename ? ` - ${uploadedFilename}` : '' }}</span>
                                <span v-if="MAX_VALIDATION_RESULTS > 0 && dataRowErrors.length === MAX_VALIDATION_RESULTS" class="text-sm font-semibold">Maximum errors reached</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button label="Download"
                                    icon="pi pi-download"
                                    size="small"
                                    @click="downloadErrors"
                                    class="w-28"
                                    raised />
                            <Button :icon="showErrorCard ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                                    :label="showErrorCard ? 'Hide' : 'Show'"
                                    severity="secondary"
                                    size="small"
                                    @click="showErrorCard = !showErrorCard"
                                    class="w-28" />
                        </div>
                    </div>
                </template>
                <template #content>
                    <div v-show="showErrorCard" class="error-content">
                        <div class="mb-4 p-3 bg-yellow-100 border-l-4 border-yellow-500 rounded-lg">
                            <p class="text-sm text-yellow-700">
                                <i class="pi pi-info-circle mr-2"></i>
                                Please review and correct the errors below. For detailed guidance on submission requirements, refer to
                                <a href="https://thegencc.org/submission-directions"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="text-yellow-700 hover:text-yellow-900 underline font-medium">
                                    GenCC Submission Directions
                                    <i class="pi pi-external-link text-xs ml-1"></i>
                                </a>.
                            </p>
                        </div>
                        <DataTable :value="dataRowErrors"
                                   size="small"
                                   stripedRows
                                   scrollable
                                   scrollHeight="400px"
                                   class="text-sm">
                            <Column field="error_type" header="Error Type" :style="{width: '180px'}">
                                <template #body="slotProps">
                                    <span class="font-mono text-xs">{{ slotProps.data.error_type || 'validation_error' }}</span>
                                </template>
                            </Column>
                            <Column field="severity" header="Severity" :style="{width: '100px'}">
                                <template #body="slotProps">
                                    <Tag :severity="slotProps.data.severity === 'fatal' ? 'danger' : (slotProps.data.severity === 'warning' ? 'warn' : 'danger')"
                                         :value="slotProps.data.severity || 'error'" />
                                </template>
                            </Column>
                            <Column field="message" header="Error Message">
                                <template #body="slotProps">
                                    <div class="whitespace-pre-wrap">{{ slotProps.data.message }}</div>
                                </template>
                            </Column>
                            <Column field="rows" header="Rows" :style="{width: '200px'}">
                                <template #body="slotProps">
                                    <div class="font-mono text-xs">
                                        <span>{{ formatRowsForDisplay(slotProps.data.rows, slotProps.index).text }}</span>
                                        <button
                                            v-if="formatRowsForDisplay(slotProps.data.rows, slotProps.index).hasMore"
                                            @click="toggleRowExpansion(slotProps.index)"
                                            class="ml-2 text-blue-600 hover:text-blue-800 underline cursor-pointer text-xs"
                                        >
                                            <template v-if="formatRowsForDisplay(slotProps.data.rows, slotProps.index).isExpanded">
                                                show less
                                            </template>
                                            <template v-else>
                                                (+{{ formatRowsForDisplay(slotProps.data.rows, slotProps.index).remaining }} more)
                                            </template>
                                        </button>
                                    </div>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </template>
            </Card>

            <!-- PMID Normalization Warnings Card -->
            <div v-if="pmidWarnings.length > 0" class="mt-2 mb-4">
                <div class="bg-orange-50 border-l-4 border-orange-400 p-4 rounded">
                    <div class="flex justify-between items-center text-orange-700">
                        <div class="flex items-center gap-2">
                            <i class="pi pi-info-circle text-2xl"></i>
                            <div class="flex flex-col">
                                <span class="font-semibold">{{ pmidWarnings.length }} PMID Normalization Warning(s)</span>
                                <span class="text-sm">Some PMID values were automatically cleaned. Upload will proceed normally.</span>
                            </div>
                        </div>
                        <Button label="Dismiss" icon="pi pi-times" severity="warning" text size="small" @click="uploadWarnings = []" />
                    </div>
                    <div class="mt-3 max-h-40 overflow-y-auto">
                        <div v-for="(warning, index) in pmidWarnings" :key="index" class="text-sm text-orange-600 py-1 border-b border-orange-200 last:border-b-0">
                            <span class="font-mono">Row {{ warning.rows }}:</span> {{ warning.message }}
                        </div>
                    </div>
                </div>
            </div>

            <SubmissionsListing :submissions="submissions" :errors="errors" :favorites="favorites" :hasSubmittedJob="hasSubmittedJob" :jobStatus="job?.status" />
        </div>

    </div>
</template>

<style scoped>
/* Job Status Colors */
.job-status-draft {
    background-color: #FEF08A !important;  /* Tailwind yellow-200 */
    color: #713F12 !important;             /* Tailwind yellow-950 */
    border-color: #FACC15 !important;      /* Tailwind yellow-400 */
}

.job-status-submitted {
    background-color: #BFDBFE !important;  /* Tailwind blue-200 */
    color: #1E3A8A !important;             /* Tailwind blue-900 */
    border-color: #60A5FA !important;      /* Tailwind blue-400 */
}

.job-status-processed {
    background-color: #86EFAC !important;  /* Tailwind green-300 */
    color: #14532D !important;             /* Tailwind green-950 */
    border-color: #4ADE80 !important;      /* Tailwind green-400 */
}

.status-tag {
    min-width: 100px;
    text-align: center;
    line-height: 1.2;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.spinner {
    flex-shrink: 0;
}

/* Reduce whitespace when error card is collapsed */
.collapsed-card :deep(.p-card-body) {
    padding: 0 !important;
    margin: 0 !important;
}

.collapsed-card :deep(.p-card-content) {
    padding: 0 !important;
    margin: 0 !important;
    display: none !important; /* Completely hide content area when collapsed */
}

.collapsed-card :deep(.p-card-title) {
    padding: 0.75rem !important;
    margin: 0 !important;
}

.collapsed-card :deep(.p-card-header) {
    padding: 0 !important;
    margin: 0 !important;
}
</style>
