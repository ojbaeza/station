<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import HealthIndicator from './Components/HealthIndicator.vue'
import MetricsChart from './Components/MetricsChart.vue'

const props = defineProps({
    stats: Object,
    health: Object,
    pausedQueues: Object,
    activeBatches: Array,
    recentAlerts: Object,
    recentFailed: Object,
    timeSeries: Array,
})

const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(10000) })
const pollTimer = ref(null)
const isRefreshing = ref(false)

const refreshData = () => {
    if (isRefreshing.value) return
    isRefreshing.value = true
    router.reload({
        only: ['stats', 'health', 'pausedQueues', 'activeBatches', 'recentAlerts', 'recentFailed', 'timeSeries'],
        preserveScroll: true,
        onFinish: () => { isRefreshing.value = false },
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

// Helpers
const truncate = (str, len = 60) => {
    if (!str) return '-'
    return str.length > len ? str.substring(0, len) + '...' : str
}

const timeAgo = (dateStr) => {
    if (!dateStr) return '-'
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000)
    if (diff < 60) return `${diff}s ago`
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

const getProgress = (batch) => {
    if (!batch.total_jobs || batch.total_jobs === 0) return 0
    return Math.round(((batch.processed_jobs || 0) / batch.total_jobs) * 100)
}

const retryJob = async (id) => {
    try {
        const response = await fetch(route('station.api.failed.retry', id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
        if (response.ok) refreshData()
    } catch {
        // silently fail
    }
}

// Chart data
const chartLabels = computed(() => {
    if (!props.timeSeries || props.timeSeries.length === 0) return []
    return props.timeSeries.map(p => {
        const d = new Date(p.timestamp || p.recorded_at)
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    })
})

const chartData = computed(() => {
    if (!props.timeSeries || props.timeSeries.length === 0) return []
    return props.timeSeries.map(p => p.jobs_processed ?? 0)
})

// Status colors
const statusColors = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-500/10 dark:text-gray-400',
}

const severityDot = {
    critical: 'bg-red-500',
    warning: 'bg-amber-500',
    info: 'bg-blue-500',
}
</script>

<template>
    <Head title="Station Dashboard" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">Dashboard</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Monitor your queue performance and job status</p>
                </div>
                <HealthIndicator :health="health" />
            </div>

            <!-- Compact Stats Bar -->
            <div class="bg-card border border-border rounded-lg p-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-4">
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Pending</p>
                        <p class="text-xl font-bold text-amber-500 tabular-nums">{{ stats.totals.pending.toLocaleString() }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Processing</p>
                        <p class="text-xl font-bold text-blue-500 tabular-nums">{{ stats.totals.processing.toLocaleString() }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Completed</p>
                        <p class="text-xl font-bold text-green-500 tabular-nums">{{ stats.totals.completed.toLocaleString() }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Failed</p>
                        <p class="text-xl font-bold text-red-500 tabular-nums">{{ stats.totals.failed.toLocaleString() }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Throughput</p>
                        <p class="text-xl font-bold text-emerald-500 tabular-nums">{{ stats.throughput }}<span class="text-xs font-normal text-muted-foreground">/min</span></p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Failure Rate</p>
                        <p class="text-xl font-bold tabular-nums" :class="stats.failureRate > 0.05 ? 'text-red-500' : 'text-green-500'">{{ (stats.failureRate * 100).toFixed(1) }}%</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Supervisors</p>
                        <p class="text-xl font-bold text-purple-500 tabular-nums">{{ stats.activeSupervisors }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-muted-foreground mb-1">Workers</p>
                        <p class="text-xl font-bold text-indigo-500 tabular-nums">{{ stats.activeWorkers }}</p>
                    </div>
                </div>
            </div>

            <!-- Throughput Chart -->
            <MetricsChart
                title="Throughput (Last 6 Hours)"
                :labels="chartLabels"
                :data="chartData"
                color="#10b981"
                unit="jobs"
                :link="{ href: route('station.metrics'), text: 'View metrics' }"
            />

            <!-- Active Batches + Recent Alerts -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Active Batches -->
                <div class="lg:col-span-7 bg-card border border-border rounded-lg">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <h3 class="text-sm font-semibold text-foreground">Active Batches</h3>
                        <Link :href="route('station.batches')" class="text-xs text-indigo-500 hover:text-indigo-400">View all</Link>
                    </div>
                    <div v-if="activeBatches && activeBatches.length > 0" class="divide-y divide-border">
                        <div v-for="batch in activeBatches" :key="batch.id" class="px-4 py-3 flex items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <Link :href="route('station.batches.show', batch.id)" class="text-sm font-medium text-foreground hover:text-indigo-500 truncate block">
                                    {{ batch.name || 'Unnamed Batch' }}
                                </Link>
                                <div class="flex items-center gap-2 mt-1">
                                    <span :class="[statusColors[batch.status] || statusColors.pending, 'px-1.5 py-0.5 text-[10px] font-medium rounded']">
                                        {{ batch.status }}
                                    </span>
                                    <span class="text-xs text-muted-foreground tabular-nums">
                                        {{ batch.processed_jobs || 0 }}/{{ batch.total_jobs || 0 }} jobs
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 w-32">
                                <div class="flex-1 bg-secondary rounded-full h-1.5">
                                    <div class="bg-emerald-500 h-1.5 rounded-full transition-all" :style="{ width: getProgress(batch) + '%' }"></div>
                                </div>
                                <span class="text-xs text-muted-foreground tabular-nums w-8 text-right">{{ getProgress(batch) }}%</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                        No active batches
                    </div>
                </div>

                <!-- Recent Alerts -->
                <div class="lg:col-span-5 bg-card border border-border rounded-lg">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <h3 class="text-sm font-semibold text-foreground">Recent Alerts</h3>
                        <Link :href="route('station.alerts')" class="text-xs text-indigo-500 hover:text-indigo-400">View all</Link>
                    </div>
                    <div v-if="recentAlerts?.data && recentAlerts.data.length > 0" class="divide-y divide-border">
                        <div v-for="alert in recentAlerts.data" :key="alert.id" class="px-4 py-3 flex items-start gap-3">
                            <span :class="[severityDot[alert.severity] || severityDot.info, 'w-2 h-2 rounded-full mt-1.5 shrink-0']"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-foreground truncate">{{ alert.message }}</p>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ alert.rule_name }} &middot; {{ timeAgo(alert.created_at) }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                        No recent alerts
                    </div>
                </div>
            </div>

            <!-- Recent Failed Jobs -->
            <div class="bg-card border border-border rounded-lg">
                <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                    <h3 class="text-sm font-semibold text-foreground">Recent Failed Jobs</h3>
                    <Link :href="route('station.failed')" class="text-xs text-indigo-500 hover:text-indigo-400">View all</Link>
                </div>
                <div v-if="recentFailed?.data && recentFailed.data.length > 0" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-secondary">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Job</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Queue</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Exception</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Failed At</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="job in recentFailed.data" :key="job.id" class="hover:bg-secondary/50 transition-colors">
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <div class="text-sm font-medium text-foreground">
                                        {{ truncate(job.name, 40) }}
                                    </div>
                                    <div class="text-xs text-muted-foreground font-mono">
                                        {{ job.id }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ job.queue || 'default' }}
                                </td>
                                <td class="px-4 py-2.5 text-sm text-red-400 max-w-xs truncate">
                                    {{ truncate(job.exception, 80) }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ timeAgo(job.failed_at) }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                    <button
                                        @click="retryJob(job.id)"
                                        class="text-xs text-emerald-500 hover:text-emerald-400 font-medium"
                                    >
                                        Retry
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                    No failed jobs
                </div>
            </div>
        </div>
    </StationLayout>
</template>
