<script setup>

    import { ref, watch } from 'vue'

    const props = defineProps(['visible', 'title', 'label']);

    const localVisible = defineModel('visible');
    const selectedItem = defineModel('selectedItem');

    // watch the parentsclose state and pass back when changed
    watch(() => props.visible, (first, second) => {
        localVisible.value = first;
    });

    const emit = defineEmits(['select_date_close', 'select_date_item'])

    function closeCallback()
    {
        // send selected item back to the parent
        emit('select_date_item', selectedItem.value);
        emit('select_date_close');

    }

    function initializeSelect()
    {
        selectedItem.value = "";
    }

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="localVisible" modal @show="initializeSelect" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold">{{ title }}</span>
                </div>
            </template>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                <Calendar v-model="selectedItem" showIcon iconDisplay="input" placeholder="Select or enter a date" class="w-full md:w-14rem" />
            </div>
                
            <div class="flex align-items-center gap-3">
                <Button label="Update" @click="closeCallback" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
                <Button label="Cancel" @click="localVisible = false" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
            </div>              
        </Dialog>
    </div>
</template>