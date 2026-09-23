<script setup>
import HelpArticleLayout from '@/Layouts/HelpArticleLayout.vue';

const resolutionRows = [
    { input: 'MONDO', method: 'The submitted MONDO term resolves to itself.', result: 'That MONDO term' },
    { input: 'OMIM', method: 'A MONDO term must list the OMIM identifier as an exact match.', result: 'The uniquely matched MONDO term' },
    { input: 'ORPHA or Orphanet', method: 'The ordered Orphanet process below is applied.', result: 'The uniquely matched MONDO term' },
];
</script>

<template>
    <HelpArticleLayout title="Disease Mapping">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Disease Mapping
            </h2>
        </template>

        <div class="min-h-screen bg-gray-100 pb-12">
            <main class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <section id="principles" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Mapping principles</h2>
                        <p class="mt-4 text-gray-700">Every accepted disease identifier must resolve to exactly one MONDO term. The portal follows three rules:</p>
                        <ol class="mt-4 list-decimal space-y-3 pl-6 text-gray-700">
                            <li><strong>Exact relationships only.</strong> Broader, narrower, undecided, and unannotated cross-references are not treated as mappings.</li>
                            <li><strong>MONDO is the normalized disease.</strong> The submitted identifier is preserved when its source record exists, but the submission's normalized disease is always MONDO.</li>
                            <li><strong>Ambiguity fails closed.</strong> The portal does not choose a candidate by database order or guess among multiple MONDO terms.</li>
                        </ol>
                    </section>

                    <section id="accepted-identifiers" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Accepted identifiers</h2>
                        <p class="mt-4 text-gray-700">Disease identifiers use a namespace and numeric identifier:</p>
                        <ul class="mt-4 list-disc space-y-2 pl-6 text-gray-700">
                            <li><code class="rounded bg-gray-100 px-1.5 py-0.5">MONDO:0013212</code></li>
                            <li><code class="rounded bg-gray-100 px-1.5 py-0.5">OMIM:613287</code></li>
                            <li><code class="rounded bg-gray-100 px-1.5 py-0.5">Orphanet:722</code> or <code class="rounded bg-gray-100 px-1.5 py-0.5">ORPHA:722</code></li>
                        </ul>
                        <p class="mt-4 text-gray-700">Prefixes are case-insensitive, and ORPHA is normalized to Orphanet. Other namespaces, bare disease numbers, and OMIM phenotypic-series identifiers are not supported submission formats.</p>
                    </section>

                    <section id="resolution" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Resolution by namespace</h2>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead class="bg-gray-50"><tr><th class="px-4 py-3 font-semibold">Submitted namespace</th><th class="px-4 py-3 font-semibold">Method</th><th class="px-4 py-3 font-semibold">Normalized result</th></tr></thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="row in resolutionRows" :key="row.input"><td class="px-4 py-3 font-medium">{{ row.input }}</td><td class="px-4 py-3 text-gray-700">{{ row.method }}</td><td class="px-4 py-3 text-gray-700">{{ row.result }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-4 text-gray-700">For OMIM, an Orphanet record that happens to reference the same OMIM identifier is not used as a bridge. An OMIM submission maps only when MONDO itself records that exact OMIM match.</p>
                    </section>

                    <section id="orphanet" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Orphanet resolution order</h2>
                        <p class="mt-4 text-gray-700">The portal evaluates these steps in order. A unique match resolves the identifier; multiple matches stop resolution as ambiguous.</p>
                        <ol class="mt-4 space-y-4 text-gray-700">
                            <li class="rounded border-l-4 border-sky-600 bg-sky-50 p-4"><strong>1. MONDO exact match.</strong> A MONDO term lists the submitted Orphanet code as an exact match.</li>
                            <li class="rounded border-l-4 border-sky-600 bg-sky-50 p-4"><strong>2. Orphadata MONDO relation.</strong> Orphadata lists an exact, validated MONDO equivalent for the Orphanet disorder.</li>
                            <li class="rounded border-l-4 border-sky-600 bg-sky-50 p-4"><strong>3. Exact OMIM bridge.</strong> Orphadata lists an exact, validated OMIM equivalent, and MONDO lists that OMIM identifier as an exact match.</li>
                            <li class="rounded border-l-4 border-gray-400 bg-gray-50 p-4"><strong>4. No mapping.</strong> If none of those steps succeeds, the identifier remains unresolved.</li>
                        </ol>
                        <p class="mt-4 text-gray-700">A unique result at an earlier step is authoritative for this process; lower-priority paths are not evaluated afterward.</p>
                    </section>

                    <section id="ambiguity" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Ambiguous mappings</h2>
                        <p class="mt-4 text-gray-700">If the step currently being evaluated produces more than one distinct MONDO term, resolution stops. The submission receives an error listing the candidates; the portal does not continue to a lower-priority step or select one arbitrarily.</p>
                        <p class="mt-3 text-gray-700">Review the candidate diseases and submit the scientifically appropriate MONDO identifier directly.</p>
                    </section>

                    <section id="unresolved" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Valid format does not guarantee a mapping</h2>
                        <p class="mt-4 text-gray-700">An identifier can have valid syntax and still be unknown to the loaded source, lack an exact MONDO relationship, or map ambiguously. Such a row may be created with a blocking disease error so it can be corrected in the portal.</p>
                        <p class="mt-3 text-gray-700">There is also a narrower case where MONDO names an external identifier but the portal has no standalone source record for that identifier. The error asks you to submit the resolved MONDO identifier directly.</p>
                    </section>

                    <section id="deprecated" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8 mb-6">
                        <h2 class="text-2xl font-bold text-gray-900">Deprecated and unavailable terms</h2>
                        <p class="mt-4 text-gray-700">Active and deprecated disease records are eligible for resolution. A deprecated MONDO result produces a non-blocking warning, with a replacement shown when MONDO provides one. The portal does not silently replace the submitted scientific assertion.</p>
                        <p class="mt-3 text-gray-700">Soft-deleted or otherwise unavailable records are not eligible. Terms removed from an upstream source may remain for historical references, but they do not regain old broad mappings.</p>
                    </section>

                    <section id="sources" class="scroll-mt-24 bg-white overflow-hidden shadow-xl sm:rounded-lg p-6 lg:p-8">
                        <h2 class="text-2xl font-bold text-gray-900">Mapping sources</h2>
                        <ul class="mt-4 list-disc space-y-3 pl-6 text-gray-700">
                            <li><strong>MONDO:</strong> only relationships explicitly marked <code class="rounded bg-gray-100 px-1.5 py-0.5">skos:exactMatch</code> are used for OMIM and Orphanet mapping.</li>
                            <li><strong>Orphadata:</strong> only cross-references whose relation is exact and whose status is validated are used.</li>
                            <li><strong>OMIM titles:</strong> provide OMIM records and names, but do not themselves assert a MONDO relationship.</li>
                        </ul>
                        <p class="mt-4 text-gray-700">Generic or unqualified cross-references and relationships marked broader, narrower, or undecided are intentionally excluded.</p>
                    </section>
            </main>
        </div>
    </HelpArticleLayout>
</template>
