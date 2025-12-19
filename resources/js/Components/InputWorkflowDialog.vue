<script setup>
    import { ref, watch } from 'vue'
    import Chips from 'primevue/chips';

    const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

    const localPublic = defineModel('public');
    const localPrivate = defineModel('private');
    const localReasons = defineModel('reasons');
    const localDescription = defineModel('description');
    const localVisible = defineModel('visible');

    watch(() => props.visible, (first, second) => {
        localVisible.value = first;
    });

    const emit = defineEmits(['input_version_close', 'input_version_item'])

    function closeCallback()
    {
        // need to get this back up to the parent somehow
        emit('input_version_item', 
            { public: localPublic.value, private: localPrivate.value, reasons: localReasons.value, description: localDescription.value });
        emit('input_version_close');

    }

    function initializeInput()
    {
        console.log(props.input);

        localPublic.value = props.input.display;
        localPrivate.value = props.input.internal;
        localReasons.value = props.input.reasons;
        localDescription.value = props.input.description;
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="localVisible" @show="initializeInput" modal header="{{ header }}" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold">{{ title }}</span>
                </div>
            </template>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">Display</label>
                <InputText type="text" v-model="localPublic" class="flex-auto" autocomplete="off" />
            </div>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">Internal</label>
                <InputText type="text" v-model="localPrivate" class="flex-auto" autocomplete="off" />
            </div>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">Reasons</label>
                <Chips v-model="localReasons" class="flex-auto"  autocomplete="off" />
            </div>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">Description</label>
                <Textarea v-model="localDescription" rows="5" cols="30" />
            </div>
                
            <div class="flex align-items-center gap-3">
                <Button label="Update" @click="closeCallback" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
                <Button label="Cancel" @click="localVisible = false" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
            </div>              
        </Dialog>
    </div>
</template>