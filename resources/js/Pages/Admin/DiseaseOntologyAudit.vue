<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import DiseaseReplacementAdvice from '@/Components/DiseaseReplacementAdvice.vue'

const props = defineProps({
    run: Object, sourcesChanged: Boolean, latestAttempt: Object, filters: Object, caseLabels: Object,
    findings: Object, counts: Object, submitters: Array, namespaces: Array,
    submissionStates: Array, jobStates: Array,
})
const expandedRows = ref([])
const filterKeys = ['case', 'submitter', 'namespace', 'submission_status', 'job_status', 'search']
const form = reactive(Object.fromEntries(filterKeys.map(key => [key, props.filters[key] ?? ''])))
// One query shape (stable key order, empty values omitted) for filtering, paging, and export.
const query = source => Object.fromEntries([...filterKeys, 'run']
    .map(key => [key, key === 'run' ? props.run?.id : source[key]])
    .filter(([, value]) => value !== '' && value != null)
    .map(([key, value]) => [key, String(value)]))
const visit = parameters => router.get(route('admin.disease-ontology-audit'), parameters, { preserveState: true, preserveScroll: true })
// Filters of the latest visit. Paging uses them, so it cannot pick up a newer filter state.
// A newer Inertia visit cancels an older one in flight, so the latest request wins.
let requested = query(props.filters)
let searchTimer
const applyFilters = () => {
    clearTimeout(searchTimer)
    const parameters = query(form)
    if (JSON.stringify(parameters) === JSON.stringify(requested)) return
    requested = parameters
    visit(parameters) // No page parameter: a filter change returns to page 1.
}
const searchChanged = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyFilters, 350)
}
// A pending search must not navigate back here after the page is left.
onBeforeUnmount(() => clearTimeout(searchTimer))
const clearFilters = () => {
    filterKeys.forEach(key => { form[key] = '' })
    applyFilters()
}
const changePage = event => visit({ ...requested, page: event.page + 1 })
const date = value => value ? new Date(value).toLocaleString() : '—'
const term = value => value ? `${value.curie} — ${value.name}${value.deprecated ? ' (deprecated)' : ''}${value.deleted ? ' (deleted)' : ''}` : 'None'
const routeLabels = {
    mondo_self: 'Submitted MONDO term', mondo_exact_match: 'MONDO exact match',
    orphanet_exact_match: 'Orphadata exact equivalence', omim_bridge: 'Exact OMIM bridge',
}
const oldReport = computed(() => props.run && Date.now() - new Date(props.run.finished_at).getTime() > 8 * 86400000)
// Export uses the filters the server applied to the displayed table, not a pending edit.
const exportUrl = computed(() => route('admin.disease-ontology-audit.export', query(props.filters)))
</script>

