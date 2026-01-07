<script setup lang="ts">
import { onMounted, ref } from 'vue'

const mounted = ref(false)

onMounted(() => {
  mounted.value = true
})
</script>

<template>
  <div class="feature-sections" :class="{ 'is-mounted': mounted }">
    <slot />
  </div>
</template>

<style scoped>
.feature-sections {
  max-width: 1152px;
  margin: 0 auto;
  padding: 0 24px 64px;
}

/* Ensure code is always visible */
.feature-sections :deep(pre),
.feature-sections :deep(code) {
  visibility: visible !important;
  opacity: 1 !important;
}

/* Force Shiki colors to apply */
.feature-sections :deep(.shiki) {
  background-color: var(--vp-code-block-bg) !important;
}

.feature-sections :deep(.shiki span) {
  color: var(--shiki-light, inherit) !important;
}

:root.dark .feature-sections :deep(.shiki span) {
  color: var(--shiki-dark, inherit) !important;
}
</style>
