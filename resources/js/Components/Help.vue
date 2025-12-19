<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Message from 'primevue/message';
</script>

<template>
    <div>
        <div class="p-6 lg:p-8 bg-white border-b border-gray-200">

            <Message severity="info" :closable="false">
                <div class="font-bold text-lg">Welcome to the GenCC Submission Portal</div>
               There are no system announcements at this time
            </Message>


            <p class="mt-6 text-gray-500 leading-relaxed">
                The following resources should resolve most issues, however, in the event it does not, you can contact us using the provided link.
            </p>
        </div>

        <div class="bg-gray-200 bg-opacity-25 grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 p-6 lg:p-8">
            <div>
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" class="w-6 h-6 stroke-gray-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                    <h2 class="ms-3 text-xl font-semibold text-gray-900">
                        GenCC Submission Directions and Spreadsheet Download
                    </h2>
                </div>

                <p class="mt-4 text-gray-500 text-sm leading-relaxed">
                    Use the link below to view the GenCC Submission Directions and to download the GenCC Submission Spreadsheet. The spreadsheet is an alternative method of submitting new curations. Follow all directions, including required fields and formats, and make sure the full header (lines 1-12) is left intact.
                </p>

                <p class="mt-4 text-sm">
                    <a href="/submission-directions" class="inline-flex items-center font-semibold text-indigo-700">
                        View the submission directions

                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="ms-1 w-5 h-5 fill-indigo-500">
                            <path fill-rule="evenodd" d="M5 10a.75.75 0 01.75-.75h6.638L10.23 7.29a.75.75 0 111.04-1.08l3.5 3.25a.75.75 0 010 1.08l-3.5 3.25a.75.75 0 11-1.04-1.08l2.158-1.96H5.75A.75.75 0 015 10z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </p>
            </div>

            <div>
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" class="w-6 h-6 stroke-gray-400">
                        <path d="M3 8A2 2 0 0 1 5 6H19A2 2 0 0 1 21 8V17A2 2 0 0 1 19 19H5A2 2 0 0 1 3 17V8M3 9L10.5 13.5A3 3 0 0 0 13.5 13.5L21 9"/>
                    </svg>
                    <h2 class="ms-3 text-xl font-semibold text-gray-900">
                        Contact Us
                    </h2>
                </div>

                <p class="mt-4 text-gray-500 text-sm leading-relaxed">
                    Use the link below to open an email to GenCC using your computer's default email application.
                    Optionally, you can cut and paste the GenCC email address <b>gencc@thegencc.org</b>
                </p>

                <p class="mt-4 text-sm">
                    <a href="mailto:gencc@thegencc.org" target="_target" class="inline-flex items-center font-semibold text-indigo-700">
                        Send an email

                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="ms-1 w-5 h-5 fill-indigo-500">
                            <path fill-rule="evenodd" d="M5 10a.75.75 0 01.75-.75h6.638L10.23 7.29a.75.75 0 111.04-1.08l3.5 3.25a.75.75 0 010 1.08l-3.5 3.25a.75.75 0 11-1.04-1.08l2.158-1.96H5.75A.75.75 0 015 10z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </p>
            </div>
        </div>
    </div>
</template>
