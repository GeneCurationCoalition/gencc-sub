<script setup>
defineProps({ recommendations: { type: Array, default: () => [] } })
const targetNote = target => {
    if (target.kind !== 'disease') return `Not an eligible disease identifier (${target.kind.replaceAll('_', ' ')})`
    if (target.availability !== 'active') return `Successor is ${target.availability}; review required`
    if (target.validation_error) return target.validation_error
    if (target.mondo_deprecated === true) return 'Replacement maps to a deprecated MONDO term; review required'
    if (!target.usable) return 'Review required; not a single usable successor'
    return 'Passes current disease validation'
}
const mondoStatus = target => target.mondo_deprecated === true ? 'deprecated'
    : target.mondo_deprecated === false || target.usable === true ? 'active' : 'status not captured in this scan'
</script>

<template>
    <div v-for="advice in recommendations" :key="advice.curie" class="bg-amber-50 border-l-4 border-amber-600 text-amber-900 p-4 mt-2 text-sm break-words">
        <p v-if="advice.contexts?.length" class="font-semibold">{{ advice.contexts.join(' / ') }}</p>
        <p>{{ advice.message }}</p>
        <p v-if="advice.unparsed && advice.targets.length" class="mt-2">Some replacement information could not be interpreted; review the source entry.</p>
        <ul v-if="advice.targets.length" class="mt-2 space-y-2">
            <li v-for="target in advice.targets" :key="target.curie">
                <span class="font-medium">{{ target.curie }}</span><span v-if="target.name"> — {{ target.name }}</span><span v-if="['active', 'deprecated'].includes(target.availability)"> ({{ target.availability }})</span>.
                {{ targetNote(target) }}.<span v-if="target.mondo && target.curie !== target.mondo"> Exact mapping: {{ target.mondo }} ({{ mondoStatus(target) }}).</span>
            </li>
        </ul>
        <p class="mt-2">Replacement advice does not change the submitted term or its exact mapping.</p>
    </div>
</template>
