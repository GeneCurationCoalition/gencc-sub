<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/GuestLayout.vue';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import { ref } from "vue";

const visible = ref(false);
const fpvisible = ref(false);
const loginMessage = ref('Please enter a valid username and password');
const loginError = ref('info');
const fpMessage = ref('Enter your email address to receive a password reset link');
const fpMessageType = ref('info');
const isRefreshingToken = ref(false);

defineProps({
    canLogin: Boolean,
    laravelVersion: String,
    phpVersion: String,
});

const username = defineModel('username');
const password = defineModel('password');
const email = defineModel('email');

async function refreshCsrfToken() {
    try {
        await axios.get('/sanctum/csrf-cookie');
    } catch (error) {
        console.error('Failed to refresh CSRF token:', error);
    }
}

async function closeCallback() {


    if (username.value == '' || password.value == '')
    {
        loginMessage.value = "Invalid username or password"
        loginError.value = 'error';
        return;
    }

    try {
        // Refresh CSRF token before attempting login
        await refreshCsrfToken();

        const response = await axios.post('/login', {
            email: username.value,
            password: password.value,
            remember: ''
        }, {
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        });

        if (response.status == 200) {
            router.visit('/dashboard');
        }

    } catch (error) {
        loginMessage.value = 'Invalid Username or Password';
        loginError.value = 'error';
    }
}

async function fpcloseCallback() {
    if (!email.value || email.value.trim() === '') {
        fpMessage.value = 'Please enter a valid email address';
        fpMessageType.value = 'error';
        return;
    }

    try {
        // Refresh CSRF token before sending request
        await refreshCsrfToken();

        await axios.post('/forgot-password', {
            email: email.value
        });

        // Success - show message and close dialog
        fpMessage.value = 'If an account exists with that email, a password reset link has been sent.';
        fpMessageType.value = 'success';

        // Close dialog after showing success message briefly
        setTimeout(() => {
            fpvisible.value = false;
            // Reset message for next time
            fpMessage.value = 'Enter your email address to receive a password reset link';
            fpMessageType.value = 'info';
            email.value = '';
        }, 3000);

    } catch (error) {
        // Laravel returns validation errors or throttle errors
        if (error.response?.status === 422) {
            fpMessage.value = error.response.data.errors?.email?.[0] || 'Invalid email address';
        } else if (error.response?.status === 429) {
            fpMessage.value = 'Too many requests. Please wait before trying again.';
        } else {
            fpMessage.value = 'An error occurred. Please try again.';
        }
        fpMessageType.value = 'error';
    }
}

function showFP() {
    visible.value = false;
    fpvisible.value = true;
}

async function showLogin() {
    visible.value = true;
    // Reset login message to default state
    loginMessage.value = 'Please enter a valid username and password';
    loginError.value = 'info';
    // Proactively refresh CSRF token when opening login dialog
    await refreshCsrfToken();
}

</script>


