<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import StatCard from './Components/StatCard.vue'
import MetricsChart from './Components/MetricsChart.vue'

const props = defineProps({
    throughput: Number,
    avgWaitTime: Number,
    avgProcessingTime: Number,
    failureRate: Number,
    jobsProcessed: Number,
    jobsFailed: Number,
    period: String,
    timeSeries: { type: Array, default: () => [] },
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
    router.get(route('station.metrics'), params, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch(selectedPeriod, changePeriod)
watch(selectedConnection, changePeriod)

const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(30000) })
const pollTimer = ref(null)

const refreshMetrics = () => {
    router.reload({
        only: ['throughput', 'avgWaitTime', 'avgProcessingTime', 'failureRate', 'jobsProcessed', 'jobsFailed', 'timeSeries'],
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
    if (enabled) { pollTimer.value = setInterval(refreshMetrics, ms) }
}, { immediate: true })

watch(() => autoRefresh.focused?.value, (focused) => {
    if (focused && autoRefresh.enabled.value) refreshMetrics()
})

onUnmounted(stopPolling)

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0'
    return num.toLocaleString()
}

// Chart data derived from timeSeries prop
const chartLabels = computed(() => {
    const useDates = ['24h', '7d'].includes(selectedPeriod.value)
    return props.timeSeries.map(p => {
        const d = new Date(p.timestamp)
        if (useDates) {
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
                + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        }
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    })
})

const throughputData = computed(() => props.timeSeries.map(p => p.jobs_processed))
const failureData = computed(() => props.timeSeries.map(p => p.jobs_failed))
const waitTimeData = computed(() => props.timeSeries.map(p => p.avg_wait_time))
const processTimeData = computed(() => props.timeSeries.map(p => p.avg_processing_time))

</script>

<template>
    <Head title="Metrics - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-foreground">Metrics</h1>
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

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    title="Throughput"
                    :value="`${formatNumber(throughput)}/min`"
                    icon="trending-up"
                    color="emerald"
                />
                <StatCard
                    title="Jobs Processed"
                    :value="formatNumber(jobsProcessed)"
                    icon="check"
                    color="green"
                />
                <StatCard
                    title="Jobs Failed"
                    :value="formatNumber(jobsFailed)"
                    icon="x-circle"
                    color="red"
                />
                <StatCard
                    title="Failure Rate"
                    :value="`${(failureRate * 100)?.toFixed(2) || 0}%`"
                    icon="exclamation"
                    :color="failureRate > 0.05 ? 'red' : 'green'"
                />
                <StatCard
                    title="Avg Wait Time"
                    :value="`${avgWaitTime?.toFixed(2) || 0}s`"
                    icon="clock"
                    color="blue"
                />
                <StatCard
                    title="Avg Process Time"
                    :value="`${avgProcessingTime?.toFixed(2) || 0}s`"
                    icon="refresh"
                    color="purple"
                />
            </div>

            <!-- Time-Series Charts -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <MetricsChart
                    title="Throughput Over Time"
                    :labels="chartLabels"
                    :data="throughputData"
                    color="#10b981"
                    unit="jobs"
                />
                <MetricsChart
                    title="Failures Over Time"
                    :labels="chartLabels"
                    :data="failureData"
                    color="#ef4444"
                    unit="jobs"
                />
                <MetricsChart
                    title="Avg Wait Time"
                    :labels="chartLabels"
                    :data="waitTimeData"
                    color="#3b82f6"
                    unit="ms"
                />
                <MetricsChart
                    title="Avg Processing Time"
                    :labels="chartLabels"
                    :data="processTimeData"
                    color="#8b5cf6"
                    unit="ms"
                />
            </div>

            <!-- Info Box -->
            <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-emerald-300">
                            Metrics are collected as jobs are processed and aggregated based on the selected time period.
                            Historical data is retained for 7 days by default.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </StationLayout>
</template>
