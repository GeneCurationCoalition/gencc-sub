<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <AppLayout title="Submission Directions">
        <template #header>
            <h2 class="font-semibold text-4xl text-white leading-tight">
                Submission Directions
            </h2>
        </template>

        <div class="pb-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                    <div class="p-6 lg:p-8">

                        <!-- Submission Spreadsheet Section -->
                        <div class="mb-8">
                            <h3 class="text-2xl font-bold text-gray-900 mb-4">Submission Spreadsheet</h3>
                            <p class="text-gray-700 leading-relaxed">
                                Member organizations can download the current version of the
                                <a href="/documents/GenCC Submission Spreadsheet.xlsx"
                                   class="font-semibold text-indigo-700 hover:text-indigo-900">
                                    GenCC Submission Spreadsheet - Version 2
                                </a>
                                with guidance provided within the Excel file itself.
                            </p>
                        </div>

                        <!-- Submitter IDs Section -->
                        <div class="mb-8">
                            <h3 class="text-2xl font-bold text-gray-900 mb-4">Submitter IDs</h3>
                            <p class="text-gray-700 leading-relaxed mb-4">
                                The GenCC has member organizations, each assigned a unique identifier.
                                Submissions must include the appropriate submitter identifier in the designated column of the spreadsheet.
                            </p>

                            <div class="bg-gray-50 rounded-lg p-6">
                                <ul class="list-disc list-inside space-y-2 text-gray-700">
                                    <li><span class="font-mono text-gray-900">GENCC:000101</span> – Ambry Genetics</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000102</span> – ClinGen</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000103</span> – DECIPHER</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000104</span> – Genomics England PanelApp</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000105</span> – Illumina</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000106</span> – Labcorp Genetics (formerly Invitae)</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000107</span> – Laboratory for Molecular Medicine</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000108</span> – Myriad Women's Health</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000109</span> – Online Mendelian Inheritance in Man (OMIM)</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000110</span> – Orphanet</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000111</span> – PanelApp Australia</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000112</span> – G2P</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000113</span> – Franklin by Genoox</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000114</span> – King Faisal Specialist Hospital and Research Center</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000115</span> – Broad Center for Mendelian Genomics</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000116</span> – Baylor College of Medicine Research Center</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000117</span> – LiferaOmics</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000118</span> – University of Washington Center for Rare Disease Research (UW-CRDR)</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000119</span> – Stanford Center for Undiagnosed Diseases</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000120</span> – ClinPGx</li>
                                    <li><span class="font-mono text-gray-900">GENCC:000121</span> – HGNC</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Classification IDs Section -->
                        <div class="mb-8">
                            <h3 class="text-2xl font-bold text-gray-900 mb-4">Classification IDs</h3>
                            <p class="text-gray-700 leading-relaxed mb-4">
                                The GenCC recognizes nine evidence classifications for gene-disease associations,
                                categorizing the strength of evidence supporting gene-disease relationships.
                            </p>

                            <div class="bg-gray-50 rounded-lg p-6">
                                <ul class="list-disc list-inside space-y-2 text-gray-700">
                                    <li><span class="font-mono text-gray-900">GENCC:100001</span> – Definitive</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100002</span> – Strong</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100003</span> – Moderate</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100004</span> – Limited</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100005</span> – Disputed Evidence</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100006</span> – Refuted Evidence</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100007</span> – Animal Model Only</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100008</span> – No Known Disease Relationship</li>
                                    <li><span class="font-mono text-gray-900">GENCC:100009</span> – Supportive</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Mode of Inheritance IDs Section -->
                        <div class="mb-8">
                            <h3 class="text-2xl font-bold text-gray-900 mb-4">Mode of Inheritance IDs</h3>
                            <p class="text-gray-700 leading-relaxed mb-4">
                                Submissions require HPO (Human Phenotype Ontology) codes for inheritance patterns.
                            </p>

                            <div class="bg-gray-50 rounded-lg p-6">
                                <ul class="list-disc list-inside space-y-2 text-gray-700">
                                    <li><span class="font-mono text-gray-900">HP:0000005</span> – Unknown</li>
                                    <li><span class="font-mono text-gray-900">HP:0000006</span> – Autosomal dominant</li>
                                    <li><span class="font-mono text-gray-900">HP:0010985</span> – Gonosomal</li>
                                    <li><span class="font-mono text-gray-900">HP:0001426</span> – Multifactorial</li>
                                    <li><span class="font-mono text-gray-900">HP:0032382</span> – Uniparental disomy</li>
                                    <li><span class="font-mono text-gray-900">HP:0001428</span> – Somatic mutation</li>
                                    <li><span class="font-mono text-gray-900">HP:0000007</span> – Autosomal recessive</li>
                                    <li><span class="font-mono text-gray-900">HP:0001466</span> – Contiguous gene syndrome</li>
                                    <li><span class="font-mono text-gray-900">HP:0003743</span> – Genetic anticipation</li>
                                    <li><span class="font-mono text-gray-900">HP:0001425</span> – Heterogeneous</li>
                                    <li><span class="font-mono text-gray-900">HP:0001427</span> – Mitochondrial</li>
                                    <li><span class="font-mono text-gray-900">HP:0032113</span> – Semidominant</li>
                                    <li><span class="font-mono text-gray-900">HP:0003745</span> – Sporadic</li>
                                    <li><span class="font-mono text-gray-900">HP:0001417</span> – X-linked</li>
                                    <li><span class="font-mono text-gray-900">HP:0001419</span> – X-linked recessive</li>
                                    <li><span class="font-mono text-gray-900">HP:0001423</span> – X-linked dominant</li>
                                    <li><span class="font-mono text-gray-900">HP:0001450</span> – Y-linked inheritance</li>
                                    <li><span class="font-mono text-gray-900">HP:0001442</span> – Somatic mosaicism</li>
                                    <li><span class="font-mono text-gray-900">HP:0012274</span> – Autosomal dominant inheritance with paternal imprinting</li>
                                    <li><span class="font-mono text-gray-900">HP:0010984</span> – Digenic inheritance</li>
                                    <li><span class="font-mono text-gray-900">HP:0012275</span> – Autosomal dominant inheritance with maternal imprinting</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Disclaimer Section -->
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-yellow-800">Important Notice</h4>
                                    <div class="mt-2 text-sm text-yellow-700">
                                        <p>
                                            The information on this website is not intended for direct diagnostic use or medical
                                            decision-making without review by a genetics professional. The GenCC emphasizes that
                                            submitted information undergoes periodic collection rather than independent verification.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
