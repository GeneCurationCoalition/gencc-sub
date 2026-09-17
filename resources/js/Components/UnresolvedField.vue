<script setup>
/**
 * A reference field whose submitted value did not resolve: shows what was
 * submitted, marked as unresolved, with the error as a tooltip.
 * See utils/submissionFields.js.
 */
defineProps({
    // {message, id, name} from unresolvedField()
    unresolved: { type: Object, required: true },
    // Omit the submitted name, for narrow table cells
    compact: { type: Boolean, default: false },
})
</script>

<template>
    <div v-tooltip.top="unresolved.message" class="cursor-help">
        <div class="font-medium text-red-700">
            <i class="pi pi-exclamation-circle text-xs mr-1"></i>{{ unresolved.id || 'None submitted' }}
        </div>
        <div class="text-xs text-red-600">
            Not resolved<span v-if="unresolved.name && !compact"> · submitted as "{{ unresolved.name }}"</span>
        </div>
    </div>
</template>
