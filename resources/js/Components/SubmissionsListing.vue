<script setup>


    import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
    import { router } from '@inertiajs/vue3'
    import { usePage } from '@inertiajs/vue3';
    import { FilterMatchMode, FilterOperator } from "primevue/api";
    import ConfirmDialog from 'primevue/confirmdialog';
    import { useConfirm } from "primevue/useconfirm";
    import { useToast } from "primevue/usetoast";
    import ToggleButton from 'primevue/togglebutton';
    import Tag from 'primevue/tag';
    import { getDiseaseUrl, getGeneUrl } from '@/utils/externalLinks';


    const props = defineProps(['submissions', 'errors', 'favorites', 'hasSubmittedJob', 'jobStatus'])

    const page = usePage()

    const mine = computed(() => page.props.mine)

    // Local reactive copy of favorites for optimistic updates
    // Initialize from props but can be updated locally for instant UI feedback
    const localFavorites = ref([]);

    // Sync localFavorites when props.favorites changes (e.g., after page reload)
    watch(() => props.favorites, (newFavorites) => {
        if (!newFavorites) {
            localFavorites.value = [];
        } else if (Array.isArray(newFavorites)) {
            localFavorites.value = [...newFavorites];
        } else {
            localFavorites.value = Object.values(newFavorites);
        }
    }, { immediate: true });

    // Use localFavorites for display (allows optimistic updates)
    const favorites = computed(() => localFavorites.value)

    // Transform submissions to include a sortable status_date field
    // This allows PrimeVue DataTable to sort on the computed status date
    const submissionsWithStatusDate = computed(() => {
        if (!props.submissions) return [];
        return props.submissions.map(submission => ({
            ...submission,
            status_date: getStatusDateRaw(submission)
        }));
    })

    const confirm = useConfirm();
    const toast = useToast();


    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS },
        sid: { value: null, matchMode: FilterMatchMode.CONTAINS },
        'gene.symbol': { value: null, matchMode: FilterMatchMode.CONTAINS },
        'disease.name': { value: null, matchMode: FilterMatchMode.CONTAINS },
        'inheritance.name': { value: null, matchMode: FilterMatchMode.CONTAINS },
        created_at: { value: null, matchMode: FilterMatchMode.CONTAINS }
    })

    const options = [
        {value: "HP:0000005", label: "Unknown"},
        {value: "HP:0000006", label: "Autosomal dominant"},
        {value: "HP:0010985", label: "Gonosomal"},
        {value: "HP:0001426", label: "Multifactorial"},
        {value: "HP:0032382", label: "Uniparental disomy"},
        {value: "HP:0001428", label: "Somatic mutation"},
        {value: "HP:0000007", label: "Autosomal recessive"},
        {value: "HP:0001466", label: "Contiguous gene syndrome"},
        {value: "HP:0003743", label: "Genetic anticipation"},
        {value: "HP:0001425", label: "Heterogeneous"},
        {value: "HP:0001427", label: "Mitochondrial"},
        {value: "HP:0032113", label: "Semidominant"},
        {value: "HP:0003745", label: "Sporadic"},
        {value: "HP:0001417", label: "X-linked"},
        {value: "HP:0001419", label: "X-linked recessive"},
        {value: "HP:0001423", label: "X-linked dominant"},
        {value: "HP:0001450", label: "Y-linked inheritance"},
        {value: "HP:0001442", label: "Somatic mosaicism"},
        {value: "HP:0012274", label: "Autosomal dominant inheritance with paternal imprinting"},
        {value: "HP:0010984", label: "Digenic inheritance"},
        {value: "HP:0012275", label: "Autosomal dominant inheritance with maternal imprinting"}

    ];

    // Filter display options - context-aware based on job status
    // General submissions list: Full hierarchical structure with parent/child indentation
    // Pending job (draft/submitted): Only pending subfilters, flat list
    // Released job: Only released subfilters, flat list
    // Show Errors: Only in draft jobs or general list (errors can be fixed in drafts)
    const displayOptions = computed(() => {
        // Determine job context
        const isGeneralList = !props.jobStatus;
        const isDraftJob = props.jobStatus === 'draft';
        const isSubmittedJob = props.jobStatus === 'submitted';
        const isReleasedJob = props.jobStatus === 'released' || props.jobStatus === 'processed';

        // Base options always available
        const baseOptions = [
            { name: 'Show All', option: 'all', isParent: false },
            { name: 'Show Favorites', option: 'favorites', isParent: false }
        ];

        // Show Errors only in draft jobs or general list (where draft jobs may exist)
        if (isDraftJob || isGeneralList) {
            baseOptions.push({ name: 'Show Errors', option: 'errors', isParent: false });
        }

        if (isGeneralList) {
            // Full hierarchical structure for general submissions list
            return [
                ...baseOptions,
                { name: 'Show Pending', option: 'pending', isParent: true },
                { name: 'Show New', option: 'pending_new', isParent: false, isChild: true },
                { name: 'Show Republish', option: 'pending_republish', isParent: false, isChild: true },
                { name: 'Show Unpublish', option: 'pending_unpublish', isParent: false, isChild: true },
                { name: 'Show Released', option: 'released', isParent: true },
                { name: 'Show Published', option: 'released_published', isParent: false, isChild: true },
                { name: 'Show Unpublished', option: 'released_unpublished', isParent: false, isChild: true }
            ];
        } else if (isDraftJob || isSubmittedJob) {
            // Pending job: Only pending subfilters, flat list (no parent, no indentation)
            return [
                ...baseOptions,
                { name: 'Show New', option: 'pending_new', isParent: false },
                { name: 'Show Republish', option: 'pending_republish', isParent: false },
                { name: 'Show Unpublish', option: 'pending_unpublish', isParent: false }
            ];
        } else if (isReleasedJob) {
            // Released job: Only released subfilters, flat list (no parent, no indentation)
            return [
                ...baseOptions,
                { name: 'Show Published', option: 'released_published', isParent: false },
                { name: 'Show Unpublished', option: 'released_unpublished', isParent: false }
            ];
        }

        // Fallback to base options
        return baseOptions;
    });

    // Historic toggle: false = hide historic (live only), true = show historic
    const showHistoric = ref(false);

    // Tooltip for historic toggle - describes current state
    const historicToggleTooltip = computed(() => {
        return showHistoric.value
            ? 'Include historic submissions'
            : 'Hide historic submissions';
    });

    // Determine if historic toggle should be visible
    // Show on: general submissions list (no jobStatus) or released jobs
    // Hide on: pending jobs (draft or submitted)
    const showHistoricToggle = computed(() => {
        // If no jobStatus prop, we're on the general submissions list
        if (!props.jobStatus) return true;
        // Show toggle for released jobs only
        return props.jobStatus === 'released' || props.jobStatus === 'processed';
    });

    const filterUser = defineModel(false);

    filterUser.value = false;

    const selectedDisplay = ref('all');

    // Bulk selection state
    const selectedSubmissions = ref([]);
    const isLoadingBulkAction = ref(false); // Loading state for bulk action reload

    // Bulk action progress tracking
    const bulkActionProgress = ref({
        current: 0,
        total: 0,
        action: '',
        successCount: 0,
        errorCount: 0
    });

    // Cancellation flag for bulk operations
    const bulkActionCancelled = ref(false);

    // Cancel bulk action handler
    function cancelBulkAction() {
        bulkActionCancelled.value = true;
    }

    // Computed properties for bulk actions
    // These determine which batch operations are available based on selected submissions

    // Delete: Only available when ALL selected are pending submissions in draft job
    // With simplified model: pending statuses are 'new', 'republish', 'unpublish'
    // Must also check job status is 'draft' (not submitted)
    const bulkDeleteAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return false;

        // Pending statuses (includes both new simplified and legacy compound)
        const pendingStatuses = ['new', 'republish', 'unpublish', 'draft_new', 'draft_republish', 'draft_unpublish'];
        return selectedSubmissions.value.every(s =>
            pendingStatuses.includes(s.status) && s.job?.status !== 'submitted'
        );
    });

    // Unpublish: Only available when ALL selected are live published versions with no pending draft
    // is_live=true means the submission is currently publicly accessible
    // is_most_recent must be true (no pending draft version)
    // Cannot unpublish if there's a submitted job pending
    const bulkUnpublishAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return false;
        if (props.hasSubmittedJob) return false;

        return selectedSubmissions.value.every(s =>
            s.status === 'published' && s.is_live === true && s.is_most_recent !== false
        );
    });

    // Republish: Only available when ALL selected are live published OR unpublished versions with no pending draft
    // Live published can be republished, unpublished (which are always is_most_recent but not is_live) can also be republished
    // Both must also have is_most_recent=true (no pending draft version)
    // Cannot republish if there's a submitted job pending
    const bulkRepublishAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return false;
        if (props.hasSubmittedJob) return false;

        return selectedSubmissions.value.every(s => {
            // Published submissions must be live (not archived) AND most recent (no pending draft)
            if (s.status === 'published') {
                return s.is_live === true && s.is_most_recent !== false;
            }
            // Unpublished submissions can be republished if they are the most recent version (no pending draft)
            if (s.status === 'unpublished') {
                return s.is_most_recent !== false;
            }
            return false;
        });
    });

    // Favorite: Only available when ALL selected are NOT already favorited
    // Works for both current and historical submissions
    const bulkFavoriteAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return false;

        const noneFavorited = selectedSubmissions.value.every(s => !favorites.value.includes(s.ident));
        return noneFavorited;
    });

    // Unfavorite: Only available when ALL selected ARE already favorited
    // Works for both current and historical submissions
    const bulkUnfavoriteAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return false;

        const allFavorited = selectedSubmissions.value.every(s => favorites.value.includes(s.ident));
        return allFavorited;
    });

    // Determine if we should show "Favorite" or "Unfavorite" for bulk action
    // Returns null if neither action is available (mixed favorite state)
    const bulkFavoriteAction = computed(() => {
        if (bulkFavoriteAvailable.value) return 'favorite';
        if (bulkUnfavoriteAvailable.value) return 'unfavorite';
        return null;
    });

    // Calculate filtered submission count
    // This accounts for both the display filter (Show All, Show New, etc.) and the global search filter
    const filteredSubmissionsCount = computed(() => {
        if (!props.submissions) return 0;

        // First apply the row filter (display options)
        const rowFiltered = props.submissions.filter(rowFilter);

        // If there's no global filter, return the row-filtered count
        if (!filters.value.global.value) {
            return rowFiltered.length;
        }

        // Apply global filter manually to match PrimeVue's behavior
        const searchTerm = filters.value.global.value.toLowerCase();
        const globalFilterFields = ['sid', 'display_id', 'friendly', 'gene.symbol', 'gene.hgnc_id', 'disease.name', 'disease.curie', 'inheritance.name', 'inheritance.curie', 'classification.name', 'created_at'];

        return rowFiltered.filter(item => {
            return globalFilterFields.some(field => {
                const keys = field.split('.');
                let value = item;
                for (const key of keys) {
                    value = value?.[key];
                }
                return value?.toString().toLowerCase().includes(searchTerm);
            });
        }).length;
    });

    const axios = window.axios;

    // Listen for Inertia finish event to reset loading state
    const handleFinish = () => {
        // Clear the timeout if reload finished before overlay was shown
        if (window.bulkActionLoadingTimeout) {
            clearTimeout(window.bulkActionLoadingTimeout);
            window.bulkActionLoadingTimeout = null;
        }
        isLoadingBulkAction.value = false;
    };

    let removeFinishListener = null;

    onMounted(() => {
        removeFinishListener = router.on('finish', handleFinish);
    });

    onUnmounted(() => {
        if (removeFinishListener) {
            removeFinishListener();
        }
    });

    function removeSubmission(sid) {

        if (sid != '') {

            axios.delete('/api/submissions/' + sid)
                .then(response => {

                    if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                    {
                        // reload the server data
                        router.reload();
                        return true;
                    }
                    else
                    {
                        console.log(response);
                    }
                })
                .catch(error => {
                    console.error(error);
                });
        }

        return false;
    }

    const requireConfirmation = (sid) => {
        confirm.require({
            group: 'headless',
            header: 'Are you sure?',
            message: 'Please confirm to delete this submission.',
            acceptLabel: 'Delete Submission',
            rejectLabel: 'Cancel',
            accept: () => {
                removeSubmission(sid);
            },
            reject: () => {
                //
            }
        });
    };

    function unpublishSubmission(sid) {
        if (sid != '') {
            axios.post('/api/submissions/' + sid, {
                type: 'unpublish',
                value: true
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            })
            .then(response => {
                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    // reload the server data
                    router.reload();
                    toast.add({ severity: 'success', summary: 'Confirmed', detail: 'Submission Unpublished', life: 3000 });
                    return true;
                }
                else
                {
                    console.log(response);
                }
            })
            .catch(error => {
                console.error(error);
            });
        }

        return false;
    }

    const requireUnpublishConfirmation = (sid) => {
        confirm.require({
            group: 'headless',
            header: 'Unpublish Submission?',
            message: 'This submission will be added to a draft job as a request to remove it from public view. The submission will be removed once the job is submitted and processed.',
            accept: () => {
                unpublishSubmission(sid);
            },
            reject: () => {
                //
            }
        });
    };

    // V2 State Transition Functions
    function republishSubmission(sid) {
        if (sid != '') {
            axios.post('/api/submissions/' + sid + '/republish')
            .then(response => {
                if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                    toast.add({ severity: 'success', summary: 'Republish Initiated', detail: 'Submission moved to draft for editing', life: 3000 });

                    // Redirect to submission edit view
                    if (response.data.submission_ident) {
                        router.visit('/submissions/' + response.data.submission_ident);
                    } else {
                        // Fallback: reload the page
                        router.reload();
                    }
                    return true;
                }
                else {
                    console.log(response);
                    toast.add({ severity: 'error', summary: 'Error', detail: response.data.message, life: 3000 });
                }
            })
            .catch(error => {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to initiate republish', life: 3000 });
            });
        }
        return false;
    }

    function unpublishSubmissionV2(sid) {
        if (sid != '') {
            axios.post('/api/submissions/' + sid + '/unpublish')
            .then(response => {
                if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                    router.reload();
                    toast.add({ severity: 'success', summary: 'Unpublish Initiated', detail: 'Submission moved to draft for unpublishing', life: 3000 });
                    return true;
                }
                else {
                    console.log(response);
                    toast.add({ severity: 'error', summary: 'Error', detail: response.data.message, life: 3000 });
                }
            })
            .catch(error => {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to initiate unpublish', life: 3000 });
            });
        }
        return false;
    }

    function cancelSubmission(sid) {
        if (sid != '') {
            axios.post('/api/submissions/' + sid + '/cancel')
            .then(response => {
                if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                    router.reload();
                    return true;
                }
                else {
                    console.log(response);
                    toast.add({ severity: 'error', summary: 'Error', detail: response.data.message, life: 3000 });
                }
            })
            .catch(error => {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to cancel operation', life: 3000 });
            });
        }
        return false;
    }

    const requireRepublishConfirmation = (sid) => {
        confirm.require({
            group: 'headless',
            header: 'Edit Published Submission?',
            message: 'This will create a draft copy for editing. The current published version will remain visible until changes are submitted and published.',
            acceptLabel: 'Continue',
            rejectLabel: 'Cancel',
            accept: () => {
                republishSubmission(sid);
            },
            reject: () => {
                //
            }
        });
    };

    const requireUnpublishConfirmationV2 = (sid) => {
        confirm.require({
            group: 'headless',
            header: 'Unpublish Submission?',
            message: 'This submission will be added to a draft job as a request to remove it from public view. The submission will be removed once the job is submitted and processed.',
            acceptLabel: 'Unpublish',
            rejectLabel: 'Cancel',
            accept: () => {
                unpublishSubmissionV2(sid);
            },
            reject: () => {
                //
            }
        });
    };

    const requireDeleteDraftConfirmation = (sid) => {
        // Find the submission to determine type (using simplified status values)
        const submission = props.submissions.find(s => s.sid === sid);
        const isRepublish = submission?.status === 'republish';
        const actionType = isRepublish ? 'republish' : 'unpublish';

        let message = `This will permanently delete this draft ${actionType} version. The original published submission will remain unchanged.`;

        confirm.require({
            group: 'headless',
            header: 'Delete Draft?',
            message: message,
            acceptLabel: 'Delete',
            rejectLabel: 'Cancel',
            accept: () => {
                cancelSubmission(sid);
            },
            reject: () => {
                //
            }
        });
    };

    // Bulk Action Functions
    function bulkRepublishSubmissions() {
        // Use idents to target specific versions of submissions
        const idents = selectedSubmissions.value.map(s => s.ident);
        confirm.require({
            group: 'headless',
            header: 'Edit Multiple Submissions?',
            message: `This will create draft copies for editing ${idents.length} submission(s). The current published versions will remain visible until changes are submitted and published.`,
            acceptLabel: 'Continue',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('republish', idents, true);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkUnpublishSubmissions() {
        // Use idents to target specific versions of submissions
        const idents = selectedSubmissions.value.map(s => s.ident);
        confirm.require({
            group: 'headless',
            header: 'Unpublish Multiple Submissions?',
            message: `${idents.length} submission(s) will be added to a draft job as requests to remove them from public view. The submissions will be removed once the job is submitted and processed.`,
            acceptLabel: 'Unpublish',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('unpublish', idents, true);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkDeleteSubmissions() {
        // Use idents instead of sids to identify specific versions for deletion
        // This ensures we delete the exact version selected (e.g., draft_republish)
        // rather than finding a different version with the same sid
        const idents = selectedSubmissions.value.map(s => s.ident);
        confirm.require({
            group: 'headless',
            header: 'Delete Multiple Submissions?',
            message: `Are you sure you want to delete ${idents.length} submission(s)?`,
            acceptLabel: 'Delete',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('delete', idents, true);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkToggleFavorites() {
        const action = bulkFavoriteAction.value; // 'favorite' or 'unfavorite'
        // Use idents to target specific versions of submissions
        const idents = selectedSubmissions.value.map(s => s.ident);

        // Show confirmation dialog
        confirm.require({
            group: 'headless',
            message: `Are you sure you want to ${action} ${idents.length} submission(s)?`,
            header: `Confirm ${action === 'favorite' ? 'Favorite' : 'Unfavorite'}`,
            icon: 'pi pi-exclamation-triangle',
            rejectClass: 'p-button-secondary p-button-outlined',
            rejectLabel: 'Cancel',
            acceptLabel: 'Continue',
            accept: async () => {
                // Save previous state for rollback on error
                const previousFavorites = [...localFavorites.value];

                // Optimistic update - apply changes immediately
                if (action === 'favorite') {
                    // Add all idents that aren't already favorites
                    const newFavorites = [...localFavorites.value];
                    idents.forEach(ident => {
                        if (!newFavorites.includes(ident)) {
                            newFavorites.push(ident);
                        }
                    });
                    localFavorites.value = newFavorites;
                } else {
                    // Remove all idents from favorites
                    localFavorites.value = localFavorites.value.filter(fav => !idents.includes(fav));

                    // If showing only favorites and all are removed, switch to Show All
                    if (selectedDisplay.value === 'favorites' && localFavorites.value.length === 0) {
                        selectedDisplay.value = 'all';
                    }
                }

                // Clear selection immediately for better UX
                selectedSubmissions.value = [];

                try {
                    // Use the new bulk favorites endpoint - single request for all submissions
                    const response = await axios.post('/api/submissions/bulk-favorites', {
                        action: action,
                        idents: idents
                    }, {
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });

                    // Check response and show appropriate message
                    if (response.data.success === 'true') {
                        toast.add({
                            severity: 'success',
                            summary: 'Success',
                            detail: `${response.data.success_count} submission(s) ${action === 'favorite' ? 'favorited' : 'unfavorited'}`,
                            life: 3000
                        });
                    } else if (response.data.success === 'partial') {
                        // Partial success - reload to get accurate state
                        router.reload({ only: ['favorites'], preserveScroll: true });
                        toast.add({
                            severity: 'warn',
                            summary: 'Partially Complete',
                            detail: `${response.data.success_count} submission(s) updated, ${response.data.error_count} not found`,
                            life: 5000
                        });
                    } else {
                        // Failed - revert optimistic update
                        localFavorites.value = previousFavorites;
                        toast.add({
                            severity: 'error',
                            summary: 'Error',
                            detail: response.data.message || 'Failed to update favorites',
                            life: 5000
                        });
                    }
                } catch (err) {
                    // Error - revert optimistic update
                    localFavorites.value = previousFavorites;
                    console.error('Bulk favorites error:', err);

                    toast.add({
                        severity: 'error',
                        summary: 'Error',
                        detail: `Failed to update favorites: ${err.message}`,
                        life: 5000
                    });
                }
            }
        });
    }

    async function executeBulkAction(action, ids, useIdents = false) {
        const BATCH_SIZE = 50; // Process in batches for progress tracking
        const isLargeOperation = ids.length > BATCH_SIZE;

        // For large republish/unpublish operations, process in batches with progress
        if (isLargeOperation && (action === 'republish' || action === 'unpublish')) {
            return executeBulkActionWithProgress(action, ids, useIdents, BATCH_SIZE);
        }

        // For delete and small operations, use the optimized single-request approach
        // Show loading overlay only if operation takes longer than 2 seconds
        const loadingTimeout = setTimeout(() => {
            isLoadingBulkAction.value = true;
        }, 2000);

        window.bulkActionLoadingTimeout = loadingTimeout;

        try {
            // Build request payload - use idents for delete to target specific versions
            const payload = { action: action };
            if (useIdents) {
                payload.idents = ids;
            } else {
                payload.sids = ids;
            }

            const response = await axios.post('/api/submissions/bulk-action', payload, {
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                selectedSubmissions.value = []; // Clear selection

                // Toast notification removed per user request

                setTimeout(() => {
                    router.reload();
                }, 500);
            } else {
                // Clear the timeout if error occurred
                if (window.bulkActionLoadingTimeout) {
                    clearTimeout(window.bulkActionLoadingTimeout);
                    window.bulkActionLoadingTimeout = null;
                }
                isLoadingBulkAction.value = false;

                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: response.data.message || 'Failed to complete bulk action',
                    life: 3000
                });
            }
        } catch (error) {
            console.error(error);

            // Clear the timeout if error occurred
            if (window.bulkActionLoadingTimeout) {
                clearTimeout(window.bulkActionLoadingTimeout);
                window.bulkActionLoadingTimeout = null;
            }
            isLoadingBulkAction.value = false;

            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to execute bulk action',
                life: 3000
            });
        }
    }

    /**
     * Execute bulk action in batches with progress tracking
     * Used for large republish/unpublish operations
     */
    async function executeBulkActionWithProgress(action, ids, useIdents, batchSize) {
        // Reset cancellation flag
        bulkActionCancelled.value = false;

        // Initialize progress tracking
        bulkActionProgress.value = {
            current: 0,
            total: ids.length,
            action: action,
            successCount: 0,
            errorCount: 0
        };

        // Show loading overlay immediately for large operations
        isLoadingBulkAction.value = true;

        let totalSuccessCount = 0;
        let totalErrorCount = 0;
        const allErrors = [];
        let wasCancelled = false;

        try {
            // Process in batches
            for (let i = 0; i < ids.length; i += batchSize) {
                // Check for cancellation before processing each batch
                if (bulkActionCancelled.value) {
                    wasCancelled = true;
                    console.log('Bulk action cancelled by user');
                    break;
                }

                const batch = ids.slice(i, i + batchSize);
                const batchNumber = Math.floor(i / batchSize) + 1;
                const totalBatches = Math.ceil(ids.length / batchSize);

                console.log(`Processing ${action} batch ${batchNumber}/${totalBatches} (${batch.length} items)`);

                // Build request payload
                const payload = { action: action };
                if (useIdents) {
                    payload.idents = batch;
                } else {
                    payload.sids = batch;
                }

                try {
                    const response = await axios.post('/api/submissions/bulk-action', payload, {
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    });

                    if (response.data.hasOwnProperty('status_code') && response.data.status_code == 200) {
                        totalSuccessCount += response.data.success_count || batch.length;
                        totalErrorCount += response.data.error_count || 0;
                        if (response.data.errors) {
                            allErrors.push(...response.data.errors);
                        }
                    } else {
                        // API returned an error
                        totalErrorCount += batch.length;
                        allErrors.push(response.data.message || 'Batch failed');
                    }
                } catch (batchError) {
                    console.error(`Batch ${batchNumber} failed:`, batchError);
                    totalErrorCount += batch.length;
                    allErrors.push(`Batch ${batchNumber} failed: ${batchError.message}`);
                }

                // Update progress
                bulkActionProgress.value.current = Math.min(i + batchSize, ids.length);
                bulkActionProgress.value.successCount = totalSuccessCount;
                bulkActionProgress.value.errorCount = totalErrorCount;

                // Small delay between batches to prevent overwhelming the server
                if (i + batchSize < ids.length) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }

            // Clear selection and reload
            selectedSubmissions.value = [];

            // Reset progress tracking
            bulkActionProgress.value = {
                current: 0,
                total: 0,
                action: '',
                successCount: 0,
                errorCount: 0
            };

            // Hide loading overlay
            isLoadingBulkAction.value = false;

            // Show appropriate completion message
            if (wasCancelled) {
                toast.add({
                    severity: 'warn',
                    summary: 'Operation Cancelled',
                    detail: `Cancelled after processing ${totalSuccessCount} of ${ids.length} submissions`,
                    life: 5000
                });
            } else if (totalErrorCount > 0) {
                toast.add({
                    severity: 'warn',
                    summary: 'Partially Complete',
                    detail: `${totalSuccessCount} succeeded, ${totalErrorCount} failed`,
                    life: 5000
                });
            }

            // Reload page data
            setTimeout(() => {
                router.reload();
            }, 500);

        } catch (error) {
            console.error('Bulk action with progress failed:', error);

            // Reset progress tracking
            bulkActionProgress.value = {
                current: 0,
                total: 0,
                action: '',
                successCount: 0,
                errorCount: 0
            };

            isLoadingBulkAction.value = false;

            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to execute bulk action',
                life: 3000
            });
        }
    }


    async function updateFavorite(ident, toggle) {
        // Optimistic update - update UI immediately for instant feedback
        const previousFavorites = [...localFavorites.value];

        if (toggle) {
            // Adding to favorites
            if (!localFavorites.value.includes(ident)) {
                localFavorites.value = [...localFavorites.value, ident];
            }
        } else {
            // Removing from favorites
            localFavorites.value = localFavorites.value.filter(fav => fav !== ident);

            // If we're removing a favorite and currently showing only favorites
            // Check if this was the last favorite, and if so, reset to "Show All"
            if (selectedDisplay.value === 'favorites' && localFavorites.value.length === 0) {
                selectedDisplay.value = 'all';
            }
        }

        try {
            // Use ident (not sid) to target the specific version
            const response = await axios.post('/api/submissions/' + ident, {
                type: 'favorites',
                value: toggle
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if (!(response.data.hasOwnProperty('status_code') && response.data.status_code == 200)) {
                // API didn't return success - revert optimistic update
                localFavorites.value = previousFavorites;
                toast.add({
                    severity: 'error',
                    summary: 'Error',
                    detail: 'Failed to update favorite status',
                    life: 3000
                });
            }
            // No reload needed - optimistic update already applied
        } catch (error) {
            // Revert optimistic update on error
            localFavorites.value = previousFavorites;
            console.error(error);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to update favorite status',
                life: 3000
            });
        }
    }


    function isEmpty(obj) {
        for (const prop in obj) {
            if (Object.hasOwn(obj, prop)) {
                return false;
            }
        }
        return true;
    }

    const dt = ref();

    function rowFilter(item)
    {
        // Status helpers using simplified statuses
        // Pending statuses: new, republish, unpublish (action-based)
        const pendingStatuses = ['new', 'republish', 'unpublish'];
        // Released statuses: published, unpublished (visibility-based)
        const releasedStatuses = ['published', 'unpublished'];

        const isPending = pendingStatuses.includes(item.status);
        const isReleased = releasedStatuses.includes(item.status);

        // Apply historic filter for released submissions
        // When showHistoric is false, only show live submissions (is_live=true)
        // This affects all filter modes when viewing released submissions
        const passesHistoricFilter = () => {
            if (!isReleased) return true; // Pending submissions always pass
            if (showHistoric.value) return true; // Show all when toggle is on
            return item.is_live === true; // Only live submissions when toggle is off
        };

        // User filter helper
        const passesUserFilter = () => {
            if (!filterUser.value) return true;
            return item.user_id == mine.value;
        };

        // Apply both user filter and historic filter
        const baseFilter = () => passesUserFilter() && passesHistoricFilter();

        switch (selectedDisplay.value) {
            case 'favorites':
                return baseFilter() && favorites.value.includes(item.ident);

            case 'errors':
                return baseFilter() && !isEmpty(item.submission_errors);

            case 'pending':
                // Show all pending submissions (new, republish, unpublish)
                return baseFilter() && isPending;

            case 'pending_new':
                // Show only new submissions
                return baseFilter() && item.status === 'new';

            case 'pending_republish':
                // Show only republish submissions
                return baseFilter() && item.status === 'republish';

            case 'pending_unpublish':
                // Show only unpublish submissions
                return baseFilter() && item.status === 'unpublish';

            case 'released':
                // Show all released submissions (published, unpublished)
                return baseFilter() && isReleased;

            case 'released_published':
                // Show only published submissions
                return baseFilter() && item.status === 'published';

            case 'released_unpublished':
                // Show only unpublished submissions
                return baseFilter() && item.status === 'unpublished';

            case 'all':
            default:
                // Show All
                return baseFilter();
        }
    }


    async function exportCSV(event)
    {
        // Get the filtered data - apply rowFilter first
        // Use submissionsWithStatusDate to match the DataTable source
        let filteredData = submissionsWithStatusDate.value?.filter(rowFilter) || [];

        // Apply global keyword search filter if present
        const globalFilter = filters.value.global?.value;
        if (globalFilter) {
            const searchLower = globalFilter.toLowerCase();
            filteredData = filteredData.filter(row => {
                // Search across all the globalFilterFields
                const searchableText = [
                    row.sid,
                    row.display_id,
                    row.friendly,
                    row.gene?.symbol,
                    row.gene?.hgnc_id,
                    row.disease?.name,
                    row.disease?.curie,
                    row.inheritance?.name,
                    row.inheritance?.curie,
                    row.classification?.name,
                    row.created_at
                ].filter(Boolean).join(' ').toLowerCase();

                return searchableText.includes(searchLower);
            });
        }

        if (filteredData.length === 0) {
            toast.add({
                severity: 'warn',
                summary: 'No Data',
                detail: 'No submissions to export.',
                life: 3000
            });
            return;
        }

        try {
            // Call backend API to generate Excel file with template formatting preserved
            // Get CSRF token from meta tag
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            // Get XSRF token from cookie (Laravel sets this)
            const xsrfCookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='));
            const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie.split('=')[1]) : '';

            const response = await fetch('/api/submissions/export-template', {
                method: 'POST',
                credentials: 'same-origin',  // Include cookies for session auth
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-XSRF-TOKEN': xsrfToken
                },
                body: JSON.stringify({
                    submissions: filteredData
                })
            });

            console.log('Export response status:', response.status);
            console.log('Export response ok:', response.ok);
            console.log('Export response headers:', Object.fromEntries(response.headers.entries()));

            if (!response.ok) {
                // Try to get error message from response
                const text = await response.text();
                console.error('Export error response:', text);
                let errorMessage = 'Export failed';
                try {
                    const errorData = JSON.parse(text);
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = text || errorMessage;
                }
                throw new Error(errorMessage);
            }

            // Download the file
            const blob = await response.blob();
            console.log('Blob size:', blob.size, 'type:', blob.type);
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);

            // Get filename from Content-Disposition header or use default
            const contentDisposition = response.headers.get('Content-Disposition');
            let filename = 'submissions_export.xlsx';
            if (contentDisposition) {
                const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(contentDisposition);
                if (matches && matches[1]) {
                    filename = matches[1].replace(/['"]/g, '');
                }
            }

            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

        } catch (error) {
            console.error('Error exporting to xlsx:', error);
            toast.add({
                severity: 'error',
                summary: 'Export Failed',
                detail: error.message || 'Failed to export submissions. Please try again.',
                life: 5000
            });
        }
    }

    function rowStyle(data)
    {
        // Gray background for archived submissions (released but superseded by a newer release)
        // is_archived is computed by the backend: released status + is_live=false
        if (data.is_archived === true) {
            return { class: 'row-not-current' };
        }
        return null;
    }

    function displayStatus(status) {
        switch (status) {
            case 0:
                return 'Initializing';
	 		case 1:
                return 'Newly Created';
	 		case 3:
                return 'Processing';
            case 4:
                return 'Errors';
	 		case 9:
                return 'Removed';
            case 20:
                return 'Published';
        }
    }

    // V2 status display with better terminology
    // With simplified status model: new, republish, unpublish (pending) and published, unpublished (released)
    function displayStatusV2(status) {
        const statusMap = {
            // New simplified statuses
            'new': 'New',
            'republish': 'Republish',
            'unpublish': 'Unpublish',
            'published': 'Published',
            'unpublished': 'Unpublished',
            // Legacy compound statuses (for backwards compatibility)
            'draft_new': 'New',
            'submitted_new': 'New',
            'draft_republish': 'Republish',
            'submitted_republish': 'Republish',
            'draft_unpublish': 'Unpublish',
            'submitted_unpublish': 'Unpublish'
        };
        return statusMap[status] || status;
    }

    // Get severity for status badge with custom classes for shades
    // Stage (draft/submitted) is now derived from job.status
    function getStatusSeverity(status, jobStatus = null) {
        const isSubmittedJob = jobStatus === 'submitted';

        const severityMap = {
            // New simplified statuses - use job status for color
            'new': isSubmittedJob ? 'info' : 'warning',
            'republish': isSubmittedJob ? 'info' : 'warning',
            'unpublish': isSubmittedJob ? 'orange' : 'amber',
            'published': 'success',
            'unpublished': 'danger',
            // Legacy compound statuses
            'draft_new': 'warning',
            'submitted_new': 'info',
            'draft_republish': 'warning',
            'submitted_republish': 'info',
            'draft_unpublish': 'amber',
            'submitted_unpublish': 'orange'
        };
        return severityMap[status] || 'secondary';
    }

    // Get custom CSS class for status badges with shades
    // Archived submissions (is_archived=true) get gray styling
    function getStatusClass(status, isArchived = false, jobStatus = null) {
        // Archived submissions get gray styling (released but superseded by newer release)
        if (isArchived) {
            return 'status-not-current';
        }

        const isSubmittedJob = jobStatus === 'submitted';

        const classMap = {
            // New simplified statuses - class based on job status
            'new': isSubmittedJob ? 'status-submitted-new' : 'status-draft-new',
            'republish': isSubmittedJob ? 'status-submitted-republish' : 'status-draft-republish',
            'unpublish': isSubmittedJob ? 'status-submitted-unpublish' : 'status-draft-unpublish',
            'published': 'status-published',
            'unpublished': 'status-unpublished',
            // Legacy compound statuses
            'draft_new': 'status-draft-new',
            'submitted_new': 'status-submitted-new',
            'draft_republish': 'status-draft-republish',
            'submitted_republish': 'status-submitted-republish',
            'draft_unpublish': 'status-draft-unpublish',
            'submitted_unpublish': 'status-submitted-unpublish'
        };
        return classMap[status] || '';
    }

    // Get the status date based on submission status
    // Draft states: created_at, Submitted states: submitted_at, Published/Unpublished: released_at
    function getStatusDate(submission) {
        if (!submission.status) return submission.created_at;

        let dateStr = null;
        const isSubmittedJob = submission.job?.status === 'submitted';

        switch (submission.status) {
            // New simplified pending statuses
            case 'new':
            case 'republish':
            case 'unpublish':
                dateStr = isSubmittedJob ? (submission.submitted_at || submission.created_at) : submission.created_at;
                break;
            // Legacy compound statuses
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

    // Get raw status date for sorting (returns Date object or null)
    function getStatusDateRaw(submission) {
        const dateStr = getStatusDate(submission);
        if (!dateStr) return null;
        return new Date(Date.parse(dateStr));
    }

    function getDiseaseDeprecationTooltip(disease) {
        if (!disease || disease.status !== 8) {
            return 'DEPRECATED: This disease term is deprecated';
        }

        if (disease.deprecated_name) {
            return `DEPRECATED: ${disease.deprecated_name}`;
        }

        return 'DEPRECATED: This disease term is deprecated';
    }
</script>

<style>
table tbody tr:hover {
    background: #F8F8F8;
}

.status-tag {
    min-width: 100px;
    max-width: 100px;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    white-space: normal;
    text-align: center;
    line-height: 1.2;
}

/* Draft New - Yellow */
.status-draft-new {
    background-color: #FEF08A !important;  /* Tailwind yellow-200 */
    color: #713F12 !important;             /* Tailwind yellow-950 */
    border-color: #FACC15 !important;      /* Tailwind yellow-400 */
}

/* Draft Republish - Yellow */
.status-draft-republish {
    background-color: #FEF08A !important;  /* Tailwind yellow-200 */
    color: #713F12 !important;             /* Tailwind yellow-950 */
    border-color: #FACC15 !important;      /* Tailwind yellow-400 */
}

/* Draft Unpublish - Amber */
.status-draft-unpublish {
    background-color: #FDE68A !important;  /* Tailwind amber-200 */
    color: #78350F !important;             /* Tailwind amber-950 */
    border-color: #FBBF24 !important;      /* Tailwind amber-400 */
}

/* Submitted New - Blue */
.status-submitted-new {
    background-color: #BFDBFE !important;  /* Tailwind blue-200 */
    color: #1E3A8A !important;             /* Tailwind blue-900 */
    border-color: #60A5FA !important;      /* Tailwind blue-400 */
}

/* Submitted Republish - Blue */
.status-submitted-republish {
    background-color: #BFDBFE !important;  /* Tailwind blue-200 */
    color: #1E3A8A !important;             /* Tailwind blue-900 */
    border-color: #60A5FA !important;      /* Tailwind blue-400 */
}

/* Submitted Unpublish - Orange */
.status-submitted-unpublish {
    background-color: #FED7AA !important;  /* Tailwind orange-200 */
    color: #7C2D12 !important;             /* Tailwind orange-950 */
    border-color: #FB923C !important;      /* Tailwind orange-400 */
}

/* Published - Green */
.status-published {
    background-color: #86EFAC !important;  /* Tailwind green-300 */
    color: #14532D !important;             /* Tailwind green-950 */
    border-color: #4ADE80 !important;      /* Tailwind green-400 */
}

/* Unpublished - Red */
.status-unpublished {
    background-color: #FECACA !important;  /* Tailwind red-200 */
    color: #7F1D1D !important;             /* Tailwind red-950 */
    border-color: #F87171 !important;      /* Tailwind red-400 */
}

/* Non-most-recent versions - Gray (historical versions) */
.status-not-current {
    background-color: #E5E7EB !important;  /* Tailwind gray-200 */
    color: #4B5563 !important;             /* Tailwind gray-600 */
    border-color: #9CA3AF !important;      /* Tailwind gray-400 */
}

/* Row styling for non-most-recent (historical) versions */
.row-not-current {
    background-color: #F9FAFB !important;  /* Tailwind gray-50 */
}

.row-not-current td {
    color: #6B7280 !important;             /* Tailwind gray-500 */
}

/* Reduce DataTable cell padding for more compact layout */
:deep(.p-datatable .p-datatable-tbody > tr > td) {
    padding: 0.5rem 0.5rem !important;     /* Reduced from default 1rem */
}

:deep(.p-datatable .p-datatable-thead > tr > th) {
    padding: 0.5rem 0.5rem !important;     /* Reduced from default 1rem */
}

/* Prevent status date from wrapping */
:deep(.p-datatable .p-datatable-tbody > tr > td:last-child),
:deep(.p-datatable .p-datatable-tbody > tr > td:nth-last-child(2)) {
    white-space: nowrap;
}

</style>

<template>
    <div>
        <!-- Loading overlay for bulk action reload -->
        <div v-if="isLoadingBulkAction" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-8 max-w-md text-center shadow-2xl">
                <i class="pi pi-spin pi-spinner text-blue-500 text-6xl mb-4"></i>
                <h3 class="text-xl font-bold text-gray-900 mb-2">
                    {{ bulkActionProgress.total > 0 ? `Processing ${bulkActionProgress.action === 'republish' ? 'Republish' : bulkActionProgress.action === 'unpublish' ? 'Unpublish' : 'Action'}` : 'Processing Action' }}
                </h3>

                <!-- Progress display for large operations -->
                <template v-if="bulkActionProgress.total > 0">
                    <div class="mb-4">
                        <div class="text-2xl font-bold text-blue-600 mb-1">
                            {{ bulkActionProgress.current }} / {{ bulkActionProgress.total }}
                        </div>
                        <div class="text-sm text-gray-500">
                            submissions processed
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="w-full bg-gray-200 rounded-full h-3 mb-4">
                        <div
                            class="bg-blue-500 h-3 rounded-full transition-all duration-300"
                            :style="{ width: `${Math.round((bulkActionProgress.current / bulkActionProgress.total) * 100)}%` }">
                        </div>
                    </div>

                    <!-- Success/Error counts -->
                    <div v-if="bulkActionProgress.successCount > 0 || bulkActionProgress.errorCount > 0" class="flex justify-center gap-4 text-sm mb-4">
                        <span class="text-green-600">
                            <i class="pi pi-check-circle mr-1"></i>{{ bulkActionProgress.successCount }} succeeded
                        </span>
                        <span v-if="bulkActionProgress.errorCount > 0" class="text-red-600">
                            <i class="pi pi-times-circle mr-1"></i>{{ bulkActionProgress.errorCount }} failed
                        </span>
                    </div>

                    <!-- Cancel button -->
                    <Button
                        label="Cancel Operation"
                        icon="pi pi-times"
                        severity="secondary"
                        outlined
                        @click="cancelBulkAction"
                        class="mt-2" />
                    <p class="text-xs text-gray-400 mt-2">
                        Canceling will stop processing. Items already processed will remain changed.
                    </p>
                </template>

                <!-- Simple message for small/fast operations -->
                <template v-else>
                    <p class="text-gray-600">
                        Please wait while we update the submissions. This may take a moment for larger sets of submissions.
                    </p>
                </template>
            </div>
        </div>

        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <div v-if="errors" class="bg-amber-100 border-l-4 border-amber-700 text-amber-800 p-4 mt-2" role="alert">
                <p class="font-bold">There are submission errors present</p>
                <p>
                    Submissions with errors are marked with a red warning icon.  Click on the edit link to resolve any errors.
                    Once corrected, the submission will automatically become eligible for publishing.
                </p>
            </div>

            <div v-if="props.hasSubmittedJob" class="bg-blue-100 border-l-4 border-blue-700 text-blue-800 p-4 mt-2" role="alert">
                <p class="font-bold">A submitted job exists.</p>
                <p>
                    The submitted job is awaiting processing. Published submissions cannot be edited or unpublished until the current job is processed.
                </p>
            </div>

            <ConfirmDialog group="headless">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <!-- Red theme for Unpublish and Delete, Purple for Favorite/Unfavorite, Blue for others -->
                        <div v-if="message.header && (message.header.includes('Unpublish') || message.header.includes('Delete') || message.acceptLabel?.includes('Delete'))" class="rounded-full bg-red-700 dark:bg-red-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-exclamation-triangle text-5xl"></i>
                        </div>
                        <div v-else-if="message.header && (message.header.includes('Favorite') || message.header.includes('Unfavorite'))" class="rounded-full bg-purple-700 dark:bg-purple-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-star text-5xl"></i>
                        </div>
                        <div v-else class="rounded-full bg-blue-700 dark:bg-blue-600 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-pencil text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-2 mt-4">
                            <Button v-if="message.header && (message.header.includes('Unpublish') || message.header.includes('Delete') || message.acceptLabel?.includes('Delete'))" :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-red-700 !ring-red-700 hover:!bg-red-800"></Button>
                            <Button v-else-if="message.header && (message.header.includes('Favorite') || message.header.includes('Unfavorite'))" :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-purple-700 !ring-purple-700 hover:!bg-purple-800"></Button>
                            <Button v-else :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-blue-700 !ring-blue-700 hover:!bg-blue-800"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>
            <Toast />

            <DataTable v-model:filters="filters" v-model:selection="selectedSubmissions" ref="dt" :value="submissionsWithStatusDate?.filter(rowFilter)" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100, 250]" sortField="status_date" :sortOrder="-1"
                    :rowStyle="rowStyle" :globalFilterFields="['sid', 'display_id', 'friendly', 'gene.symbol', 'gene.hgnc_id', 'disease.name', 'disease.curie', 'inheritance.name', 'inheritance.curie', 'classification.name', 'created_at']" tableStyle="min-width: 20rem; width: auto;"
                    dataKey="ident">
                <template #header>
                    <!-- Bulk Action Toolbar -->
                    <div v-if="selectedSubmissions.length > 0" class="bg-yellow-100 border border-yellow-500 rounded-md p-4 mb-4">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-yellow-700">
                                {{ selectedSubmissions.length }} submission(s) selected
                            </span>
                            <div class="flex gap-2">
                                <!-- Republish: Available when all selected are most-recent published AND/OR unpublished -->
                                <Button
                                    v-if="bulkRepublishAvailable"
                                    label="Republish Selected"
                                    icon="pi pi-refresh"
                                    @click="bulkRepublishSubmissions"
                                    severity="info"
                                    outlined
                                    raised />

                                <!-- Unpublish: Available when all selected are most-recent published only -->
                                <Button
                                    v-if="bulkUnpublishAvailable"
                                    label="Unpublish Selected"
                                    icon="pi pi-eye-slash"
                                    @click="bulkUnpublishSubmissions"
                                    severity="warning"
                                    outlined
                                    raised />

                                <!-- Delete: Only available when all selected are draft submissions -->
                                <Button
                                    v-if="bulkDeleteAvailable"
                                    label="Delete Selected"
                                    icon="pi pi-trash"
                                    @click="bulkDeleteSubmissions"
                                    severity="danger"
                                    outlined
                                    raised />

                                <!-- Favorite: Only available when ALL selected are NOT already favorited -->
                                <Button
                                    v-if="bulkFavoriteAction === 'favorite'"
                                    label="Favorite Selected"
                                    icon="pi pi-star"
                                    @click="bulkToggleFavorites"
                                    severity="help"
                                    outlined
                                    raised />

                                <!-- Unfavorite: Only available when ALL selected ARE already favorited -->
                                <Button
                                    v-if="bulkFavoriteAction === 'unfavorite'"
                                    label="Unfavorite Selected"
                                    icon="pi pi-star-fill"
                                    @click="bulkToggleFavorites"
                                    severity="help"
                                    outlined
                                    raised />

                                <Button label="Clear Selection" icon="pi pi-times" @click="selectedSubmissions = []" severity="secondary" text />
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <Button icon="pi pi-download"
                                    label="Download"
                                    @click="exportCSV($event)"
                                    severity="success"
                                    raised
                                    :disabled="filteredSubmissionsCount === 0" />
                            <span class="ml-3 text-sm text-gray-600">
                                <template v-if="filteredSubmissionsCount < (submissions ? submissions.length : 0)">
                                    Showing {{ filteredSubmissionsCount }} of {{ submissions ? submissions.length : 0 }} submissions.
                                </template>
                                <template v-else>
                                    Total of {{ submissions ? submissions.length : 0 }} submissions.
                                </template>
                            </span>
                        </span>
                        <div class="text-left flex gap-2">
                            <Dropdown v-model="selectedDisplay" :options="displayOptions" optionLabel="name" optionValue="option" placeholder="Display" class="w-48">
                                <template #option="slotProps">
                                    <div :class="{ 'pl-4': slotProps.option.isChild, 'font-semibold': slotProps.option.isParent }">
                                        {{ slotProps.option.name }}
                                    </div>
                                </template>
                            </Dropdown>
                            <ToggleButton
                                v-if="showHistoricToggle"
                                v-model="showHistoric"
                                onLabel="Historic"
                                offLabel="Historic"
                                onIcon="pi pi-eye"
                                offIcon="pi pi-eye-slash"
                                class="w-32"
                                v-tooltip.bottom="historicToggleTooltip"
                                :aria-label="historicToggleTooltip" />
                            <ToggleButton v-model="filterUser" onLabel="User Only" offLabel="Submitter" onIcon="pi pi-user" offIcon="pi pi-sitemap" class="w-32" aria-label="Do you confirm" />
                        </div>
                        <IconField iconPosition="left">
                            <InputIcon>
                                <i class="pi pi-search" />
                            </InputIcon>
                            <InputText v-model="filters['global'].value" placeholder="Keyword Search" />
                        </IconField>
                    </div>
                </template>
                <Column selectionMode="multiple" headerStyle="width: 3rem" :exportable="false"></Column>
                <Column field="ident" header="">
                     <template #body="{ data }">
                        <div v-if="favorites.includes(data.ident)" class="text-orange-300 text-xl" @click="updateFavorite(data.ident, false)"><i class="pi pi-star-fill" ></i></div>
                        <div v-else class="text-slate-300 text-xl" @click="updateFavorite(data.ident, true)"><i class="pi pi-star"></i></div>
                    </template>
                </Column>
                <Column field="sid" header="Submission" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" class="p-column-filter" placeholder="Search by name" />
                     </template>-->
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.display_id || data.sid }}</div>
                        <div v-if="data.job?.ident" class="text-xs">
                            <a :href="'/jobs/' + data.job.ident"
                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                {{ data.job.slug }}
                            </a>
                        </div>
                    </template>
                </Column>
                <Column field="gene.symbol" header="Gene" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" :filterFields="['gene.symbol', 'gene.hgnc_id']" class="p-column-filter" placeholder="Search by name" />
                     </template>>-->
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.gene?.symbol || '-' }}</div>
                        <div class="text-xs">
                            <a v-if="data.gene?.hgnc_id"
                               :href="getGeneUrl(data.gene.hgnc_id)"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                {{ data.gene.hgnc_id }}
                            </a>
                            <span v-else>{{ data.gene?.hgnc_id || '' }}</span>
                        </div>
                    </template>
                </Column>
                <Column field="disease.name" header="Disease" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" class="p-column-filter" placeholder="Search by name" />
                     </template>-->
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.disease?.name || '-' }}</div>
                        <div class="text-xs text-gray-500">
                            <a v-if="data.disease?.curie && getDiseaseUrl(data.disease.curie)"
                               :href="getDiseaseUrl(data.disease.curie)"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                {{ data.disease.curie }}
                            </a>
                            <span v-else>{{ data.disease?.curie || '' }}</span>
                            <span v-if="data.disease?.status === 8" class="text-amber-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(data.disease)">⚠</span>
                        </div>
                        <div v-if="data.submission_data?.disease?.id && data.disease?.curie !== data.submission_data.disease.id"
                             class="text-xs text-gray-500">
                            Submitted as:
                            <a v-if="getDiseaseUrl(data.submission_data.disease.id)"
                               :href="getDiseaseUrl(data.submission_data.disease.id)"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                {{ data.submission_data.disease.id }}
                            </a>
                            <span v-else>{{ data.submission_data.disease.id }}</span>
                            <span v-if="data.original_disease?.status === 8" class="text-amber-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(data.original_disease)">⚠</span>
                        </div>
                    </template>
                </Column>
                <Column field="inheritance.name" header="Inheritance" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" class="p-column-filter" placeholder="Search by name" />
                     </template>-->
                   <!-- <template #filter="{ filterModel, filterCallback }">
                        <MultiSelect v-model="filterModel.value" @change="filterCallback()" :options="options" optionLabel="label" placeholder="Any" class="p-column-filter" style="min-width: 14rem" :maxSelectedLabels="1">
                            <template #option="slotProps">
                                <div class="flex align-items-center gap-2">
                                    <span>{{ slotProps.option.label }}</span>
                                </div>
                            </template>
                        </MultiSelect>
                    </template>-->
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.inheritance?.name || '-' }}</div>
                        <div class="text-xs">{{ data.inheritance?.curie || '' }}</div>
                    </template>
                </Column>
                <Column field="classification.name" header="Classification" sortable>
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.classification?.name || '-' }}</div>
                        <div class="text-xs">{{ data.classification?.curie || '' }}</div>
                    </template>
                </Column>
                <Column field="status_date" header="Status Date" sortable style="min-width: 6rem; white-space: nowrap;">
                     <template #body="{ data }">
                        {{ formatDateTimestamp(getStatusDate(data)) }}
                     </template>
                </Column>
                <Column field="status" header="Status" sortable>
                     <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <Tag v-if="data.status" :value="displayStatusV2(data.status)" :severity="getStatusSeverity(data.status)" :class="['status-tag', getStatusClass(data.status, data.is_archived)]" />
                            <span v-else>{{ displayStatus(data.status) }}</span>
                            <i v-if="data.submission_errors && Object.keys(data.submission_errors).length > 0"
                               class="pi pi-exclamation-triangle text-red-500 text-xl"
                               title="Submission has errors"></i>
                            <!-- Indicator for archived versions (superseded by newer release) -->
                            <i v-if="data.is_archived"
                               class="pi pi-history text-gray-400"
                               v-tooltip.top="'Archived (superseded by newer release)'"></i>
                        </div>
                     </template>
                </Column>
                <Column header="Action" style="width: 10%; min-width: 8rem" headerStyle="width: 5rem; text-align: center" bodyStyle="text-align: center; overflow: visible">
                    <template #body="slotProps">
                        <!-- Archived versions (superseded by newer release): Only show View button (no other actions) -->
                        <template v-if="slotProps.data.is_archived">
                            <Button type="button" icon="pi pi-arrow-right" text raised rounded
                                    v-tooltip.top="'View (archived version)'"
                                    @click="router.visit('/submissions/' + slotProps.data.ident)" />
                        </template>

                        <!-- Active versions: Show all applicable actions -->
                        <template v-else>
                            <!-- V2 Status Actions -->
                            <template v-if="slotProps.data.status">
                                <!-- Published: Show Republish (Edit) and Unpublish buttons
                                     Only if:
                                     - No submitted job exists
                                     - Submission is live (is_live=true)
                                     - Submission is most recent (is_most_recent=true) meaning no pending draft version
                                -->
                                <span v-if="slotProps.data.status === 'published' && !props.hasSubmittedJob && slotProps.data.is_live === true && slotProps.data.is_most_recent !== false" class="mr-3">
                                    <Button @click="requireRepublishConfirmation(slotProps.data.sid)" icon="pi pi-refresh" severity="info" text raised rounded v-tooltip.top="'Republish'"></Button>
                                </span>
                                <span v-if="slotProps.data.status === 'published' && !props.hasSubmittedJob && slotProps.data.is_live === true && slotProps.data.is_most_recent !== false" class="mr-3">
                                    <Button @click="requireUnpublishConfirmationV2(slotProps.data.sid)" icon="pi pi-eye-slash" severity="warning" text raised rounded v-tooltip.top="'Unpublish'"></Button>
                                </span>

                                <!-- Pending submissions in draft job: Show Delete button -->
                                <!-- Republish/Unpublish drafts: Cancel the draft (original remains) -->
                                <span v-if="(slotProps.data.status === 'republish' || slotProps.data.status === 'unpublish') && slotProps.data.job?.status === 'draft'" class="mr-3">
                                    <Button @click="requireDeleteDraftConfirmation(slotProps.data.sid)" icon="pi pi-trash" severity="danger" text raised rounded v-tooltip.top="'Delete draft'"></Button>
                                </span>

                                <!-- New submissions in draft job: Delete the submission -->
                                <span v-if="slotProps.data.status === 'new' && slotProps.data.job?.status === 'draft'" class="mr-3">
                                    <Button @click="requireConfirmation(slotProps.data.sid)" icon="pi pi-trash" severity="danger" text raised rounded v-tooltip.top="'Delete'"></Button>
                                </span>

                                <!-- Unpublished: Show Republish button
                                     Only if:
                                     - Submission is most recent (is_most_recent=true) meaning no pending draft version
                                -->
                                <span v-if="slotProps.data.status === 'unpublished' && slotProps.data.is_most_recent !== false" class="mr-3">
                                    <Button @click="requireRepublishConfirmation(slotProps.data.sid)" icon="pi pi-refresh" severity="success" text raised rounded v-tooltip.top="'Republish'"></Button>
                                </span>
                            </template>

                            <!-- Legacy Status Actions (fallback) -->
                            <template v-else>
                                <span v-if="(slotProps.data.job.status == 2 || slotProps.data.job.status == 4) && slotProps.data.status != 20 && !slotProps.data.publish_date" class="mr-3">
                                    <Button @click="requireConfirmation(slotProps.data.sid)" icon="pi pi-trash" severity="danger" text raised rounded></Button>
                                </span>
                                <span v-if="(slotProps.data.job.status == 2 || slotProps.data.job.status == 3 || slotProps.data.job.status == 4) && slotProps.data.status == 20" class="mr-3">
                                    <Button @click="requireUnpublishConfirmation(slotProps.data.sid)" icon="pi pi-eye-slash" severity="warning" text raised rounded v-tooltip.top="'Unpublish'"></Button>
                                </span>
                                <span v-if="slotProps.data.status == 3 && slotProps.data.origin_state !== null" class="mr-3">
                                    <Button @click="requireDeleteDraftConfirmation(slotProps.data.sid)" icon="pi pi-trash" severity="danger" text raised rounded v-tooltip.top="'Delete draft'"></Button>
                                </span>
                            </template>

                            <!-- View/Edit button (always shown for current versions) -->
                            <Button type="button" icon="pi pi-arrow-right" text raised rounded
                                    v-tooltip.top="(slotProps.data.status === 'new' || slotProps.data.status === 'republish') && slotProps.data.job?.status === 'draft' ? 'View/Edit' : 'View'"
                                    @click="router.visit('/submissions/' + slotProps.data.ident)" />
                        </template>
                    </template>
                 </Column>
                 <template #footer>
                    <template v-if="filteredSubmissionsCount < (submissions ? submissions.length : 0)">
                        Showing {{ filteredSubmissionsCount }} of {{ submissions ? submissions.length : 0 }} submissions.
                    </template>
                    <template v-else>
                        Total of {{ submissions ? submissions.length : 0 }} submissions.
                    </template>
                 </template>
            </DataTable>
        </div>

    </div>
</template>
