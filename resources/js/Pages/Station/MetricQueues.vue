<script setup>
import { Head, router } from '@inertiajs/vue3'
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import MetricsChart from './Components/MetricsChart.vue'

const props = defineProps({
    entries: { type: Object, default: () => ({}) },
    timeSeries: { type: Object, default: () => ({}) },
    period: String,
    connections: { type: Array, default: () => [] },
    currentConnection: { type: String, default: null },
})

const selectedPeriod = ref(props.period || '1h')
const selectedConnection = ref(props.currentConnection || '')

const periods = [
    { value: '5m', label: 'Last 5 Min' },
    { value: '15m', label: 'Last 15 Min' },
    { value: '1h', label: 'Last Hour' },
    { value: '6h', label: 'Last 6 Hours' },
    { value: '24h', label: 'Last 24 Hours' },
    { value: '7d', label: 'Last 7 Days' },
]

const changePeriod = () => {
    const params = { period: selectedPeriod.value }
    if (selectedConnection.value) {
        params.connection = selectedConnection.value
    }
    router.get(route('station.metrics.queues'), params, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch(selectedPeriod, changePeriod)
watch(selectedConnection, changePeriod)

const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(30000) })
const pollTimer = ref(null)

const refreshData = () => {
    router.reload({
        only: ['entries', 'timeSeries'],
        preserveScroll: true,
    })
}

const stopPolling = () => { clearInterval(pollTimer.value); pollTimer.value = null }

const effectiveInterval = computed(() => {
    const base = autoRefresh.interval.value
    return autoRefresh.focused?.value === false ? base * 6 : base
})

watch([() => autoRefresh.enabled.value, effectiveInterval], ([enabled, ms]) => {
    stopPolling()
    if (enabled) { pollTimer.value = setInterval(refreshData, ms) }
}, { immediate: true })

watch(() => autoRefresh.focused?.value, (focused) => {
    if (focused && autoRefresh.enabled.value) refreshData()
})

onUnmounted(stopPolling)

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0'
    return num.toLocaleString()
}

const formatDuration = (ms) => {
    if (ms === null || ms === undefined || ms === 0) return '0ms'
    if (ms < 1000) return `${Math.round(ms)}ms`
    return `${(ms / 1000).toFixed(1)}s`
}

const sortedEntries = computed(() => {
    return Object.entries(props.entries)
        .map(([key, entry]) => ({ key, ...entry }))
        .sort((a, b) => a.connection.localeCompare(b.connection) || a.queue.localeCompare(b.queue))
})

const maxQueueSize = computed(() => {
    return sortedEntries.value.reduce((max, q) => Math.max(max, q.size || 0), 0)
})

const chartLabels = (key) => {
    const series = props.timeSeries[key] || []
    const useDates = ['24h', '7d'].includes(selectedPeriod.value)
    return series.map(p => {
        const d = new Date(p.timestamp)
        if (useDates) {
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
                + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        }
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    })
}

const throughputData = (key) => {
    const series = props.timeSeries[key] || []
    return series.map(p => p.jobs_processed)
}

const runtimeData = (key) => {
    const series = props.timeSeries[key] || []
    return series.map(p => p.avg_processing_time)
}

const expandedQueues = ref(new Set())
const toggleQueue = (key) => {
    if (expandedQueues.value.has(key)) {
        expandedQueues.value.delete(key)
    } else {
        expandedQueues.value.add(key)
    }
}
</script>

