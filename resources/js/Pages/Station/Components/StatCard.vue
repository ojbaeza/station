<script setup>
defineProps({
    title: String,
    value: [String, Number],
    icon: String,
    color: {
        type: String,
        default: 'indigo'
    },
    trend: {
        type: String,
        default: null
    }
})

const colorConfig = {
    blue: { icon: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-50 dark:bg-blue-500/10' },
    green: { icon: 'text-green-600 dark:text-green-400', bg: 'bg-green-50 dark:bg-green-500/10' },
    yellow: { icon: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-50 dark:bg-amber-500/10' },
    red: { icon: 'text-red-600 dark:text-red-400', bg: 'bg-red-50 dark:bg-red-500/10' },
    indigo: { icon: 'text-indigo-600 dark:text-indigo-400', bg: 'bg-indigo-50 dark:bg-indigo-500/10' },
    purple: { icon: 'text-purple-600 dark:text-purple-400', bg: 'bg-purple-50 dark:bg-purple-500/10' },
    cyan: { icon: 'text-cyan-600 dark:text-cyan-400', bg: 'bg-cyan-50 dark:bg-cyan-500/10' },
    emerald: { icon: 'text-emerald-600 dark:text-emerald-400', bg: 'bg-emerald-50 dark:bg-emerald-500/10' },
}

const iconPaths = {
    clock: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    refresh: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
    check: 'M5 13l4 4L19 7',
    'x-circle': 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    'trending-up': 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
    exclamation: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
    server: 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01',
    users: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    play: 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664zM21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    x: 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    template: 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm0 8a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zm12 0a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z',
    default: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
}
</script>

<template>
    <div class="bg-card border border-border rounded-lg p-5">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div :class="[colorConfig[color]?.bg || colorConfig.indigo.bg, 'rounded-lg p-2.5']">
                    <svg :class="[colorConfig[color]?.icon || colorConfig.indigo.icon, 'h-5 w-5']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[icon] || iconPaths.default" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ title }}
                    </p>
                    <p class="mt-0.5 text-2xl font-semibold text-foreground tabular-nums tracking-tight">
                        {{ value }}
                    </p>
                </div>
            </div>

            <div v-if="trend" class="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium"
                :class="trend === 'up' ? 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'">
                <svg v-if="trend === 'up'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                </svg>
                <svg v-else class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
            </div>
        </div>
    </div>
</template>
