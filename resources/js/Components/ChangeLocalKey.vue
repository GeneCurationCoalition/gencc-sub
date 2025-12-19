<script setup>
import { ref, watch, computed } from 'vue'
import { useForm } from 'vee-validate';
import * as yup from 'yup';

const props = defineProps(['visible', 'input', 'header', 'title', 'label']);

// component side validations
const schema = yup.object({
  local_key: yup.string().label('Local Key').max(248),
});

const { defineField, handleSubmit, resetForm} = useForm({
  validationSchema: schema,
});

const [local_key] = defineField('local_key');

// local vars
const visible = defineModel('visible');

// computed property for disabling the update button
const disabled = computed(() => {
  return !local_key.value || local_key.value.trim() === '';
});

// both the child and parent can trigger visibility, so we need a watcher
watch(() => props.visible, (first, second) => {
  visible.value = first;
});

const emit = defineEmits(['input_local_key_close', 'input_local_key_item'])

/**
 * Callback function when user clicks on the update button
 */
function closeCallback()
{
  // need to get this back up to the parent somehow
  emit('input_local_key_item', local_key.value)

}

/**
 * Function to initialize the local models used in child components with props values
 */
function initializeInput()
{
  local_key.value = props.input;
}


</script>

<template>
  <div class="col-span-12 ">
    <Dialog v-model:visible="visible" modal @show="initializeInput" header="{{ header }}" class="ring-8 ring-sky-500" :style="{ width: '50rem' }">
      <template #header>
        <div class="inline-flex align-items-center justify-content-center gap-2">
          <span class="font-bold text-2xl">{{ title }}</span>
        </div>
      </template>

      <div class="grid grid-cols-4">
        <div class="flex items-center gap-3 mt-3">
          <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
        </div>
        <div class="flex items-center col-span-3 gap-3 mt-3">
          <InputText type="text" v-model="local_key" class="flex-auto" autocomplete="off" />
        </div>
        <div class="flex items-center gap-3">
          &nbsp
        </div>
      </div>

      <template #footer>
        <div class="flex gap-8 bg-slate-50 m-0 p-4">
          <Button label="Update" @click="closeCallback" outlined severity="info" class="ml-4 p-3" />
          <Button label="Cancel" @click="visible = false" severity="secondary" class="mr-4 p-3" />
        </div>
      </template>
    </Dialog>
  </div>
</template>