<template>
    <Head title="Queue Metrics - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-foreground">Queue Breakdown</h1>
                <div class="flex items-center gap-3">
                    <select
                        v-if="connections.length > 0"
                        v-model="selectedConnection"
                        class="block w-full min-w-[200px] pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                    >
                        <option value="">All Connections</option>
                        <option v-for="conn in connections" :key="conn" :value="conn">
                            {{ conn }}
                        </option>
                    </select>
                    <select
                        v-model="selectedPeriod"
                        class="block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                    >
                        <option v-for="period in periods" :key="period.value" :value="period.value">
                            {{ period.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Queue Cards -->
            <div v-if="sortedEntries.length > 0" class="space-y-6">
                <div
                    v-for="q in sortedEntries"
                    :key="q.key"
                    class="bg-card border border-border rounded-xl overflow-hidden"
                    :class="{ 'opacity-60': q.paused }"
                >
                    <button
                        @click="toggleQueue(q.key)"
                        class="flex items-center justify-between w-full px-6 py-4 text-left hover:bg-secondary/30 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <svg
                                class="h-4 w-4 text-muted-foreground transition-transform duration-200 flex-shrink-0"
                                :class="expandedQueues.has(q.key) ? 'rotate-90' : ''"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                            <span class="text-base font-semibold text-foreground">{{ q.queue }}</span>
                            <span class="px-1.5 py-0.5 rounded bg-secondary text-[10px] text-muted-foreground">{{ q.connection }}</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="hidden sm:flex items-center gap-4 text-xs text-muted-foreground">
                                <span><span class="font-medium text-foreground tabular-nums">{{ formatNumber(q.size) }}</span> pending</span>
                                <span><span class="font-medium text-foreground tabular-nums">{{ q.throughput }}</span>/min</span>
                                <span><span class="font-medium text-foreground tabular-nums">{{ formatNumber(q.processed) }}</span> processed</span>
                                <span :class="q.failed > 0 ? 'text-red-500' : ''"><span class="font-medium tabular-nums" :class="q.failed > 0 ? 'text-red-500' : 'text-foreground'">{{ formatNumber(q.failed) }}</span> failed</span>
                                <span><span class="font-medium text-foreground tabular-nums">{{ formatDuration(q.avg_runtime) }}</span> avg</span>
                            </div>
                            <span v-if="q.paused" class="px-2 py-0.5 inline-flex text-xs font-medium rounded bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Paused</span>
                            <span v-else class="px-2 py-0.5 inline-flex text-xs font-medium rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Active</span>
                        </div>
                    </button>
                    <!-- Mobile stats row (visible only on small screens) -->
                    <div class="sm:hidden px-6 pb-3 grid grid-cols-3 gap-3 text-xs text-muted-foreground">
                        <div><span class="font-medium text-foreground tabular-nums">{{ formatNumber(q.size) }}</span> pending</div>
                        <div><span class="font-medium text-foreground tabular-nums">{{ q.throughput }}</span>/min</div>
                        <div><span class="font-medium text-foreground tabular-nums">{{ formatNumber(q.processed) }}</span> processed</div>
                        <div :class="q.failed > 0 ? 'text-red-500' : ''"><span class="font-medium tabular-nums" :class="q.failed > 0 ? 'text-red-500' : 'text-foreground'">{{ formatNumber(q.failed) }}</span> failed</div>
                        <div><span class="font-medium text-foreground tabular-nums">{{ formatDuration(q.avg_runtime) }}</span> avg</div>
                    </div>
                    <transition
                        enter-active-class="transition-all duration-200 ease-out"
                        leave-active-class="transition-all duration-150 ease-in"
                        enter-from-class="opacity-0 max-h-0"
                        enter-to-class="opacity-100 max-h-[2000px]"
                        leave-from-class="opacity-100 max-h-[2000px]"
                        leave-to-class="opacity-0 max-h-0"
                    >
                        <div v-show="expandedQueues.has(q.key)" class="overflow-hidden border-t border-border">
                            <div class="px-6 py-5">
                                <div class="grid grid-cols-5 gap-4 mb-4">
                                    <div>
                                        <p class="text-xs text-muted-foreground">Pending</p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <p class="text-lg font-semibold text-foreground">{{ formatNumber(q.size) }}</p>
                                            <div class="flex-1 bg-secondary rounded-full h-1.5">
                                                <div
                                                    class="h-1.5 rounded-full bg-indigo-500 transition-all"
                                                    :style="{ width: maxQueueSize > 0 ? Math.max((q.size / maxQueueSize) * 100, q.size > 0 ? 4 : 0) + '%' : '0%' }"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Throughput</p>
                                        <p class="text-lg font-semibold text-foreground mt-1">{{ q.throughput }}/min</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Processed</p>
                                        <p class="text-lg font-semibold text-foreground mt-1">{{ formatNumber(q.processed) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Failed</p>
                                        <p class="text-lg font-semibold mt-1" :class="q.failed > 0 ? 'text-red-500' : 'text-foreground'">{{ formatNumber(q.failed) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Avg Runtime</p>
                                        <p class="text-lg font-semibold text-foreground mt-1">{{ formatDuration(q.avg_runtime) }}</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <MetricsChart
                                        title="Throughput"
                                        :labels="chartLabels(q.key)"
                                        :data="throughputData(q.key)"
                                        color="#6366f1"
                                        unit="jobs"
                                    />
                                    <MetricsChart
                                        title="Runtime"
                                        :labels="chartLabels(q.key)"
                                        :data="runtimeData(q.key)"
                                        color="#8b5cf6"
                                        unit="ms"
                                    />
                                </div>
                            </div>
                        </div>
                    </transition>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-card border border-border rounded-xl p-12 text-center">
                <p class="text-sm text-muted-foreground">No queue data available for the selected period.</p>
            </div>
        </div>
    </StationLayout>
</template>
