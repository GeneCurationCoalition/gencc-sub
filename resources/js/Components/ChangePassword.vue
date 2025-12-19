<script setup>
    import { ref, watch } from 'vue'

    const props = defineProps(['visible']);


    const oldpasswd = ref('');
    const newpasswd = ref('');
    const confirmpw = ref('');

    const localVisible = defineModel('visible')


    watch(() => props.visible, (first, second) => {
        localVisible.value = first;
    });

    const emit = defineEmits(['input_dialog_close', 'input_password_item'])

    function closeCallback()
    {

        if (newpasswd.value != confirmpw.value)
            return;

        // send selection back to the parent
        emit('input_password_item', {
            'old': oldpasswd.value,
            'new': newpasswd.value
        });
        emit('input_dialog_close');

    }

    async function checkEntry() {

        try {
            const response = await axios.get('/api/lookup/disease/' + localInput.value);

            const nodata = response.data.hasOwnProperty('status_code');

            if ( !nodata )
            {
                cardTitle.value = response.data.name + '   (' + response.data.curie + ')';
                cardBody.value = response.data.description;
                mondo.value = response.data.curie;
            }

        } catch (error) {
             console.error(error);
        }
    }
</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="localVisible" modal header="header" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold text-2xl">Change Password</span>
                </div>
            </template>

            
            <div class="grid grid-cols-5">
                <div class="flex items-center col-span-2 gap-3 mb-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Enter Old Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <InputText type="password" v-model="oldpasswd" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center col-span-2 gap-3 mb-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Enter New Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <InputText type="password" v-model="newpasswd" class="flex-auto" autocomplete="off" required />
                </div>
                <div class="flex items-center col-span-2 gap-3 mb-3">
                    <label for="newInput" class="flex items-center font-semibold w-6rem">Confirm New Password</label>
                </div>
                <div class="flex items-center col-span-3 gap-3 mb-3">
                    <InputText type="password" v-model="confirmpw" class="flex-auto" autocomplete="off" required/>
                </div>
            </div>
                
            <template #footer>
                <div class="flex gap-8 bg-slate-50 m-0 p-4">
                    <Button label="Update" @click="closeCallback" outlined severity="info" class="ml-4 p-3" :disabled="disabled" />
                    <Button label="Cancel" @click="visible = false" severity="secondary" class="mr-4 p-3" />
                </div>
            </template>              
        </Dialog>
    </div>
</template>