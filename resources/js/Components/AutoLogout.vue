<script setup>
    import { ref, onMounted, onBeforeUnmount, onUnmounted } from 'vue';
    import { router } from '@inertiajs/vue3'
    import { useToast } from "primevue/usetoast";
    import ProgressBar from 'primevue/progressbar';

    // lifecycle hooks to ensure the event listeners are correctly added and removed
    onUnmounted(() => {
        if (interval.value) {
            clearInterval(interval.value);
        }
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

        resetTimer();
    });


    // local vars
    const events = ref(['click', 'mousemove', 'mousedown', 'scroll', 'keypress', 'load']);
    const warningTimer = ref(null);
    const toast = useToast();
    const visible = ref(false);
    const progress = ref(0);
    const interval = ref();

    /**
     * Start the 15 minute idle timer
     */
    function setTimers() {
        warningTimer.value = setTimeout(warningMessage, 900000); // 15 minutes
        //console.log('set timer');
        //visible.value = false
    }

    /**
     * Called when the timer fires, display the countdown
     */
    function warningMessage() {
        if (!visible.value) {
            toast.add({ severity: 'custom', summary: 'Idle Logout', group: 'headless', closeCallback: closeCallback });

            visible.value = true;
            progress.value = 0;

            if (interval.value) {
                clearInterval(interval.value);
            }

            interval.value = setInterval(() => {
                if (progress.value <= 100) {
                    progress.value = progress.value + 1;
                }

                if (progress.value >= 100) {
                    progress.value = 100;
                    console.log('visible ' + visible.value)
                    if (visible.value !== false)
                        router.post(route('logout'));
                    clearInterval(interval.value);
                }
            }, 1000);
        }
    }

    /**
     * Reset the timer
     */
    function resetTimer() {
        //toast.remove({});
        clearTimeout(warningTimer.value);
        //clearInterval(interval.value);
        //progress.value = 0;
        setTimers();
        //console.log('reset time');
    }

    /**
     * Reset everything when the user clicks on cancel
     */
    function closeCallback() {
        resetTimer();
        clearInterval(interval.value);
        progress.value = 0;
        visible.value = false;
    }

</script>


<template>
    <Toast position="top-center" group="headless" @close="visible = false">
        <template #container="{ message, closeCallback }">
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
                        <Button label="Cancel" text class="text-white p-0" @click="closeCallback"></Button>
                    </div>
                </div>
            </section>
        </template>
    </Toast>
</template>
