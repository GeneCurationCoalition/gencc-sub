<script setup>
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

const props = defineProps({
    content: {
        type: String,
        default: ''
    }
})

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,  // Convert \n to <br>
    gfm: true      // GitHub Flavored Markdown
})

// Parse markdown and sanitize HTML
const renderedContent = computed(() => {
    if (!props.content) return ''

    // Parse markdown to HTML
    const html = marked.parse(props.content)

    // Sanitize to prevent XSS
    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'],
        ALLOWED_ATTR: ['href', 'target', 'rel']
    })
})
</script>

<template>
    <div class="markdown-content" v-html="renderedContent"></div>
</template>

<style scoped>
.markdown-content {
    @apply text-gray-700;
}

.markdown-content :deep(h1) {
    @apply text-2xl font-bold mt-4 mb-2;
}

.markdown-content :deep(h2) {
    @apply text-xl font-bold mt-3 mb-2;
}

.markdown-content :deep(h3) {
    @apply text-lg font-semibold mt-2 mb-1;
}

.markdown-content :deep(p) {
    @apply mb-2;
}

.markdown-content :deep(ul) {
    @apply list-disc list-inside mb-2;
}

.markdown-content :deep(ol) {
    @apply list-decimal list-inside mb-2;
}

.markdown-content :deep(li) {
    @apply ml-4;
}

.markdown-content :deep(code) {
    @apply bg-gray-100 px-1 rounded text-sm font-mono;
}

.markdown-content :deep(pre) {
    @apply bg-gray-100 p-2 rounded mb-2 overflow-x-auto;
}

.markdown-content :deep(pre code) {
    @apply bg-transparent p-0;
}

.markdown-content :deep(blockquote) {
    @apply border-l-4 border-gray-300 pl-4 italic my-2;
}

.markdown-content :deep(a) {
    @apply text-blue-600 hover:underline;
}

.markdown-content :deep(strong) {
    @apply font-bold;
}

.markdown-content :deep(em) {
    @apply italic;
}

.markdown-content :deep(table) {
    @apply border-collapse w-full mb-2;
}

.markdown-content :deep(th) {
    @apply border border-gray-300 bg-gray-100 px-2 py-1 font-semibold;
}

.markdown-content :deep(td) {
    @apply border border-gray-300 px-2 py-1;
}

.markdown-content :deep(hr) {
    @apply my-4 border-gray-300;
}
</style>
