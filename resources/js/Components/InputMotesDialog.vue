<script setup>
import { ref, watch } from 'vue'

const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

const localInput = defineModel('input');
const localVisible = defineModel('visible');

const cardTitle = ref('');
const cardBody = ref('');

watch(() => props.visible, (first, second) => {
      localVisible.value = first;
});

watch(() => localInput.value, (first, second) => {
      //input.value = first;
      //console.log(first);
});

const emit = defineEmits(['input_dialog_close'])

function closeCallback()
{
    // need to get this back up to the parent somehow
    emit('input_dialog_close');

}

function checkEntry()
{
    // need to get this back up to the parent somehow

}

</script>

<template>
    <div class="col-span-12 ">
        <Dialog v-model:visible="localVisible" modal header="{{ header }}" :style="{ width: '50rem' }">
            <template #header>
                <div class="inline-flex align-items-center justify-content-center gap-2">
                    <span class="font-bold">{{ title }}</span>
                </div>
            </template>
            <div class="flex align-items-center gap-3 mb-3">
                <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
                <InputText type="text" v-model="localInput" class="flex-auto" autocomplete="off" />
                <Button icon="pi pi-search" @click="checkEntry()"/>
            </div>

            <Card>
                <template #title>{{ cardTitle }}</template>
                <template #content>
                    <p class="m-0">
                        {{ input }}
                        {{ cardBody }}
                    </p>
                </template>
            </Card>
                
            <div class="flex align-items-center gap-3">
                <Button label="Update" @click="closeCallback" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
                <Button label="Cancel" @click="localVisible = false" text class="p-3 w-full text-primary-50 border-1 border-white-alpha-30 hover:bg-white-alpha-10"></Button>
            </div>              
        </Dialog>
    </div>
</template>