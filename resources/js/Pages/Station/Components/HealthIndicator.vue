<script setup>
import { computed } from 'vue'

const props = defineProps({
    health: Object
})

const statusConfig = computed(() => {
    const status = props.health?.status || 'unknown'

    return {
        healthy: {
            dot: 'bg-green-500',
            text: 'text-green-700 dark:text-green-400',
            bg: 'bg-green-50 border-green-200 dark:bg-green-500/10 dark:border-green-500/20',
            label: 'Healthy',
        },
        degraded: {
            dot: 'bg-amber-500',
            text: 'text-amber-700 dark:text-amber-400',
            bg: 'bg-amber-50 border-amber-200 dark:bg-amber-500/10 dark:border-amber-500/20',
            label: 'Degraded',
        },
        unhealthy: {
            dot: 'bg-red-500',
            text: 'text-red-700 dark:text-red-400',
            bg: 'bg-red-50 border-red-200 dark:bg-red-500/10 dark:border-red-500/20',
            label: 'Unhealthy',
        },
        unknown: {
            dot: 'bg-zinc-400',
            text: 'text-zinc-600 dark:text-zinc-400',
            bg: 'bg-zinc-50 border-zinc-200 dark:bg-zinc-500/10 dark:border-zinc-500/20',
            label: 'Unknown',
        }
    }[status]
})
</script>

<template>
    <div :class="[statusConfig.bg, 'px-3 py-1.5 rounded-full flex items-center gap-2 border']">
        <span :class="[statusConfig.dot, 'inline-flex rounded-full h-2 w-2']"></span>
        <span :class="[statusConfig.text, 'text-sm font-medium']">
            {{ statusConfig.label }}
        </span>
    </div>
</template>
