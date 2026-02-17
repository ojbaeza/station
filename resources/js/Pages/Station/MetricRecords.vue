<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import Pagination from './Components/Pagination.vue'

const props = defineProps({
    metrics: Object,
    period: String,
    connections: Array,
    currentConnection: String,
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

const applyFilters = () => {
    const params = {
        period: selectedPeriod.value,
    }
    if (selectedConnection.value) {
        params.connection = selectedConnection.value
    }
    router.get(route('station.metrics.records'), params, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch(selectedPeriod, applyFilters)
watch(selectedConnection, applyFilters)

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0'
    return num.toLocaleString()
}
</script>

<template>
    <Head title="Metric Records - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm text-muted-foreground mb-1">
                        <Link :href="route('station.metrics')" class="hover:text-foreground transition-colors">Metrics</Link>
                        <span>/</span>
                        <span class="text-foreground">Records</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-foreground">Metric Records</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Individual metric records for the selected period</p>
                </div>
                <div class="flex items-center gap-3">
                    <select
                        v-if="connections && connections.length > 0"
                        v-model="selectedConnection"
                        class="block w-full min-w-[200px] pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                    >
                        <option value="">All Connections</option>
                        <option v-for="conn in connections" :key="conn" :value="conn">
                            {{ conn }}
                        </option>
                    </select>
                    <select
                        v-model="selectedPeriod"
                        class="block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                    >
                        <option v-for="period in periods" :key="period.value" :value="period.value">
                            {{ period.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Records Table -->
            <div class="bg-card border border-border rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-secondary">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Time
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Connection
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Queue
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Jobs Processed
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Jobs Failed
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Avg Wait (s)
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Avg Process (s)
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-card divide-y divide-border">
                            <tr v-for="metric in metrics.data" :key="metric.id || metric.recorded_at">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ new Date(metric.recorded_at).toLocaleString() }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                                    {{ metric.connection || '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-foreground">
                                    {{ metric.queue || 'all' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                    {{ formatNumber(metric.jobs_processed) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                    {{ formatNumber(metric.jobs_failed) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                    {{ metric.avg_wait_time?.toFixed(2) || 0 }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                    {{ metric.avg_processing_time?.toFixed(2) || 0 }}
                                </td>
                            </tr>
                            <tr v-if="!metrics.data || metrics.data.length === 0">
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-muted-foreground">
                                    No metrics data available for this period
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="metrics" />
            </div>
        </div>
    </StationLayout>
</template>
