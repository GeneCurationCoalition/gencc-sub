<script setup>

    import { ref, computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import InputDialog from './InputDialog.vue';
    import ChangeInheritance from './ChangeInheritance.vue';
    import ChangeClassification from './ChangeClassification.vue';
    import ChangeGene from './ChangeGene.vue';
    import ChangeDisease from './ChangeDisease.vue';
    import { getDiseaseUrl, getGeneUrl } from '@/utils/externalLinks';
    import ChangeContributor from './ChangeContributor.vue';
    import ChangeNotes from './ChangeNotes.vue';
    import ChangeEvidence from './ChangeEvidence.vue';
    import ChangeReport from './ChangeReport.vue';
    import ChangeCriteria from './ChangeCriteria.vue';
    import ChangeVersion from './ChangeVersion.vue';
    import ChangeMechanism from './ChangeMechanism.vue';
    import ChangeLocalKey from './ChangeLocalKey.vue';
    import MarkdownDisplay from './MarkdownDisplay.vue';
    import ConfirmDialog from 'primevue/confirmdialog';
    import Tag from 'primevue/tag';
    import { useConfirm } from "primevue/useconfirm";
    import { useToast } from "primevue/usetoast";


    const props = defineProps(['submission', 'criteria_options', 'hasSubmittedJob', 'unpublishedDuplicateWarning'])

    const confirm = useConfirm();
    const toast = useToast();

    // Check if submission is editable
    // Read-only if:
    // - Published (status='published')
    // - Draft unpublish (status='draft_unpublish') - user must delete draft to keep published version
    // - Legacy: published (status=20) OR job not in processing/error status
    const isNotEditable = computed(() => {
        // V2 status checks
        if (props.submission.status) {
            return props.submission.status === 'published' ||
                   props.submission.status === 'draft_unpublish' ||
                   props.submission.status === 'submitted_republish' ||
                   props.submission.status === 'submitted_unpublish' ||
                   props.submission.status === 'unpublished';
        }

        // Legacy status checks (fallback)
        return props.submission.status === 20 || ![2, 4].includes(props.submission.job?.status);
    });

    // Gene field has additional restrictions: cannot be edited for republished submissions
    const isGeneNotEditable = computed(() => {
        // Apply all standard editability rules first
        if (isNotEditable.value) {
            return true;
        }

        // Additionally, block gene editing for draft_republish and submitted_republish
        if (props.submission.status) {
            return props.submission.status === 'draft_republish' ||
                   props.submission.status === 'submitted_republish';
        }

        return false;
    });

    // Check if this is an archived version (superseded by a newer release)
    // Archived versions are read-only and should have gray styling
    // Uses is_archived computed attribute from backend (released status + is_live=false)
    const isArchivedVersion = computed(() => {
        return props.submission.is_archived === true;
    });

    // Check if this submission has a pending draft version
    // This is true when is_live=true but is_most_recent=false
    // (meaning there's a newer draft version waiting to be released)
    const hasPendingDraftVersion = computed(() => {
        return props.submission.is_live === true && props.submission.is_most_recent === false;
    });

    // set up some models for the shared dialog component
    const showDialog = defineModel('showDialig');
    const dialogTitle = defineModel('dialogTitle');
    const dialogLabel = defineModel('dialogLabel');
    const dialogInput = defineModel('dialogInput');
    const dialogInput2 = defineModel('dialogInput2');

    // Make localMechanism reactive to props changes
    const localMechanism = computed(() => {
        if (props.submission.submission_data?.mechanism == undefined) {
            return { id: '', comments: '', name: ''};
        } else {
            return props.submission.submission_data.mechanism;
        }
    });

    const entryName = ref("");
    const entryText = ref("");
    const entryDescription = ref("");

    // visibility flags for the various modals
    const showSelectDialog = ref(false);
    const showClassificationDialog = ref(false);
    const showDateDialog = ref(false);
    const showGeneDialog = ref(false);
    const showDiseaseDialog = ref(false);
    const showNotesDialog = ref(false);
    const showEvidenceDialog = ref(false);
    const showReportDialog = ref(false);
    const showCriteriaDialog = ref(false);
    const showContributorDialog = ref(false);
    const showVersionDialog = ref(false);
    const showMechanismDialog = ref(false);
    const showLocalKeyDialog = ref(false);

    // Error state for local key updates
    const localKeyError = ref('');

    // Error state for duplicate submission errors in dialogs
    const duplicateError = ref('');

    // Warning state for unpublished duplicate warnings - initialized from server prop
    const unpublishedDuplicateWarning = ref(props.unpublishedDuplicateWarning);

    const axios = window.axios;

    /**
     * Handle API response for potential duplicate errors/warnings
     * @param {Object} response - Axios response object
     * @returns {boolean} - true if update should proceed (success), false if blocked
     */
    function handleDuplicateResponse(response) {
        // Check for duplicate blocking error (status_code 3013)
        if (response.data.status_code === 3013) {
            // Set the error state to display in the dialog
            duplicateError.value = response.data.message || 'A submission with this gene, disease, and mode of inheritance already exists.';
            return false;
        }

        // Clear any previous duplicate error on success
        duplicateError.value = '';

        // Check for unpublished duplicate warning
        if (response.data.warnings && response.data.warnings.length > 0) {
            const duplicateWarning = response.data.warnings.find(w => w.type === 'unpublished_duplicate');
            if (duplicateWarning) {
                // Store warning for persistent display
                unpublishedDuplicateWarning.value = duplicateWarning;

                // Show toast notification for warning (this is informational, not blocking)
                toast.add({
                    severity: 'warn',
                    summary: 'Unpublished Duplicate Exists',
                    detail: duplicateWarning.message,
                    life: 8000
                });
            }
        }

        return true;
    }

    /**
     * Clear duplicate error when opening a dialog
     */
    function clearDuplicateError() {
        duplicateError.value = '';
    }

    // check if an opject is emptu
    function isEmpty(obj) {
        for (const prop in obj) {
            if (Object.hasOwn(obj, prop)) {
                return false;
            }
        }
        return true;
    }

    // check if a property exists in the errorbag
    function hasProperty(type) {
        if (props.submission.submission_errors == null)
            return false;

        return props.submission.submission_errors.hasOwnProperty(type);
    }

    // check if there are any errors
    function hasAnyErrors() {
        if (!props.submission.submission_errors) {
            return false;
        }

        // Check if it's an empty object or all values are empty/null
        for (const prop in props.submission.submission_errors) {
            if (Object.hasOwn(props.submission.submission_errors, prop)) {
                const value = props.submission.submission_errors[prop];
                if (value && value.trim && value.trim() !== '') {
                    return true;
                } else if (value && !value.trim) {
                    return true;
                }
            }
        }
        return false;
    }

    const JOB_STATUS_PROCESSING = 2;
    const JOB_STATUS_ERROR = 4;

    function jobHasStatusProcessingOrError() {
       // V2 state model: Only show edit buttons for editable states
       // Editable states: draft_new, draft_republish
       // Read-only states (buttons hidden): published, draft_unpublish, submitted_*, unpublished
       if (props.submission?.status) {
           return props.submission.status === 'draft_new' ||
                  props.submission.status === 'draft_republish';
       }

       // Legacy: Show buttons if job is in processing or error status
       return props.submission?.job?.status === JOB_STATUS_PROCESSING ||
           props.submission?.job?.status === JOB_STATUS_ERROR;
    }


    // open the dialog modal
    function editField(type) {
       showDialog.value = true;
    }

    // configure and display the inputdialog component
    function openDialog(type) {

        // Clear any previous duplicate error when opening a dialog
        clearDuplicateError();

        if (type == 'gene_hgnc_id')
        {
            dialogTitle.value = 'Enter Gene HGNC ID';
            dialogLabel.value = 'Gene HGNC ID';
            showGeneDialog.value = true;
        }
        else if (type == 'moi_curie_id')
        {
            dialogTitle.value = 'Edit the Mode of Inheritance';
            dialogLabel.value = 'Inheritance';
            showSelectDialog.value = true;
        }
        else if (type == 'classification_curie_id')
        {
            dialogTitle.value = 'Edit the Classification';
            dialogLabel.value = 'Classification';
            showClassificationDialog.value = true;
        }
        else if (type == 'publish_date')
        {
            dialogTitle.value = 'Edit the Publish Date';
            dialogLabel.value = 'Date';
            showDateDialog.value = true;
        }
        else if (type == 'disease_curie_id')
        {
            dialogTitle.value = 'Edit the Disease';
            dialogLabel.value = 'Disease ID';
            showDiseaseDialog.value = true;
        }
        else if (type == 'notes')
        {
            dialogTitle.value = 'Edit the Notes';
            showNotesDialog.value = true;
        }
        else if (type == 'evidence')
        {
            dialogTitle.value = 'Edit the Evidence';
            dialogLabel.value = 'PMIDs';
            showEvidenceDialog.value = true;
        }
        else if (type == 'report')
        {
            dialogTitle.value = 'Edit the Report';
            dialogLabel.value = 'URL';
            showReportDialog.value = true;
        }
        else if (type == 'criteria')
        {
            dialogTitle.value = 'Edit the Criteria';
            dialogLabel.value = 'URL';
            showCriteriaDialog.value = true;
        }
        else if (type == 'contributor')
        {
            dialogTitle.value = 'Edit the Primary Contributor';
            dialogLabel.value = 'Primary';
            showContributorDialog.value = true;
        }
        else if (type == 'version')
        {
            dialogTitle.value = 'Edit the Version Information';
            dialogLabel.value = '';
            showVersionDialog.value = true;
        }
        else if (type == 'mech_of_disease')
        {
            dialogTitle.value = 'Edit the Mechanism of Disease';
            dialogLabel.value = 'Mechanism of Disease';
            showMechanismDialog.value = true;
        }
        else if (type == 'local_key')
        {
            dialogTitle.value = 'Edit Local Key';
            dialogLabel.value = 'Local Key';
            // Clear any previous errors when opening the dialog
            localKeyError.value = '';
            showLocalKeyDialog.value = true;
        }
    }

    // query the server for the entry given
    async function checkEntry() {
        try {
            const response = await axios.get('/api/lookup/disease/' + entryText.value);

            const nodata = response.data.hasOwnProperty('status_code');

            if ( !nodata )
            {
                //console.log('updating ' + response.data.name);
                entryName.value = response.data.name;
                entryDescription.value = response.data.description;
            }

        } catch (error) {
             console.error(error);
        }
    }

    async function updateInheritance(value) {
        //console.log(value);
        if (value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'inheritance',
                    curie: value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                // Check for duplicate errors/warnings - dialog stays open on error
                if (!handleDuplicateResponse(response)) {
                    return; // Blocked by duplicate error - dialog stays open to show error
                }

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // Close the dialog FIRST (on success only)
                    showSelectDialog.value = false;

                    // Then reload the server data
                    router.reload();
                }
            } catch (error) {
                console.error(error);
            }
        }
    }


    async function updateClassification(value) {

        if (value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'classification',
                    curie: value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // reload the server data
                    router.reload();

                    // close the dialog
                    showSelectDialog.value = false;
                }
            } catch (error) {
                console.error(error);
            }
        }
    }

    async function updateLocalKey(value) {
        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'local_key',
                local_key: value
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showLocalKeyDialog.value = false;
            }
        } catch (error) {
            console.error(error);
            // Still close the dialog even on error for now
            showLocalKeyDialog.value = false;
        }
    }


    async function updateMechanism(obj) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'mechanism_of_disease',
                curie: obj.curie,
                comment: obj.comment
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showMechanismDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateVersion(value) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'version',
                curie: value.public,
                private: value.private,
                reasons:  value.reasons,
                description:  value.description
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showVersionDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateNotes(obj) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'notes',
                curie: 'notes update',
                public: obj.public,
                private: obj.private
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showNotesDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateContributor(obj) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'primary_contributor',
                curie: obj.id,
                name: obj.name
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showContributorDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateEvidence(value) {

        const pmids = value.map(({ pmid, code }) => pmid);

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'evidence',
                curie: pmids.join(','),
                evidence: pmids
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showEvidenceDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateReport(value) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'report',
                curie: value.url,
                date: value.date
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showReportDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updateCriteria(value) {

        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid, {
                type: 'criteria',
                curie: value.url + ',' + value.name,
                url: value.url,
                name: value.name,
                remember: value.remember
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // reload the server data
                router.reload();

                // close the dialog
                showCriteriaDialog.value = false;
            }
        } catch (error) {
            console.error(error);
        }
    }


    async function updatePublishDate(value) {

        if (value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'reportdate',
                    curie: value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // reload the server data
                    router.reload();

                    // close the dialog
                    showSelectDialog.value = false;
                }
            } catch (error) {
                console.error(error);
            }
        }
    }

    async function updateGene(value) {
        //console.log(value);
        if (value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'gene',
                    curie: value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                // Check for duplicate errors/warnings - dialog stays open on error
                if (!handleDuplicateResponse(response)) {
                    return; // Blocked by duplicate error - dialog stays open to show error
                }

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // Close the dialog FIRST (on success only)
                    showGeneDialog.value = false;

                    // Then reload the server data
                    router.reload();
                }
            } catch (error) {
                console.error(error);
            }
        }
    }

    async function updateDisease(value) {

        if (value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'disease',
                    curie: value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                // Check for duplicate errors/warnings - dialog stays open on error
                if (!handleDuplicateResponse(response)) {
                    return; // Blocked by duplicate error - dialog stays open to show error
                }

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // Close the dialog FIRST (on success only)
                    showDiseaseDialog.value = false;

                    // Then reload the server data
                    router.reload();
                }
            } catch (error) {
                console.error(error);
            }
        }
    }

    async function republishSubmission() {
        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid + '/republish', {}, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // Show success message
                toast.add({ severity: 'success', summary: 'Republish Initiated', detail: 'Submission moved to draft for editing', life: 3000 });

                // Reload the current page to show updated state (banner, buttons, etc.)
                router.reload();
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to initiate republish', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'An error occurred', life: 5000 });
        }
    }


    async function closeCallback() {

        if (entryText.value != '') {
            try {
                const response = await axios.post('/api/submissions/' + props.submission.sid, {
                    type: 'disease',
                    curie: entryText.value
                }, {
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                });

                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // reload the server data
                    router.reload();

                    // close the dialog
                    showDialog.value = true;
                }
            } catch (error) {
                console.error(error);
            }
        }
    }

    const requireConfirmation = () => {
        confirm.require({
            group: 'headless',
            header: 'Edit Published Submission?',
            message: 'This will create a draft copy for editing. The current published version will remain visible until changes are submitted and published.',
            acceptLabel: 'Continue',
            rejectLabel: 'Cancel',
            accept: () => {
                republishSubmission();
            },
            reject: () => {
                //
            }
        });
    };

    const requireDeleteDraftConfirmation = () => {
        // Build message based on submission status
        const isRepublish = props.submission.status === 'draft_republish';
        const actionType = isRepublish ? 'republish' : 'unpublish';
        let message = `This will permanently delete this draft ${actionType} version. The original published submission will remain unchanged.`;

        confirm.require({
            group: 'headless',
            header: 'Delete Draft?',
            message: message,
            acceptLabel: 'Delete',
            rejectLabel: 'Cancel',
            accept: () => {
                deleteDraftSubmission();
            },
            reject: () => {
                //
            }
        });
    };

    async function deleteDraftSubmission() {
        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid + '/cancel', {}, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if (response.data.status_code == 200) {
                // Redirect to the job's view
                const jobIdent = response.data.job_ident || props.submission.job.ident;
                router.visit('/jobs/' + jobIdent);
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to delete draft', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'An error occurred', life: 5000 });
        }
    }

    const requireUnpublishConfirmation = () => {
        confirm.require({
            group: 'headless',
            header: 'Unpublish Submission?',
            message: 'This submission will be added to a draft job as a request to remove it from public view. The submission will be removed once the job is submitted and processed.',
            acceptLabel: 'Unpublish',
            rejectLabel: 'Cancel',
            accept: () => {
                unpublishSubmission();
            },
            reject: () => {
                //
            }
        });
    };

    const requireRepublishConfirmation = () => {
        confirm.require({
            group: 'headless',
            header: 'Republish Submission?',
            message: 'This submission will be added to a draft job to republish it. You can make edits until the job is submitted. Once the associated job is processed, this submission will be publicly accessible again.',
            acceptLabel: 'Republish',
            rejectLabel: 'Cancel',
            accept: () => {
                republishUnpublishedSubmission();
            },
            reject: () => {
                //
            }
        });
    };

    async function unpublishSubmission() {
        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid + '/unpublish', {}, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if (response.data.status_code == 200) {
                // Show success message
                toast.add({ severity: 'success', summary: 'Unpublish Initiated', detail: 'Submission moved to draft for unpublishing', life: 3000 });

                // Reload the current page to show updated state
                router.reload();
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to initiate unpublish', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'An error occurred', life: 5000 });
        }
    }

    async function republishUnpublishedSubmission() {
        try {
            const response = await axios.post('/api/submissions/' + props.submission.sid + '/republish', {}, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if (response.data.status_code == 200) {
                // Show success message
                toast.add({ severity: 'success', summary: 'Republish Initiated', detail: 'Submission moved to draft for republishing', life: 3000 });

                // Reload the current page to show updated state
                router.reload();
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: response.data.message || 'Failed to initiate republish', life: 5000 });
            }
        } catch (error) {
            console.error(error);
            toast.add({ severity: 'error', summary: 'Error', detail: 'An error occurred', life: 5000 });
        }
    }

    // localMechanism is now a computed property (defined above) - no need for initialization here

    function getDiseaseDeprecationTooltip(disease) {
        if (!disease || disease.status !== 8) {
            return 'DEPRECATED: This disease term is deprecated';
        }

        if (disease.deprecated_name) {
            return `DEPRECATED: ${disease.deprecated_name}`;
        }

        return 'DEPRECATED: This disease term is deprecated';
    }

    // V2 status display for submissions
    function displayStatusV2(status) {
        const statusMap = {
            'draft_new': 'New',
            'submitted_new': 'New',
            'published': 'Published',
            'draft_republish': 'Republish',
            'submitted_republish': 'Republish',
            'draft_unpublish': 'Unpublish',
            'submitted_unpublish': 'Unpublish',
            'unpublished': 'Unpublished'
        };
        return statusMap[status] || status;
    }

    // Get severity for status badge
    function getStatusSeverity(status) {
        const severityMap = {
            'draft_new': 'warning',
            'submitted_new': 'info',
            'published': 'success',
            'draft_republish': 'warning',
            'submitted_republish': 'info',
            'draft_unpublish': 'amber',
            'submitted_unpublish': 'orange',
            'unpublished': 'danger'
        };
        return severityMap[status] || 'secondary';
    }

    // Get custom CSS class for status badges
    function getStatusClass(status, isMostRecent = true) {
        if (!isMostRecent && (status === 'published' || status === 'unpublished')) {
            return 'status-not-current';
        }

        const classMap = {
            'draft_new': 'status-draft-new',
            'submitted_new': 'status-submitted-new',
            'published': 'status-published',
            'draft_republish': 'status-draft-republish',
            'submitted_republish': 'status-submitted-republish',
            'draft_unpublish': 'status-draft-unpublish',
            'submitted_unpublish': 'status-submitted-unpublish',
            'unpublished': 'status-unpublished'
        };
        return classMap[status] || '';
    }

    // Get the status date based on submission status
    function getStatusDate(submission) {
        if (!submission.status) return submission.created_at;

        let dateStr = null;
        switch (submission.status) {
            case 'draft_new':
            case 'draft_republish':
            case 'draft_unpublish':
                dateStr = submission.created_at;
                break;
            case 'submitted_new':
            case 'submitted_republish':
            case 'submitted_unpublish':
                dateStr = submission.submitted_at || submission.created_at;
                break;
            case 'published':
                dateStr = submission.released_at || submission.submitted_at || submission.created_at;
                break;
            case 'unpublished':
                dateStr = submission.unpublished_at || submission.released_at || submission.submitted_at || submission.created_at;
                break;
            default:
                dateStr = submission.created_at;
        }

        return dateStr;
    }

    // Format date as YYYY-MM-DD
    function formatDateTimestamp(dateStr) {
        if (!dateStr) return '';
        const date = new Date(Date.parse(dateStr));
        return date.toISOString().split('T')[0];
    }