<template>
    <AppLayout title="Disease Ontology Audit">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">Disease Ontology Audit</h2>
        </template>
        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-xl sm:rounded-lg p-6 lg:p-8 space-y-6">
                    <p class="text-gray-600">
                        Review disease terms on live and most recent submission versions, including published and unpublished records.
                        Findings do not change submissions or their publication status.
                        <Link :href="route('help.disease-mapping')" class="text-blue-600 underline">Disease mapping policy</Link>
                    </p>
                    <p v-if="latestAttempt?.status === 'running'" role="status" class="bg-blue-50 text-blue-900 p-4">
                        An audit started {{ date(latestAttempt.started_at) }}. Refresh to check progress.
                        Any results below are from the last successful scan. If the process stopped, rerun the audit.
                    </p>
                    <p v-if="latestAttempt?.status === 'failed'" role="alert" class="bg-red-50 text-red-900 p-4">
                        The latest audit failed. {{ latestAttempt.failure }} Any previous successful results remain available.
                    </p>
                    <div v-if="!run" class="bg-gray-50 p-4">
                        No successful disease audit is available. It runs after each scheduled disease update, or an administrator can run it from the Dashboard's Admin Actions.
                    </div>
                    <template v-else>
                        <div class="text-sm text-gray-600 space-y-2">
                            <p>Scan #{{ run.id }} completed {{ date(run.finished_at) }} · {{ run.examined_count.toLocaleString() }} versions examined.</p>
                            <p>Results reflect data at scan time. Later submission edits or ontology updates may make them stale.</p>
                            <p v-if="sourcesChanged" role="status" class="text-amber-800">Disease data was updated after this scan. Run a new audit from the Dashboard's Admin Actions before acting on it.</p>
                            <p v-else-if="oldReport" role="status" class="text-amber-800">This report is more than eight days old. Run a new audit before acting on it.</p>
                            <p v-if="latestAttempt?.status === 'succeeded' && latestAttempt.id !== run.id">
                                You are viewing an older scan. <Link :href="route('admin.disease-ontology-audit')" class="text-blue-600 underline">View latest results</Link>
                            </p>
                            <details>
                                <summary class="cursor-pointer">Scan scope and source metadata</summary>
                                <p class="mt-2">{{ run.scope }}. Application version: {{ run.app_version || 'Not recorded' }}.</p>
                                <p>Cached HTTP headers describe observed source files; they do not certify a completed import.</p>
                                <ul class="mt-2 space-y-2">
                                    <li v-for="source in run.source_metadata" :key="source.file_identifier" class="break-words">
                                        {{ source.file_identifier }}: {{ source.content_length ?? 'Unknown' }} bytes;
                                        last modified {{ source.last_modified || 'unknown' }}; ETag {{ source.etag || 'unknown' }}.
                                        <span v-if="source.header_context && source.header_context !== 'current'">{{ source.header_context }} header context; replacement import unconfirmed.</span>
                                    </li>
                                </ul>
                                <p v-if="!run.source_metadata?.length">No source headers were recorded.</p>
                            </details>
                            <details>
                                <summary class="cursor-pointer">Cases across this scan (counts overlap)</summary>
                                <ul class="mt-2 grid sm:grid-cols-2 gap-2">
                                    <li v-for="(count, code) in run.case_counts" :key="code">{{ caseLabels[code] }}: {{ count }}</li>
                                </ul>
                            </details>
                        </div>
                        <form @submit.prevent="applyFilters" @change="applyFilters" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <label class="text-sm">Finding type
                                <select v-model="form.case" aria-label="Finding type" class="audit-filter"><option value="">All findings</option><option v-for="(label, code) in caseLabels" :key="code" :value="code" :disabled="!Object.hasOwn(run.case_counts || {}, code)">{{ label }}{{ Object.hasOwn(run.case_counts || {}, code) ? '' : ' (not captured)' }}</option></select>
                            </label>
                            <label class="text-sm">Submitter
                                <select v-model="form.submitter" aria-label="Submitter" class="audit-filter"><option value="">All submitters</option><option v-for="submitter in submitters" :key="submitter.submitter_id" :value="submitter.submitter_id">{{ submitter.submitter_name || 'Unknown' }}</option></select>
                            </label>
                            <label class="text-sm">Submitted namespace
                                <select v-model="form.namespace" aria-label="Submitted namespace" class="audit-filter"><option value="">All namespaces</option><option v-for="namespace in namespaces" :key="namespace">{{ namespace }}</option></select>
                            </label>
                            <label class="text-sm">Submission status
                                <select v-model="form.submission_status" aria-label="Submission status" class="audit-filter"><option value="">All statuses</option><option v-for="status in submissionStates" :key="status">{{ status }}</option></select>
                            </label>
                            <label class="text-sm">Job status
                                <select v-model="form.job_status" aria-label="Job status" class="audit-filter"><option value="">All job statuses</option><option v-for="status in jobStates" :key="status">{{ status }}</option></select>
                            </label>
                            <label class="text-sm">Search SGC, job, or disease ID
                                <input v-model="form.search" @input="searchChanged" type="search" maxlength="200" class="audit-filter" placeholder="e.g. OMIM:101800" />
                            </label>
                            <div class="sm:col-span-2 lg:col-span-3 flex flex-wrap items-center gap-4">
                                <button type="button" @click="clearFilters" class="text-blue-700 underline">Clear filters</button>
                                <a :href="exportUrl" class="text-blue-700 underline">Download filtered CSV</a>
                            </div>
                        </form>
                        <p class="text-sm text-gray-600" aria-live="polite">
                            Matching findings: {{ counts.versions }} submission versions · {{ counts.sgc_ids }} SGC IDs ·
                            {{ counts.identifiers }} submitted identifiers · {{ counts.submitters }} submitters.
                        </p>
                        <DataTable v-model:expandedRows="expandedRows" :value="findings.data" dataKey="id" lazy paginator
                            :rows="25" :totalRecords="findings.total" :first="(findings.current_page - 1) * 25"
                            @page="changePage" stripedRows :tableStyle="{ tableLayout: 'fixed', width: '100%' }" class="audit-table">
                            <template #empty>No findings match this view.</template>
                            <Column expander style="width: 3rem" />
                            <Column header="Record / submitter">
                                <template #body="{ data }">
                                    <span class="font-medium">{{ data.sid }}.{{ data.version_number }}</span>
                                    <div class="text-xs">{{ data.job_slug }}</div><div class="mt-1">{{ data.submitter_name }}</div>
                                </template>
                            </Column>
                            <Column header="State">
                                <template #body="{ data }">
                                    {{ data.submission_status }}<div class="text-xs">Job: {{ data.job_status || 'Unknown' }}</div>
                                    <div class="text-xs">{{ data.evidence.is_live ? 'Live version' : '' }}{{ data.evidence.is_live && data.evidence.is_most_recent ? ' · ' : '' }}{{ data.evidence.is_most_recent ? 'Most recent' : '' }}</div>
                                </template>
                            </Column>
                            <Column header="Submitted disease"><template #body="{ data }">{{ data.submitted_id || 'Missing' }}</template></Column>
                            <Column header="Stored MONDO"><template #body="{ data }">{{ data.stored_curie || 'None' }}</template></Column>
                            <Column header="Current result">
                                <template #body="{ data }">{{ data.current_curie || (data.cases.includes('ambiguous') ? 'Ambiguous' : 'Unresolved') }}
                                    <div class="text-xs">{{ data.evidence.accepted ? 'Accepted by current validation' : 'Rejected by current validation' }}</div>
                                </template>
                            </Column>
                            <Column header="Findings">
                                <template #body="{ data }"><span v-for="code in data.cases" :key="code" class="block text-xs rounded bg-amber-50 text-amber-900 px-2 py-1 mb-1">{{ caseLabels[code] }}</span></template>
                            </Column>
                            <template #expansion="{ data }">
                                <div class="p-4 space-y-3 text-sm break-words">
                                    <p><strong>Submitted term:</strong> {{ term(data.evidence.submitted_term) }}</p>
                                    <p><strong>Stored original:</strong> {{ term(data.evidence.stored_original) }}</p>
                                    <p><strong>Stored target:</strong> {{ term(data.evidence.stored_target) }}</p>
                                    <p><strong>Current target:</strong> {{ term(data.evidence.current_target) }}</p>
                                    <p><strong>Current resolution route:</strong> {{ routeLabels[data.evidence.route] || 'No match' }}</p>
                                    <DiseaseReplacementAdvice v-if="data.evidence.replacements" :recommendations="data.evidence.replacements" />
                                    <p v-else>Replacement information was not captured in this scan.</p>
                                    <template v-if="data.evidence.relationship_collisions">
                                        <div v-for="(collision, basis) in data.evidence.relationship_collisions" :key="basis" class="bg-amber-50 p-3 space-y-2">
                                            <p class="font-semibold">Shared {{ basis }} mapping: {{ collision.mondo }} · {{ collision.peer_count }} other assertions</p>
                                            <p>{{ basis === 'stored' ? 'These records store the same MONDO relationship.' : 'Current validation accepts the same MONDO relationship for these records.' }}</p>
                                            <div v-for="(peer, index) in collision.peers" :key="index">
                                                <p v-for="version in peer.versions" :key="version.id">{{ version.sid }}.{{ version.version }} · {{ version.submitted_id }} · {{ version.job }} · {{ version.status === 'unpublished' ? 'Already unpublished' : version.status }}</p>
                                            </div>
                                            <p v-if="collision.peer_count > collision.peers.length">Peer list capped; total count includes the remaining assertions.</p>
                                        </div>
                                    </template>
                                    <p v-else>Relationship collision information was not captured in this scan.</p>
                                    <p v-if="data.evidence.validation_error">{{ data.evidence.validation_error }}</p>
                                    <p v-if="data.evidence.existing_disease_error"><strong>Previously recorded disease error:</strong> {{ data.evidence.existing_disease_error }}</p>
                                    <ul v-if="data.evidence.candidates.length > 1"><li v-for="candidate in data.evidence.candidates" :key="candidate.curie">{{ term(candidate) }}</li></ul>
                                    <p v-if="data.evidence.original_upload_id"><strong>Original upload ID (context only):</strong> {{ data.evidence.original_upload_id }}</p>
                                    <p class="text-gray-500">Deprecated means marked deprecated in the local ontology data. Current evidence does not establish why a historical mapping was chosen.</p>
                                </div>
                            </template>
                        </DataTable>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.audit-filter { @apply block w-full mt-1 rounded border-gray-300 text-sm; }
.audit-table :deep(td), .audit-table :deep(th) { overflow-wrap: anywhere; white-space: normal; vertical-align: top; }
</style>
