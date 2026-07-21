<script setup>

import {ref, watch, computed} from 'vue'
import {useForm} from 'vee-validate';
import * as yup from 'yup';

const props = defineProps(['input', 'visible', 'title', 'label']);

// component side validations (mechanism is optional to allow comment-only updates)
const schema = yup.object({
  mechanism: yup.string().nullable().label('Mechanism of Disease'),
  comments: yup.string().label('Comment')
});

const {defineField, handleSubmit, resetForm, errors} = useForm({
  validationSchema: schema,
});

const [mechanism] = defineField('mechanism');
const [comments] = defineField('comments');

const disabled = computed(() => {
  return Object.keys(errors.value).length !== 0
})

// other local vars
const visible = defineModel('visible');

const options = [
  {value: "GENCC:200001", label: "Gain of Function"},
  {value: "GENCC:200002", label: "Loss of Function"},
  {value: "GENCC:200003", label: "Not Loss of Function"},
  {value: "GENCC:200004", label: "Dominant Negative"},
];

// both the child and parent can trigger visibility, so we need a watcher
watch(() => props.visible, (first, second) => {
  visible.value = first;
});

const emit = defineEmits(['select_mechanism_close', 'select_mechanism_item'])

/**
 * Callback function when user clicks on the update button
 */
function closeCallback() {
  // one last check for errors
  if (disabled.value === true)
    return;

  emit('select_mechanism_item', {curie: mechanism.value, comments: comments.value});
  emit('select_dialog_close');

}

/**
 * Function to initialize the local models used in child components with props values
 */

function initializeInput() {
  // Initialize mechanism and comments from props
  // Handle cases: undefined, null, or object with empty/filled values
  console.log('ChangeMechanism - initializeInput called with:', props.input);

  if (!props.input || typeof props.input !== 'object') {
    // No mechanism object at all
    console.log('ChangeMechanism - No mechanism object, setting to null');
    mechanism.value = null;
    comments.value = '';
  } else {
    // Mechanism object exists - extract values
    // Set mechanism to null if id is empty string or null
    const mechId = (props.input.id && props.input.id !== '') ? props.input.id : null;
    mechanism.value = mechId;

    const commentValue = props.input.comments || '';
    comments.value = commentValue;

    console.log('ChangeMechanism - Setting mechanism to:', mechId);
    console.log('ChangeMechanism - Setting comment to:', commentValue);
    console.log('ChangeMechanism - props.input.comments:', props.input.comments);
  }
}

</script>

<template>
  <div class="col-span-12 ">
    <Dialog v-model:visible="visible" modal @show="initializeInput" class="ring-8 ring-sky-500"
            :style="{ width: '50rem' }">
      <template #header>
        <div class="inline-flex align-items-center justify-content-center gap-2">
          <span class="font-bold text-2xl">{{ title }}</span>
        </div>
      </template>

      <div class="grid grid-cols-4">
        <div class="flex items-center gap-3">
          <label for="newInput" class="flex items-center font-semibold w-6rem">{{ label }}</label>
        </div>
        <div class="flex items-center col-span-3 gap-3">
          <Dropdown v-model="mechanism" :options="options" optionLabel="label" optionValue="value"
                    placeholder="Select a classification" class="w-full md:w-14rem"/>
        </div>
        <div class="flex items-center gap-3">
          &nbsp;
        </div>
        <div class="flex items-center col-span-3">
          <small id="username-help" class="text-red-600">{{ errors.mechanism }}</small>
        </div>

        <div class="flex items-center gap-3 mt-3">
          <label for="newInput" class="flex items-center font-semibold w-6rem">Comments</label>
        </div>
        <div class="flex items-center col-span-3 gap-3 mt-3">
          <Textarea v-model="comments" class="flex-auto" rows="10" cols="30" autocomplete="off"/>
        </div>
        <div class="flex items-center gap-3">
          &nbsp;
        </div>
        <div class="flex items-center col-span-3">
          <small id="username-help" class="text-red-600">{{ errors.comments }}</small>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-8 bg-slate-50 m-0 p-4">
          <Button label="Update" @click="closeCallback" outlined severity="info" class="ml-4 p-3" :disabled="disabled"/>
          <Button label="Cancel" @click="visible = false" severity="secondary" class="mr-4 p-3"/>
        </div>
      </template>
    </Dialog>
  </div>
</template>