console.log(props.submission)
</script>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <!-- header -->
            <div v-if="hasAnyErrors()" class="bg-amber-100 border-l-4 border-amber-700 text-amber-800 p-4 mt-2" role="alert">
                <p class="font-bold">This submission has errors</p>
                <p>
                    Fields with errors are highlighted below in red.  Click on the field edit button to correct.
                    For a submission to be published to GenCC, all errors must be resolved.
                    Once all errors have been cleared, the system will automatically stage the submission for publishing.
                </p>
            </div>
            <!-- Unpublished duplicate warning banner -->
            <div v-if="unpublishedDuplicateWarning" class="bg-amber-100 border-l-4 border-amber-700 text-amber-800 p-4 mt-2" role="alert">
                <p class="font-bold flex items-center gap-2">
                    <i class="pi pi-info-circle"></i>
                    Unpublished Duplicate Exists
                </p>
                <p class="mt-1">{{ unpublishedDuplicateWarning.message }}</p>
                <p v-if="unpublishedDuplicateWarning.sgc_ids && unpublishedDuplicateWarning.sgc_ids.length > 0" class="mt-1 text-sm">
                    Unpublished submission(s):
                    <span v-for="(sgcId, index) in unpublishedDuplicateWarning.sgc_ids" :key="sgcId">
                        <a :href="'/submissions/' + sgcId" class="text-amber-700 hover:text-amber-900 underline font-medium">{{ sgcId }}</a>
                        <span v-if="index < unpublishedDuplicateWarning.sgc_ids.length - 1">, </span>
                    </span>
                </p>
                <p class="mt-2 text-sm italic">
                    Consider republishing the existing unpublished submission instead of creating a new one.
                </p>
            </div>
            <!-- Archived version banner - shown before all other status banners -->
            <div v-if="isArchivedVersion" class="bg-gray-100 border-l-4 border-gray-700 text-gray-800 p-4 mt-2 mb-2" role="alert">
                <p class="font-bold flex items-center gap-2">
                    <i class="pi pi-history"></i>
                    Archived Version (Read Only)
                </p>
                <p class="mt-1">
                    This version (Version {{ submission.version_number || 1 }}) has been superseded by a newer release.
                    It is preserved for historical reference. No actions can be performed on archived versions.
                </p>
            </div>
            <div v-if="hasSubmittedJob && submission.status === 'published' && !isArchivedVersion" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mt-2 mb-2" role="alert">
                <p class="font-bold">A submitted job exists.</p>
                <p>
                    The submitted job is awaiting processing. This published submission cannot be edited or unpublished until the current job is processed.
                </p>
            </div>
            <div v-if="(submission.status === 'published') && !isArchivedVersion" class="bg-green-100 border-l-4 border-green-700 text-green-800 p-4 mb-2" role="alert">
                <p class="font-bold">Submission has been Published.
                    <span v-if="hasPendingDraftVersion" class="float-right text-sm font-normal text-green-700">
                        <i class="pi pi-info-circle mr-1"></i>A draft version is pending
                    </span>
                    <span v-else-if="!hasSubmittedJob" class="float-right">
                        <Button @click="requireConfirmation()" icon="pi pi-refresh" severity="secondary" text raised rounded v-tooltip.top="'Republish'" class="mr-2"></Button>
                        <Button @click="requireUnpublishConfirmation()" icon="pi pi-eye-slash" severity="warning" text raised rounded v-tooltip.top="'Unpublish'"></Button>
                    </span>
                </p>
            </div>
            <div v-if="(submission.status === 'submitted_new')" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mb-2" role="alert">
                <p class="font-bold">New submission has been Submitted.</p>
            </div>
            <div v-if="(submission.status === 'submitted_republish')" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mb-2" role="alert">
                <p class="font-bold">Republished submission has been Submitted.</p>
            </div>
            <div v-if="(submission.status === 'submitted_unpublish')" class="bg-red-100 border-l-4 border-red-700 text-red-800 p-4 mb-2" role="alert">
                <p class="font-bold">Unpublish submission has been Submitted.</p>
            </div>
            <div v-if="(submission.status === 'draft_republish')" class="bg-yellow-100 border-l-4 border-yellow-700 text-yellow-800 p-4 mb-2" role="alert">
                <p class="font-bold">Draft Submission (Republishing).
                    <Button icon="pi pi-trash" severity="danger" class="float-right align-middle ml-2" @click="requireDeleteDraftConfirmation()" v-tooltip.top="'Delete draft'"/>
                    <span class="float-right text-sm font-normal pt-2">Make edits below or delete this draft.</span>
                </p>
            </div>
            <div v-if="(submission.status === 'draft_unpublish')" class="bg-red-100 border-l-4 border-red-700 text-red-800 p-4 mb-2" role="alert">
                <p class="font-bold">Draft Submission (Unpublishing) - Read Only.
                    <Button icon="pi pi-trash" severity="danger" class="float-right align-middle ml-2" @click="requireDeleteDraftConfirmation()" v-tooltip.top="'Delete draft'"/>
                    <span class="float-right text-sm font-normal pt-2">This submission is read-only. Delete this draft to cancel the unpublish.</span>
                </p>
            </div>
            <div v-if="hasSubmittedJob && submission.status === 'unpublished' && !isArchivedVersion" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mt-2 mb-2" role="alert">
                <p class="font-bold">A submitted job exists.</p>
                <p>
                    The submitted job is awaiting processing. This unpublished submission cannot be republished until the current job is processed.
                </p>
            </div>
            <div v-if="(submission.status === 'unpublished') && !isArchivedVersion" class="bg-red-100 border-l-4 border-red-700 text-red-800 p-4 mb-2" role="alert">
                <p class="font-bold">Submission has been Unpublished.
                    <span v-if="hasPendingDraftVersion" class="float-right text-sm font-normal text-red-700">
                        <i class="pi pi-info-circle mr-1"></i>A draft version is pending
                    </span>
                    <span v-else-if="!hasSubmittedJob" class="float-right">
                        <Button @click="requireRepublishConfirmation()" icon="pi pi-refresh" severity="secondary" text raised rounded v-tooltip.top="'Republish'"></Button>
                    </span>
                </p>
            </div>

            <ConfirmDialog group="headless">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <!-- Red theme for Unpublish and Delete, Blue theme for Edit/Republish -->
                        <div v-if="message.header && (message.header.includes('Unpublish') || message.header.includes('Delete'))" class="rounded-full bg-red-700 dark:bg-red-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-exclamation-triangle text-5xl"></i>
                        </div>
                        <div v-else class="rounded-full bg-blue-700 dark:bg-blue-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-pencil text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-2 mt-4">
                            <Button v-if="message.header && (message.header.includes('Unpublish') || message.header.includes('Delete'))" :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-red-700 !ring-red-700 hover:!bg-red-800"></Button>
                            <Button v-else :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-blue-700 !ring-blue-700 hover:!bg-blue-800"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>

            <!-- entries -->
            <div class="grid grid-cols-12 mt-4 gap-0">
                <div class="col-span-12">
                    <div class="grid grid-cols-12 gap-0">

                        <!-- Row 1: Submitter, Submitted by -->
                        <div class="col-span-2 pt-3 text-right pr-3">Submitter:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal font-bold">{{ submission.submitter.name }}</div>
                            <div class="text-xs">{{ submission.submitter.curie }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Submitted by:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal font-bold">{{ submission.user.name }}</div>
                            <div class="text-xs">{{ submission.user.email }}</div>
                        </div>

                        <!-- Row 2: Status Date, Status -->
                        <div class="col-span-2 pt-3 text-right pr-3">Status Date:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal font-bold">{{ formatDateTimestamp(getStatusDate(submission)) }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Status:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <Tag v-if="submission.status" :value="displayStatusV2(submission.status)" :severity="getStatusSeverity(submission.status)" :class="['status-tag', getStatusClass(submission.status, submission.is_most_recent !== false)]" />
                        </div>

                        <hr class="col-span-12 my-4" />

                        <!-- local key -->
                        <div class="col-span-2 pt-3 text-right pr-3">Local Key:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.local_key }}</div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('local_key')" severity="success" text raised rounded/></div>
                        <div class="col-span-12 ">
                              <ChangeLocalKey v-model:visible="showLocalKeyDialog" v-bind:input="submission.local_key"  @input_local_key_item="updateLocalKey" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeLocalKey>
                        </div>

                        <!-- classification -->
                        <div class="col-span-2 pt-3 text-right pr-3">Classification:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('classification_curie_id') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal ">
                                {{  submission.classification?.name || '-' }}
                            </div>
                            <div class="text-xs">{{ submission.classification?.curie || '' }}</div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('classification_curie_id')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('classification_curie_id')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('classification_curie_id')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeClassification v-model:visible="showClassificationDialog" v-bind:input="submission.classification?.curie || ''" @select_dialog_close="showClassificationDialog = false" @select_classification_item="updateClassification" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeClassification>
                          </div>
                        </div>

                        <!-- gene -->
                        <div class="col-span-2 pt-3 text-right pr-3">Gene:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('gene_hgnc_id') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">{{ submission.gene?.symbol || '-' }}</div>
                            <div class="text-xs">
                                <a v-if="submission.gene?.hgnc_id && getGeneUrl(submission.gene.hgnc_id)"
                                   :href="getGeneUrl(submission.gene.hgnc_id)"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="text-blue-600 hover:text-blue-800 hover:underline">
                                    {{ submission.gene.hgnc_id }}
                                </a>
                                <span v-else>{{ submission.gene?.hgnc_id || '' }}</span>
                            </div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('gene_hgnc_id')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('gene_hgnc_id')" :disabled="isGeneNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('gene_hgnc_id')" :disabled="isGeneNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeGene v-model:visible="showGeneDialog" v-bind:input="submission.gene?.hgnc_id || ''" @input_dialog_close="showGeneDialog = false" @input_gene_item="updateGene" @clear_api_error="clearDuplicateError" :apiError="duplicateError" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeGene>
                          </div>
                        </div>

                        <!-- disease -->
                        <div class="col-span-2 pt-3 text-right pr-3">Disease:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('disease_curie_id') ? 'border-2 border-red-600' : ''">
                            <!-- Primary Display: Always show MONDO disease (normalized) -->
                            <div class="mb-2">
                                <div class="font-normal">{{ !submission.disease || submission.disease?.curie == "MONDO:0000001" ? '-' : submission.disease.name }}</div>
                                <div class="text-xs">
                                    <a v-if="submission.disease?.curie && submission.disease.curie !== 'MONDO:0000001' && getDiseaseUrl(submission.disease.curie)"
                                       :href="getDiseaseUrl(submission.disease.curie)"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-blue-600 hover:text-blue-800 hover:underline">
                                        {{ submission.disease.curie }}
                                    </a>
                                    <span v-else>{{ !submission.disease || submission.disease?.curie == "MONDO:0000001" ? '-' : submission.disease?.curie }}</span>
                                    <span v-if="submission.disease?.status === 8" class="text-amber-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(submission.disease)">⚠</span>
                                </div>
                            </div>
                            <!-- Secondary Display: Show original disease if different from MONDO -->
                            <div v-if="submission.original_disease && submission.disease && submission.original_disease.id !== submission.disease.id"
                                 class="mb-2 mt-3">
                                <div class="text-xs text-gray-500 font-semibold">Submitted as:</div>
                                <div class="font-normal">{{ submission.original_disease.name }}</div>
                                <div class="text-xs">
                                    <a v-if="submission.original_disease.curie && getDiseaseUrl(submission.original_disease.curie)"
                                       :href="getDiseaseUrl(submission.original_disease.curie)"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-blue-600 hover:text-blue-800 hover:underline">
                                        {{ submission.original_disease.curie }}
                                    </a>
                                    <span v-else>{{ submission.original_disease.curie }}</span>
                                    <span v-if="submission.original_disease.status === 8" class="text-amber-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(submission.original_disease)">⚠</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('disease_curie_id')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('disease_curie_id')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('disease_curie_id')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeDisease v-model:visible="showDiseaseDialog" v-bind:input="submission.original_disease?.curie || submission.disease?.curie || ''" @input_dialog_close="showDiseaseDialog = false" @input_disease_item="updateDisease" @clear_api_error="clearDuplicateError" :apiError="duplicateError" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeDisease>
                          </div>
                        </div>

                        <!-- mode of inheritance -->
                        <div class="col-span-2 pt-3 text-right pr-3">Mode Of Inheritance:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('moi_curie_id') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">{{ submission.inheritance?.name || '-' }}</div>
                            <div class="text-xs">{{ submission.inheritance?.curie || '' }}</div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('moi_curie_id')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('moi_curie_id')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('moi_curie_id')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeInheritance v-model:visible="showSelectDialog" v-bind:input="submission.inheritance?.curie || ''" @select_dialog_close="showSelectDialog = false" @select_moi_item="updateInheritance" @clear_api_error="clearDuplicateError" :apiError="duplicateError" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeInheritance>
                          </div>
                        </div>

                        <!-- mechanism of disease -->
                        <div class="col-span-2 pt-3 text-right pr-3">Mechanism of Disease:</div>
                        <div class="col-span-2 py-1 my-2 border-l-8 pl-3" :class="hasProperty('mech_of_disease') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">{{ submission.submission_data?.mechanism?.name || '' }}</div>
                            <div class="text-xs">{{ submission.submission_data?.mechanism?.curie || '' }}</div>
                        </div>
                        <div class="col-span-1 pt-3 text-right pr-3">Comment:</div>
                        <div class="col-span-6 py-1 my-2 border-l-8 pl-3" :class="hasProperty('mech_of_disease') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">
                                <MarkdownDisplay :content="submission.submission_data?.mechanism?.comments || ''" />
                            </div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('mech_of_disease')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('mech_of_disease')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('mech_of_disease')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeMechanism v-model:visible="showMechanismDialog" v-bind:input="localMechanism" @select_dialog_close="showMechanismDialog = false" @select_mechanism_item="updateMechanism" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeMechanism>
                          </div>
                        </div>

                        <!-- evaluated date-->
                        <!--<div class="col-span-2 pt-3 text-right pr-3">Evaluated/Report Date:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('publish_date') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">{{ submission.report_date }}</div>
                        </div>
                        <div v-if="hasProperty('publish_date')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('publish_date')" severity="danger" text raised rounded/></div>
                        <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('publish_date')" severity="success" text raised rounded/></div>
                        <div class="col-span-12 ">
                            <SelectDateDialog v-model:visible="showDateDialog" @select_date_close="showDateDialog = false" @select_date_item="updatePublishDate" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></SelectDateDialog>
                        </div>-->

                        <!-- pubmed ids-->
                        <div class="col-span-2 pt-3 text-right pr-3">PubMed IDs:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3" :class="hasProperty('invalid_pmid') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal grid grid-cols-[10%_85%_5%] grid-flow-col auto-cols-auto" v-for="item in submission.pubmeds">
                                    <div class="mb-2">
                                        {{ item.pmid }}
                                    </div>
                                    <div class="mb-2">
                                        <span class="fw-bold">{{ item.sortfirstauthor }}</span>, et al.,
                                        {{  new Date(Date.parse(item.sortpubdate)).getFullYear() }}, {{ item.title }}
                                    </div>
                                    <div class="mt-1">
                                        <a :href="'https://pubmed.ncbi.nlm.nih.gov/' + item.pmid" target="_pubmed"><i class="pi pi-external-link mr-5 float-end" style="font-size: 1rem"></i></a>
                                    </div>
                            </div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('invalid_pmid')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('evidence')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('evidence')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeEvidence v-model:visible="showEvidenceDialog" @input_evidence_close="showEvidence = false" @input_evidence_item="updateEvidence" v-bind:input="submission.submission_data?.evidence" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeEvidence>
                          </div>
                        </div>

                        <!-- report url -->
                        <div class="col-span-2 pt-3 text-right pr-3">Public Report:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3" :class="hasProperty('report_url') ? 'border-2 border-red-600' : ''">
                            <div v-show="submission.report_url" class="font-normal"><a class="underline" id='click-exit-public-report' target="_blank" v-bind:href="submission.report_url">Click here to view the public report <i class="fas fa-external-link-alt"></i></a></div>
                            <div class="text-xs">{{ submission.report_url }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Evaluated Date:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3" :class="hasProperty('report_date') ? 'border-2 border-red-600' : ''">
                            <div class="font-normal">{{ submission.report_date ? new Date(Date.parse(submission.report_date)).toISOString().split('T')[0] : '' }}</div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('report_url') || hasProperty('report_date')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('report')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('report')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeReport v-model:visible="showReportDialog" @input_report_close="showReport = false" @input_report_item="updateReport" v-bind:input="submission.submission_data?.report" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeReport>
                          </div>
                        </div>

                        <!-- assertion criteria -->
                        <div class="col-span-2 pt-3 text-right pr-3">Assertion Criteria:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3" :class="hasProperty('criteria_url') ? 'border-2 border-red-600' : ''">
                            <div v-show="submission.submission_data?.criteria?.url" class="font-normal"><a class="underline" id='click-exit-assertion-criteria' target="_blank" v-bind:href="submission.submission_data?.criteria?.url">Click here to view assertion criteria <i class="fas fa-external-link-alt"></i></a></div>
                            <div class="text-xs">{{ submission.submission_data?.criteria?.url }}</div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Name:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.submission_data?.criteria?.name }}</div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div v-if="hasProperty('criteria_url')" class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-times" @click="openDialog('criteria')" :disabled="isNotEditable" severity="danger" text raised rounded/></div>
                          <div v-else class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('criteria')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeCriteria v-bind:input="submission.submission_data?.criteria" v-bind:coptions="criteria_options" v-model:visible="showCriteriaDialog" @input_criteria_close="showCriteria = false" @input_criteria_item="updateCriteria" v-model:input="dialogInput" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeCriteria>
                          </div>
                        </div>

                        <!-- Notes -->
                        <div class="col-span-2 pt-3 text-right pr-3">Notes Public:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">
                                <MarkdownDisplay :content="submission.submission_data?.notes?.display || ''" />
                            </div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Private:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">
                                <MarkdownDisplay :content="submission.submission_data?.notes?.private || ''" />
                            </div>
                        </div>
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('notes')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeNotes v-model:visible="showNotesDialog" v-bind:input="submission.submission_data?.notes" @input_notes_close="showDialog = false" @input_notes_item="updateNotes" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '60rem' }"></ChangeNotes>
                          </div>
                        </div>

                        <!-- Contributors -->
                        <div class="col-span-2 pt-3 text-right pr-3">Primary Contributor:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.submission_data?.contributors?.primary?.name }}</div>
                            <div class="text-sm">{{ submission.submission_data?.contributors?.primary?.id }}</div>
                        </div>
                        <!--<div class="col-span-2 pt-3 text-right pr-3">Secondary:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal"></div>
                        </div>-->
                        <div v-if="jobHasStatusProcessingOrError()">
                          <div class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('contributor')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                          <div class="col-span-12 ">
                              <ChangeContributor v-model:visible="showContributorDialog" @input_contributor_close="showContributor = false" @input_contributor_item="updateContributor" v-bind:input="submission.submission_data?.contributors?.primary" v-model:input2="dialogInput2" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></ChangeContributor>
                          </div>
                        </div>

                        <!-- Version -->
                        <div class="col-span-2 pt-3 text-right pr-3">Version:</div>
                        <div class="col-span-3 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.submission_data?.version?.display }}  (Public)
                                <span class="ml-5">{{ submission.submission_data?.version?.internal }}  (Internal)</span>
                            </div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Reasons:</div>
                        <div class="col-span-4 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.submission_data?.version?.reasons?.join(', ') }}</div>
                        </div>

                        <div class="flex col-span-1 py-1 pl-4 my-2 items-center">
                          <div v-if="jobHasStatusProcessingOrError()">
                            <Button icon="pi pi-check" @click="openDialog('version')" :disabled="isNotEditable" severity="success" text raised rounded/></div>
                        </div>
                        <div class="col-span-2 pt-3 text-right pr-3">Description of Change:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal">{{ submission.submission_data?.version?.description }}</div>
                        </div>
                        <div class="col-span-12 ">
                            <ChangeVersion v-model:visible="showVersionDialog" @input_version_close="showVersionDialog = false" @input_version_item="updateVersion" v-bind:input="submission.submission_data?.version" v-model:input2="dialogInput2" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '60rem' }"></ChangeVersion>
                        </div>

                        <!-- Workflow -->
                        <div class="col-span-2 pt-3 text-right pr-3">Workflow:</div>
                        <div class="col-span-9 py-1 my-2 border-l-8 pl-3">
                            <div class="font-normal" v-for="(value, key) in submission.submission_data?.workflow">
                                {{ key }} : {{ value }}
                            </div>
                        </div>
                        <!--<div class="flex col-span-1 py-1 pl-4 my-2 items-center"><Button icon="pi pi-check" @click="openDialog('notes')" severity="success" text raised rounded /></div>
                        <div class="col-span-12 ">
                            <InputNotesDialog v-model:visible="showNotesDialog" @input_notes_close="showDialog = false" @input_notes_item="updateNotes" v-model:input="dialogInput" v-model:input2="dialogInput2" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '60rem' }"></InputNotesDialog>
                        </div>-->

                        <hr class="col-span-12 my-4" />

                        <!-- input dialog modal -->
                        <!--<div class="col-span-12 ">
                            <InputDialog v-model:visible="showDialog" @input_dialog_close="showDialog = false" v-model:input="dialogInput" header="header" :title="dialogTitle" :label="dialogLabel" :style="{ width: '50rem' }"></InputDialog>
                        </div>-->

                    </div>
                </div>
            </div>

        </div>

    </div>
</template>