<template>
    <AppLayout title="Welcome"></AppLayout>

    <div class="w-screen bg-cover py-10 bg-[url('/images/1399.jpg')]">
        <div class="grid grid-cols-2 gap-4">
            <div class="flex col-span-1 pl-10 justify-center">
                <div class="flex rounded-full w-96 h-96 bg-sky-900 text-4xl text-white col-span-1 pl-10 items-center text-center ">
                        GenCC<br>Submission Portal
                </div>
            </div>
            <div class="col-span-1 pl-10">
                &nbsp;
            </div>
        </div>
    </div>

    <div class="w-screen max-w-7xl mx-auto py-20">
        <div class="grid grid-cols-4 gap-4">
            <div class="text-center m-auto text-4xl col-span-1">
                <a href="mailto:gencc@thegencc.org">
                    <i class="pi pi-envelope"></i>
                    <div class="text-2xl">Contact<br>Us</div>
                </a>
            </div>
            <div class="text-center m-auto text-4xl col-span-1">
                <a href="https://thegencc.org">
                    <i class="pi pi-home"></i>
                    <div class="text-2xl">GenCC<br>Home</div>
                </a>
            </div>
            <div class="text-center m-auto text-4xl col-span-1">
                <a href="https://search.thegencc.org">
                    <i class="pi pi-database"></i>
                    <div class="text-2xl">GenCC<br>Database</div>
                </a>
            </div>
            <div class="text-center m-auto text-4xl col-span-1" @click="showLogin">
                <i class="pi pi-sign-in"></i>
                <div class="text-2xl">Portal<br>Access</div>
            </div>
        </div>
    </div>

    <!--Footer container-->
    <footer
    class="flex flex-col items-center bg-black/10 text-center dark:bg-neutral-700 dark:text-white lg:text-left">
        <div class="container p-6 max-w-7xl mx-auto">
            <div class="grid gap-10 lg:grid-cols-2">
            <div class="mb-6 md:mb-0">
                <h5 class="mb-2 font-medium uppercase">About GenCC</h5>

                <p class="mb-4">
                    The Gene Curation Coalition brings together groups engaged in the evaluation of gene-disease validity with a willingness to share data publicly, 
                    to develop consistent terminology for gene curation activities and to facilitate the consistent assessment of genes that have been reported in association with disease.
                </p>
            </div>

            <div class="mb-6 md:mb-0">
                <h5 class="mb-2 font-medium uppercase">Become a Member</h5>

                <p class="mb-4">
                The GenCC coalition is composed of sixteen leading institutions who collaborate, contribute, and commit to furthering the understanding of gene-disease relationships.  To join the GenCC coalition, please contact us at gencc@thegencc.org.
                </p>
            </div>
            </div>
        </div>

        <!--Copyright section-->
        <div class="w-full bg-black/10 p-4 text-center">
            © 2024 
            <a href="https://thegencc.org/">The GenCC - All rights reserved</a>
        </div>
    </footer>

    <Dialog
        v-model:visible="visible"
        modal
        :pt="{
            mask: {
                style: 'backdrop-filter: blur(2px)'
            }
        }"
    >
        <template #container="{ showLogin }">
            <div class="flex flex-col px-10 py-7 gap-5" style="border-radius: 12px; background-color: rgb(248 250 252);">
                <img class="border-solid border-2 border-sky-900 rounded-lg" src="/images/gencc-logo.jpg">
                <span class="text-xl font-extrabold text-center">Authorized Use Only</span>
                <Message :closable="false" :severity="loginError">{{ loginMessage }}</Message>
                <div class="inline-flex flex-col gap-2">
                    <label for="username" class="text-black font-semibold">Username</label>
                    <InputText id="username" v-model="username" size="large" class="bg-white border-0 p-4 text-primary-50"></InputText>
                </div>
                <div class="inline-flex flex-col gap-2">
                    <label for="password" class="text-black font-semibold">Password</label>
                    <InputText id="password" v-model="password" size="large" class="bg-white border-0 p-4 text-primary-50" type="password"></InputText>
                </div>
                <span class="text-base text-sky-700 text-right" @click="showFP">Forgot Password?</span>
                <div class="flex items-center gap-12">
                    <Button label="Sign-In" @click="closeCallback" outlined severity="success" class="p-4 w-full"></Button>
                    <Button label="Cancel" @click="visible = false" outlined severity="danger" class="p-4 w-full"></Button>
                </div>
            </div>
        </template>
    </Dialog>

    <Dialog
        v-model:visible="fpvisible"
        modal
        :pt="{
            mask: {
                style: 'backdrop-filter: blur(2px)'
            }
        }"
    >
        <template #container>
            <div class="flex flex-col px-10 py-7 gap-5" style="border-radius: 12px; background-color: rgb(248 250 252);">
                <img class="border-solid border-2 border-sky-900 rounded-lg" src="/images/gencc-logo.jpg">
                <span class="text-xl font-extrabold text-center">Password Reset</span>
                <Message :closable="false" :severity="fpMessageType">{{ fpMessage }}</Message>
                <div class="inline-flex flex-col gap-2">
                    <label for="email" class="text-black font-semibold">Email</label>
                    <InputText id="email" v-model="email" size="large" class="bg-white border-0 p-4 text-primary-50"></InputText>
                </div>
                <div class="flex items-center gap-12">
                    <Button label="Send Request Link" @click="fpcloseCallback" outlined severity="success" class="p-4 w-full"></Button>
                    <Button label="Cancel" @click="fpvisible = false" outlined severity="danger" class="p-4 w-full"></Button>
                </div>
            </div>
        </template>
    </Dialog>
   
</template>

<style>

</style>
