<script setup>


    import { ref, onMounted, onUnmounted } from 'vue'
    import { computed } from 'vue'
    import { router } from '@inertiajs/vue3'
    import { usePage } from '@inertiajs/vue3';
    import { FilterMatchMode, FilterOperator } from "primevue/api";
    import ConfirmDialog from 'primevue/confirmdialog';
    import { useConfirm } from "primevue/useconfirm";
    import { useToast } from "primevue/usetoast";
    import ToggleButton from 'primevue/togglebutton';
    import Tag from 'primevue/tag';
    import { getDiseaseUrl, getGeneUrl } from '@/utils/externalLinks';


    const props = defineProps(['submissions', 'errors', 'favorites', 'hasSubmittedJob'])

    console.log('SubmissionsListing hasSubmittedJob prop:', props.hasSubmittedJob, 'type:', typeof props.hasSubmittedJob)

    const page = usePage()

    const mine = computed(() => page.props.mine)

    // Ensure favorites is always an array (handle case where it might be an empty object from DB)
    const favorites = computed(() => {
        if (!props.favorites) return [];
        if (Array.isArray(props.favorites)) return props.favorites;
        // If it's an object (like empty {}), convert to array
        return Object.values(props.favorites);
    })

    const confirm = useConfirm();
    const toast = useToast();


    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS },
        sid: { value: null, matchMode: FilterMatchMode.CONTAINS },
        'gene.symbol': { value: null, matchMode: FilterMatchMode.CONTAINS },
        'disease.name': { value: null, matchMode: FilterMatchMode.CONTAINS },
        'inheritance.name': { value: null, matchMode: FilterMatchMode.CONTAINS },
        submission_date: { value: null, matchMode: FilterMatchMode.CONTAINS }
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

    const displayOptions = ref([
        { name: 'Show All', option: '1' },
        { name: 'Show New', option: '2' },
        { name: 'Show Errors', option: '3' },
        { name: 'Show Published', option: '4' },
        { name: 'Show Favorites', option: '6' },
        { name: 'Show All Drafts', option: '7' },
        { name: 'Show Unpublished', option: '8' },
        { name: 'Show Republished', option: '9' }
    ]);

    const filterUser = defineModel(false);

    filterUser.value = false;

    const selectedDisplay = ref('1');

    // Bulk selection state
    const selectedSubmissions = ref([]);
    const isLoadingBulkAction = ref(false); // Loading state for bulk action reload

    // Computed properties for bulk actions
    const bulkActionAvailable = computed(() => {
        if (selectedSubmissions.value.length === 0) return null;

        // Get unique statuses from selected submissions
        const statuses = [...new Set(selectedSubmissions.value.map(s => s.status))];

        // Special case: draft_republish and draft_unpublish can both be restored together
        const allDraftForRestore = statuses.every(s => s === 'draft_republish' || s === 'draft_unpublish');
        if (allDraftForRestore) {
            return { action: 'restore', status: 'draft' }; // Can restore both types
        }

        // For all other cases, require exact same status
        if (statuses.length !== 1) return null;

        const status = statuses[0];

        // Determine available action based on status
        if (status === 'published' && !props.hasSubmittedJob) {
            return { action: 'multiple', status: 'published' }; // Can republish or unpublish
        } else if (status === 'draft_new') {
            return { action: 'delete', status: status }; // Can delete
        } else if (status === 'unpublished') {
            return { action: 'republish', status: status }; // Can republish
        }

        return null;
    });

    // Determine if we should show "Favorite" or "Unfavorite" for bulk action
    // If ALL selected submissions are already favorited, show "Unfavorite", otherwise "Favorite"
    const bulkFavoriteAction = computed(() => {
        if (selectedSubmissions.value.length === 0) return null;

        const allFavorited = selectedSubmissions.value.every(s => favorites.value.includes(s.ident));
        return allFavorited ? 'unfavorite' : 'favorite';
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
        const globalFilterFields = ['sid', 'friendly', 'gene.symbol', 'gene.hgnc_id', 'disease.name', 'disease.curie', 'inheritance.name', 'inheritance.curie', 'classification.name', 'submission_date'];

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
                        toast.add({ severity: 'success', summary: 'Confirmed', detail: 'Submission Removed', life: 3000 });
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

    const requireCancelUpdateConfirmation = (sid) => {
        // Find the submission to get original job info
        const submission = props.submissions.find(s => s.sid === sid);

        // Build message with origin job ID if available
        let message = 'This will discard all your recent edits and restore the submission to its previously published state.';

        if (submission?.origin_job) {
            message += ` The submission will be moved back to its origin job (${submission.origin_job.slug}).`;
        } else {
            message += ' The submission will be moved back to its origin job.';
        }

        confirm.require({
            group: 'headless',
            header: 'Restore Submission?',
            message: message,
            acceptLabel: 'Restore',
            rejectLabel: 'Cancel',
            accept: () => {
                cancelUpdateSubmission(sid);
            },
            reject: () => {
                //
            }
        });
    };

    function cancelUpdateSubmission(sid) {
        if (sid != '') {
            axios.post('/api/submissions/' + sid, {
                type: 'cancel_update',
                value: true
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            })
            .then(response => {
                if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
                {
                    router.reload();
                    toast.add({ severity: 'success', summary: 'Updates Canceled', detail: 'Submission restored to ' + response.data.origin_job, life: 5000 });
                    return true;
                }
                else
                {
                    console.log(response);
                    toast.add({ severity: 'error', summary: 'Error', detail: response.data.message, life: 3000 });
                }
            })
            .catch(error => {
                console.error(error);
                toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to cancel updates', life: 3000 });
            });
        }

        return false;
    }

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
                    toast.add({ severity: 'success', summary: 'Operation Canceled', detail: 'Submission restored to ' + response.data.status, life: 3000 });
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

    const requireCancelConfirmation = (sid) => {
        // Find the submission to get original job info
        const submission = props.submissions.find(s => s.sid === sid);

        // Build message with origin job ID if available
        let message = 'This will discard all your recent edits and restore the submission to its previously published state.';

        if (submission?.origin_job) {
            message += ` The submission will be moved back to its origin job (${submission.origin_job.slug}).`;
        } else {
            message += ' The submission will be moved back to its origin job.';
        }

        confirm.require({
            group: 'headless',
            header: 'Restore Submission?',
            message: message,
            acceptLabel: 'Restore',
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
        const sids = selectedSubmissions.value.map(s => s.sid);
        confirm.require({
            group: 'headless',
            header: 'Edit Multiple Submissions?',
            message: `This will create draft copies for editing ${sids.length} submission(s). The current published versions will remain visible until changes are submitted and published.`,
            acceptLabel: 'Continue',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('republish', sids);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkUnpublishSubmissions() {
        const sids = selectedSubmissions.value.map(s => s.sid);
        confirm.require({
            group: 'headless',
            header: 'Unpublish Multiple Submissions?',
            message: `${sids.length} submission(s) will be added to a draft job as requests to remove them from public view. The submissions will be removed once the job is submitted and processed.`,
            acceptLabel: 'Unpublish',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('unpublish', sids);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkRestoreSubmissions() {
        const sids = selectedSubmissions.value.map(s => s.sid);
        confirm.require({
            group: 'headless',
            header: 'Restore Multiple Submissions?',
            message: `This will discard all recent edits and restore ${sids.length} submission(s) to their previously published state.`,
            acceptLabel: 'Restore',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('restore', sids);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkDeleteSubmissions() {
        const sids = selectedSubmissions.value.map(s => s.sid);
        confirm.require({
            group: 'headless',
            header: 'Delete Multiple Submissions?',
            message: `Are you sure you want to delete ${sids.length} submission(s)?`,
            acceptLabel: 'Delete',
            rejectLabel: 'Cancel',
            accept: () => {
                executeBulkAction('delete', sids);
            },
            reject: () => {
                //
            }
        });
    }

    function bulkToggleFavorites() {
        const action = bulkFavoriteAction.value; // 'favorite' or 'unfavorite'
        const sids = selectedSubmissions.value.map(s => s.sid);

        console.log('bulkToggleFavorites called', { action, count: sids.length, sids });

        // Show confirmation dialog
        confirm.require({
            group: 'headless',
            message: `Are you sure you want to ${action} ${sids.length} submission(s)?`,
            header: `Confirm ${action === 'favorite' ? 'Favorite' : 'Unfavorite'}`,
            icon: 'pi pi-exclamation-triangle',
            rejectClass: 'p-button-secondary p-button-outlined',
            rejectLabel: 'Cancel',
            acceptLabel: 'Continue',
            accept: async () => {
                console.log('Confirm accept callback triggered');

                // Show loading overlay only if operation takes longer than 2 seconds
                const loadingTimeout = setTimeout(() => {
                    isLoadingBulkAction.value = true;
                }, 2000);

                window.bulkActionLoadingTimeout = loadingTimeout;

                console.log('Starting API calls for', sids.length, 'submissions');

                // Call the individual favorite endpoint for each submission
                const promises = sids.map(sid =>
                    axios.post(`/api/submissions/${sid}`, {
                        type: 'favorites',
                        value: action === 'favorite' ? 'true' : 'false'
                    }, {
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        }
                    }).catch(err => ({ error: true, sid, message: err.message }))
                );

                const results = await Promise.all(promises);

                // Count successes and failures
                const failures = results.filter(r => r && r.error);
                const successes = results.length - failures.length;

                console.log(`Completed: ${successes} succeeded, ${failures.length} failed`);

                // Reload only the favorites prop
                selectedSubmissions.value = []; // Clear selection
                router.reload({
                    only: ['favorites'],
                    preserveScroll: true
                });

                // Show appropriate message
                if (failures.length === 0) {
                    toast.add({
                        severity: 'success',
                        summary: 'Success',
                        detail: `${successes} submission(s) ${action === 'favorite' ? 'favorited' : 'unfavorited'}`,
                        life: 3000
                    });
                } else if (successes > 0) {
                    toast.add({
                        severity: 'warn',
                        summary: 'Partially Complete',
                        detail: `${successes} submission(s) updated, ${failures.length} failed`,
                        life: 5000
                    });
                } else {
                    toast.add({
                        severity: 'error',
                        summary: 'Error',
                        detail: 'Failed to update favorites',
                        life: 3000
                    });
                }
            }
        });
    }

    async function executeBulkAction(action, sids) {
        // Show loading overlay only if operation takes longer than 2 seconds
        const loadingTimeout = setTimeout(() => {
            isLoadingBulkAction.value = true;
        }, 2000);

        window.bulkActionLoadingTimeout = loadingTimeout;

        try {
            const response = await axios.post('/api/submissions/bulk-action', {
                action: action,
                sids: sids
            }, {
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


    async function updateFavorite(sid, toggle) {

        try {
            const response = await axios.post('/api/submissions/' + sid, {
                type: 'favorites',
                value: toggle
            }, {
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });

            if ( response.data.hasOwnProperty('status_code') &&  response.data.status_code == 200 )
            {
                // If we're removing a favorite (toggle is false) and currently showing only favorites
                // Check if this was the last favorite, and if so, reset to "Show All"
                if (toggle === false && selectedDisplay.value == '6') {
                    // Find the submission we're unfavoriting
                    const submission = props.submissions?.find(s => s.sid === sid);
                    if (submission) {
                        // Count how many favorites will remain after this removal
                        const remainingFavorites = props.favorites.filter(fav => fav !== submission.ident).length;
                        if (remainingFavorites === 0) {
                            // Reset to "Show All" before reload to prevent blank view
                            selectedDisplay.value = '1';
                        }
                    }
                }

                // reload the server data
                router.reload();

            }
        } catch (error) {
            console.error(error);
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
        // Helper to check if using V2 status or legacy
        const isPublished = item.status ? item.status === 'published' : item.status == 20;
        const isPending = item.status ? item.status === 'submitted_new' || item.status === 'draft_new' : item.status == 1;
        const isDraft = item.status ? ['draft_new', 'draft_republish', 'draft_unpublish'].includes(item.status) : false;
        const isUnpublished = item.status ? item.status === 'unpublished' : false;
        const isRepublished = item.status ? ['draft_republish', 'submitted_republish'].includes(item.status) : false;

        if (selectedDisplay.value == 2) {
            // Show New (new submissions only, not editing existing)
            if (filterUser.value)
                return (item.user_id == mine.value && isPending);
            else
                return isPending;
        }
        else if (selectedDisplay.value == 3) {
            // Show Errors
            if (filterUser.value)
                return (item.user_id == mine.value && !isEmpty(item.submission_errors));
            else
                return !isEmpty(item.submission_errors);
        }
        else if (selectedDisplay.value == 4) {
            // Show Published
            if (filterUser.value)
                return (item.user_id == mine.value && isPublished);
            else
                return isPublished;
        }
        else if (selectedDisplay.value == 6) {
            // Show Favorites
            if (filterUser.value)
                return (item.user_id == mine.value && favorites.value.includes(item.ident));
            else
                return (favorites.value.includes(item.ident));
        }
        else if (selectedDisplay.value == 7) {
            // Show All Drafts (all work-in-progress: new, editing, unpublishing)
            if (filterUser.value)
                return (item.user_id == mine.value && isDraft);
            else
                return isDraft;
        }
        else if (selectedDisplay.value == 8) {
            // Show Unpublished (removed from public view)
            if (filterUser.value)
                return (item.user_id == mine.value && isUnpublished);
            else
                return isUnpublished;
        }
        else if (selectedDisplay.value == 9) {
            // Show Republished (republish in progress: draft or submitted)
            if (filterUser.value)
                return (item.user_id == mine.value && isRepublished);
            else
                return isRepublished;
        }

        // Show All
        if (filterUser.value)
            return (item.user_id == mine.value);

        return true;
    }


    function exportCSV(event)
    {
        // Helper function to format date as YYYY/MM/DD
        const formatDate = (dateString) => {
            if (!dateString) return '';
            try {
                const date = new Date(dateString);
                if (isNaN(date.getTime())) return '';

                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}/${month}/${day}`;
            } catch (e) {
                return '';
            }
        };

        // Get the filtered data - apply rowFilter first
        let filteredData = props.submissions?.filter(rowFilter) || [];

        // Apply global keyword search filter if present
        const globalFilter = filters.value.global?.value;
        if (globalFilter) {
            const searchLower = globalFilter.toLowerCase();
            filteredData = filteredData.filter(row => {
                // Search across all the globalFilterFields
                const searchableText = [
                    row.sid,
                    row.friendly,
                    row.gene?.symbol,
                    row.gene?.hgnc_id,
                    row.disease?.name,
                    row.disease?.curie,
                    row.inheritance?.name,
                    row.inheritance?.curie,
                    row.classification?.name,
                    row.submission_date
                ].filter(Boolean).join(' ').toLowerCase();

                return searchableText.includes(searchLower);
            });
        }

        // Define columns matching the submission worksheet format
        const columns = [
            'sgc_id',
            'action',
            'local_key',
            'hgnc_id',
            'hgnc_symbol',
            'disease_id',
            'disease_name',
            'moi_id',
            'moi_name',
            'submitter_id',
            'submitter_name',
            'classification_id',
            'classification_name',
            'date',
            'public_report_url',
            'notes',
            'pmids',
            'assertion_criteria_url'
        ];

        const columnHeaders = [
            'SGC ID',
            'Action',
            'Local Key',
            'HGNC ID',
            'Gene Symbol',
            'Disease ID (MONDO)',
            'Disease Name',
            'Mode of Inheritance ID',
            'Mode of Inheritance Name',
            'Submitter ID',
            'Submitter Name',
            'Classification ID',
            'Classification Name',
            'Report Date',
            'Public Report URL',
            'Notes',
            'PubMed IDs',
            'Assertion Criteria URL'
        ];

        // Build CSV content
        let csv = columnHeaders.join(',') + '\n';

        filteredData.forEach(row => {
            const rawDate = row.submission_data?.report?.display_date || '';
            const formattedDate = formatDate(rawDate);

            const values = [
                row.sid || '',
                'R',  // Action column - default to 'R' (Republish)
                row.local_key || '',
                (row.submission_data?.gene?.id && row.submission_data.gene.id !== '-') ? row.submission_data.gene.id : (row.gene?.hgnc_id || ''),
                (row.submission_data?.gene?.symbol && row.submission_data.gene.symbol !== '-') ? row.submission_data.gene.symbol : (row.gene?.symbol || ''),
                row.submission_data?.disease?.id || row.disease?.curie || '',
                row.submission_data?.disease?.name || row.disease?.name || '',
                row.submission_data?.moi?.id || row.inheritance?.curie || '',
                row.submission_data?.moi?.name || row.inheritance?.name || '',
                row.submitter?.curie || row.submission_data?.additional_information?.submitter_curie || '',
                row.submitter?.name || row.submission_data?.additional_information?.submitter_title || '',
                row.submission_data?.classification?.id || row.classification?.curie || '',
                row.submission_data?.classification?.name || row.classification?.name || '',
                formattedDate,  // Index 14 - formatted date (shifted from 13)
                row.submission_data?.report?.ext_url || '',
                row.submission_data?.notes?.display || '',
                (row.evidence || []).join(', '),
                row.submission_data?.criteria?.url || ''
            ];

            // Escape values that contain commas, quotes, or newlines
            // Always wrap date field (index 13) and PubMed IDs (index 16) in double quotes
            const escapedValues = values.map((val, index) => {
                const strVal = String(val);
                // Always wrap date column and PubMed IDs in quotes
                if (index === 13 || index === 16) {
                    return '"' + strVal.replace(/"/g, '""') + '"';
                }
                // Wrap other fields only if needed
                if (strVal.includes(',') || strVal.includes('"') || strVal.includes('\n')) {
                    return '"' + strVal.replace(/"/g, '""') + '"';
                }
                return strVal;
            });

            csv += escapedValues.join(',') + '\n';
        });

        // Download the CSV file
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'submissions_export.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function rowStyle(data)
    {
        // No background colors on submission rows
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

    // Get severity for status badge with custom classes for shades
    function getStatusSeverity(status) {
        const severityMap = {
            'draft_new': 'warning',             // Yellow
            'submitted_new': 'info',            // Blue
            'published': 'success',             // Green
            'draft_republish': 'warning',       // Yellow
            'submitted_republish': 'info',      // Blue
            'draft_unpublish': 'amber',         // Amber
            'submitted_unpublish': 'orange',    // Orange
            'unpublished': 'danger'             // Red
        };
        return severityMap[status] || 'secondary';
    }

    // Get custom CSS class for status badges with shades
    function getStatusClass(status) {
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
</style>

<template>
    <div>
        <!-- Loading overlay for bulk action reload -->
        <div v-if="isLoadingBulkAction" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-8 max-w-md text-center shadow-2xl">
                <i class="pi pi-spin pi-spinner text-blue-500 text-6xl mb-4"></i>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Processing Action</h3>
                <p class="text-gray-600">
                    Please wait while we update the submissions. This may take a moment for larger sets of submissions.
                </p>
            </div>
        </div>

        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <div v-if="errors" class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 p-4 mt-2" role="alert">
                <p class="font-bold">There are submission errors present</p>
                <p>
                    Submissions with errors are marked with a red warning icon.  Click on the edit link to resolve any errors.
                    Once corrected, the submission will automatically become eligible for publishing.
                </p>
            </div>

            <div v-if="props.hasSubmittedJob" class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mt-2" role="alert">
                <p class="font-bold">A submitted job exists.</p>
                <p>
                    The submitted job is awaiting processing. Published submissions cannot be edited or unpublished until the current job is processed.
                </p>
            </div>

            <ConfirmDialog group="headless">
                <template #container="{ message, acceptCallback, rejectCallback }">
                    <div class="flex flex-col items-center p-5 bg-surface-0 dark:bg-surface-700 rounded-md">
                        <!-- Red theme for Unpublish and Delete, Amber theme for Restore, Blue theme for others -->
                        <div v-if="message.header && (message.header.includes('Unpublish') || message.acceptLabel?.includes('Delete'))" class="rounded-full bg-red-500 dark:bg-red-400 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-exclamation-triangle text-5xl"></i>
                        </div>
                        <div v-else-if="message.header && message.header.includes('Restore')" class="rounded-full bg-amber-500 dark:bg-amber-400 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-history text-5xl"></i>
                        </div>
                        <div v-else class="rounded-full bg-blue-500 dark:bg-blue-400 text-surface-0 dark:text-surface-900 inline-flex justify-center items-center h-[6rem] w-[6rem] -mt-[3rem]">
                            <i class="pi pi-pencil text-5xl"></i>
                        </div>
                        <span class="font-bold text-2xl block mb-2 mt-4">{{ message.header }}</span>
                        <p class="mb-0">{{ message.message }}</p>
                        <div class="flex items-center gap-2 mt-4">
                            <Button v-if="message.header && (message.header.includes('Unpublish') || message.acceptLabel?.includes('Delete'))" :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-red-500 !ring-red-500 hover:!bg-red-600"></Button>
                            <Button v-else-if="message.header && message.header.includes('Restore')" :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-amber-500 !ring-amber-500 hover:!bg-amber-600"></Button>
                            <Button v-else :label="message.acceptLabel || 'Confirm'" @click="acceptCallback" class="!bg-blue-500 !ring-blue-500 hover:!bg-blue-600"></Button>
                            <Button :label="message.rejectLabel || 'Cancel'" outlined @click="rejectCallback" severity="secondary"></Button>
                        </div>
                    </div>
                </template>
            </ConfirmDialog>
            <Toast />

            <DataTable v-model:filters="filters" v-model:selection="selectedSubmissions" ref="dt" :value="submissions?.filter(rowFilter)" paginator :rows="25" :rowsPerPageOptions="[25, 50, 100, 250]" sortField="submission_date" :sortOrder="-1"
                    :rowStyle="rowStyle" :globalFilterFields="['sid', 'friendly', 'gene.symbol', 'gene.hgnc_id', 'disease.name', 'disease.curie', 'inheritance.name', 'inheritance.curie', 'classification.name', 'submission_date']" tableStyle="min-width: 20rem; width: auto;"
                    dataKey="sid">
                <template #header>
                    <!-- Bulk Action Toolbar -->
                    <div v-if="!props.hasSubmittedJob && selectedSubmissions.length > 0" class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-4">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-blue-900">
                                {{ selectedSubmissions.length }} submission(s) selected
                            </span>
                            <div class="flex gap-2">
                                <!-- Show appropriate buttons based on status -->
                                <template v-if="bulkActionAvailable && bulkActionAvailable.action === 'multiple'">
                                    <Button label="Republish Selected" icon="pi pi-refresh" @click="bulkRepublishSubmissions" severity="info" outlined raised />
                                    <Button label="Unpublish Selected" icon="pi pi-eye-slash" @click="bulkUnpublishSubmissions" severity="warning" outlined raised />
                                </template>
                                <template v-else-if="bulkActionAvailable && bulkActionAvailable.action === 'restore'">
                                    <Button label="Restore Selected" icon="pi pi-history" @click="bulkRestoreSubmissions" severity="secondary" outlined raised />
                                </template>
                                <template v-else-if="bulkActionAvailable && bulkActionAvailable.action === 'delete'">
                                    <Button label="Delete Selected" icon="pi pi-trash" @click="bulkDeleteSubmissions" severity="danger" outlined raised />
                                </template>
                                <template v-else-if="bulkActionAvailable && bulkActionAvailable.action === 'republish'">
                                    <Button label="Republish Selected" icon="pi pi-refresh" @click="bulkRepublishSubmissions" severity="success" outlined raised />
                                </template>
                                <template v-else>
                                    <span class="text-orange-600 italic">Selected submissions have different statuses - bulk actions unavailable</span>
                                </template>

                                <!-- Favorite/Unfavorite button - always available -->
                                <Button
                                    v-if="bulkFavoriteAction === 'favorite'"
                                    label="Favorite Selected"
                                    icon="pi pi-star"
                                    @click="bulkToggleFavorites"
                                    severity="help"
                                    outlined
                                    raised />
                                <Button
                                    v-else-if="bulkFavoriteAction === 'unfavorite'"
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
                        <span class="font-bold">
                            <Button icon="pi pi-download"
                                    label="Download"
                                    @click="exportCSV($event)"
                                    severity="info"
                                    outlined
                                    raised
                                    :disabled="filteredSubmissionsCount === 0" />
                            <span class="ml-3">
                                <template v-if="filteredSubmissionsCount < (submissions ? submissions.length : 0)">
                                    Showing {{ filteredSubmissionsCount }} of {{ submissions ? submissions.length : 0 }} submissions.
                                </template>
                                <template v-else>
                                    Total of {{ submissions ? submissions.length : 0 }} submissions.
                                </template>
                            </span>
                        </span>
                        <div class="text-left flex gap-2">
                            <Dropdown v-model="selectedDisplay" :options="displayOptions" optionLabel="name" optionValue="option" placeholder="Display" class="w-20rem" />
                            <ToggleButton v-model="filterUser" onLabel="User Only" offLabel="Submitter" onIcon="pi pi-user" offIcon="pi pi-sitemap" class="w-12rem" aria-label="Do you confirm" />
                        </div>
                        <IconField iconPosition="left">
                            <InputIcon>
                                <i class="pi pi-search" />
                            </InputIcon>
                            <InputText v-model="filters['global'].value" placeholder="Keyword Search" />
                        </IconField>
                    </div>
                </template>
                <Column v-if="!props.hasSubmittedJob" selectionMode="multiple" headerStyle="width: 3rem" :exportable="false"></Column>
                <Column field="ident" header="">
                     <template #body="{ data }">
                        <div v-if="favorites.includes(data.ident)" class="text-orange-300 text-xl" @click="updateFavorite(data.sid, false)"><i class="pi pi-star-fill" ></i></div>
                        <div v-else class="text-slate-300 text-xl" @click="updateFavorite(data.sid, true)"><i class="pi pi-star"></i></div>
                    </template>
                </Column>
                <Column field="sid" header="Submission" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" class="p-column-filter" placeholder="Search by name" />
                     </template>-->
                     <template #body="{ data }">
                        <div class="font-medium">{{ data.display_id || data.sid }}</div>
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
                            <span v-if="data.disease?.status === 8" class="text-orange-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(data.disease)">⚠</span>
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
                            <span v-if="data.original_disease?.status === 8" class="text-orange-500 cursor-help" v-tooltip.top="getDiseaseDeprecationTooltip(data.original_disease)">⚠</span>
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
                <Column field="submission_date" header="Submitted" sortable>
                    <!--<template #filter="{ filterModel, filterCallback }">
                        <InputText v-model="filterModel.value" type="text" @input="filterCallback()" class="p-column-filter" placeholder="Search by name" />
                     </template>-->
                     <template #body="{ data }">
                        {{ new Date(Date.parse(data.submission_date)).toISOString().split('T')[0] }}
                     </template>
                </Column>
                <Column field="status" header="Status" sortable>
                     <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <Tag v-if="data.status" :value="displayStatusV2(data.status)" :severity="getStatusSeverity(data.status)" :class="['status-tag', getStatusClass(data.status)]" />
                            <span v-else>{{ displayStatus(data.status) }}</span>
                            <i v-if="data.submission_errors && Object.keys(data.submission_errors).length > 0"
                               class="pi pi-exclamation-triangle text-red-500 text-xl"
                               title="Submission has errors"></i>
                        </div>
                     </template>
                </Column>
                <Column header="Action" style="width: 10%; min-width: 8rem" headerStyle="width: 5rem; text-align: center" bodyStyle="text-align: center; overflow: visible">
                    <template #body="slotProps">
                        <!-- V2 Status Actions -->
                        <template v-if="slotProps.data.status">
                            <!-- Published: Show Republish (Edit) and Unpublish buttons (only if no submitted job exists) -->
                            <span v-if="slotProps.data.status === 'published' && !props.hasSubmittedJob" class="mr-3">
                                <Button @click="requireRepublishConfirmation(slotProps.data.sid)" icon="pi pi-refresh" severity="info" text raised rounded v-tooltip.top="'Republish'"></Button>
                            </span>
                            <span v-if="slotProps.data.status === 'published' && !props.hasSubmittedJob" class="mr-3">
                                <Button @click="requireUnpublishConfirmationV2(slotProps.data.sid)" icon="pi pi-eye-slash" severity="warning" text raised rounded v-tooltip.top="'Unpublish'"></Button>
                            </span>

                            <!-- Draft states: Show Restore button -->
                            <span v-if="slotProps.data.status === 'draft_republish' || slotProps.data.status === 'draft_unpublish'" class="mr-3">
                                <Button @click="requireCancelConfirmation(slotProps.data.sid)" icon="pi pi-times-circle" severity="secondary" text raised rounded v-tooltip.top="'Restore'"></Button>
                            </span>

                            <!-- Draft new: Show Delete button -->
                            <span v-if="slotProps.data.status === 'draft_new'" class="mr-3">
                                <Button @click="requireConfirmation(slotProps.data.sid)" icon="pi pi-trash" severity="danger" text raised rounded v-tooltip.top="'Delete'"></Button>
                            </span>

                            <!-- Unpublished: Show Republish button -->
                            <span v-if="slotProps.data.status === 'unpublished'" class="mr-3">
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
                            <span v-if="slotProps.data.status == 3 && slotProps.data.origin_job_id !== null" class="mr-3">
                                <Button @click="requireCancelUpdateConfirmation(slotProps.data.sid)" icon="pi pi-times-circle" severity="secondary" text raised rounded v-tooltip.top="'Restore'"></Button>
                            </span>
                        </template>

                        <!-- View/Edit button (always shown) -->
                        <Button type="button" icon="pi pi-arrow-right" text raised rounded
                                v-tooltip.top="slotProps.data.status === 'draft_new' || slotProps.data.status === 'draft_republish' ? 'View/Edit' : 'View'"
                                @click="router.visit('/submissions/' + slotProps.data.ident)" />
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
