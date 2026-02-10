<script setup>
    import { ref, onMounted, onBeforeUnmount, onUnmounted } from 'vue';
    import { router, usePage } from '@inertiajs/vue3'
    import { useToast } from "primevue/usetoast";
    import ProgressBar from 'primevue/progressbar';

    // Get idle timeout from shared Inertia props (default: 15 minutes)
    const page = usePage();
    const idleTimeout = page.props.sessionIdleTimeout || 900000;

    // local vars
    const events = ref(['click', 'mousemove', 'mousedown', 'scroll', 'keypress', 'load']);
    const warningTimer = ref(null);
    const toast = useToast();
    const visible = ref(false);
    const progress = ref(0);
    const interval = ref(null);
    const loggingOut = ref(false);

    // lifecycle hooks to ensure the event listeners are correctly added and removed
    onUnmounted(() => {
        cleanupTimers();
    });

    onMounted(() => {
        events.value.forEach(function (event) {
            window.addEventListener(event, resetTimer);
        });

        setTimers();
    });

    onBeforeUnmount(() => {
        events.value.forEach(function (event) {
            window.removeEventListener(event, resetTimer);
        });

        cleanupTimers();
    });

    /**
     * Clean up all timers and intervals
     */
    function cleanupTimers() {
        if (warningTimer.value) {
            clearTimeout(warningTimer.value);
            warningTimer.value = null;
        }
        if (interval.value) {
            clearInterval(interval.value);
            interval.value = null;
        }
    }

    /**
     * Start the idle timer (configured via SESSION_IDLE_TIMEOUT env var)
     */
    function setTimers() {
        // Clear any existing timer first
        if (warningTimer.value) {
            clearTimeout(warningTimer.value);
        }
        warningTimer.value = setTimeout(warningMessage, idleTimeout);
    }

    /**
     * Called when the timer fires, display the countdown
     */
    function warningMessage() {
        // Don't show if already visible or logging out
        if (visible.value || loggingOut.value) {
            return;
        }

        toast.add({ severity: 'custom', summary: 'Idle Logout', group: 'headless' });

        visible.value = true;
        progress.value = 0;

        // Clear any existing interval
        if (interval.value) {
            clearInterval(interval.value);
        }

        interval.value = setInterval(() => {
            progress.value = progress.value + 1;

            if (progress.value >= 100) {
                performLogout();
            }
        }, 1000);
    }

    /**
     * Perform the actual logout
     */
    function performLogout() {
        if (loggingOut.value) return;

        loggingOut.value = true;
        cleanupTimers();
        toast.removeGroup('headless');
        visible.value = false;

        router.post(route('logout'));
    }

    /**
     * Reset the timer - called on user activity
     */
    function resetTimer() {
        // If currently logging out, don't reset
        if (loggingOut.value) return;

        // If dialog is visible, dismiss it and reset
        if (visible.value) {
            dismissDialog();
        }

        // Reset the idle timer
        setTimers();
    }

    /**
     * Dismiss the dialog and reset everything
     */
    function dismissDialog() {
        cleanupTimers();
        toast.removeGroup('headless');
        progress.value = 0;
        visible.value = false;
    }

    /**
     * Called when user clicks Cancel button
     */
    function onCancelClick() {
        dismissDialog();
        setTimers();
    }

</script>


<template>
    <Toast position="top-center" group="headless" @close="dismissDialog">
        <template #container="{ message }">
            <section class="flex p-3 gap-3 w-full bg-black/90 shadow-md" >
                <i class="pi pi-sign-out text-primary-500 text-2xl"></i>
                <div class="flex flex-col gap-3 w-full">
                    <p class="m-0 font-semibold text-base leading-none text-white">{{ message.summary }}</p>
                    <p class="m-0 text-base leading-none text-surface-700 dark:text-surface-0">{{ message.detail }}</p>
                    <div class="flex flex-col gap-2">
                        <ProgressBar :value="progress" :showValue="false" :style="{ height: '4px' }"></ProgressBar>
                        <label class="text-right text-xs text-white">Logout in {{ 100 - progress }} seconds</label>
                    </div>
                    <div class="flex gap-3 mb-3 place-content-center">
                        <Button label="Cancel" text class="text-white p-0" @click="onCancelClick"></Button>
                    </div>
                </div>
            </section>
        </template>
    </Toast>
</template>
