<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import Pagination from './Components/Pagination.vue'
import StatCard from './Components/StatCard.vue'
import { Checkbox } from '@/Components/ui/checkbox'
import { Button } from '@/Components/ui/button'
import { useBulkSelection } from '@/composables/useBulkSelection'

const props = defineProps({
    batches: Object,
    filters: Object,
    connections: Array,
    stats: {
        type: Object,
        default: () => ({ pending: 0, processing: 0, completed: 0, failed: 0, cancelled: 0 }),
    },
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const isBatchSelectable = (batch) => batch.status !== 'completed' && batch.status !== 'cancelled'

const selectableBatches = computed(() => (props.batches?.data || []).filter(isBatchSelectable))

const allSelected = computed(() => {
    if (!selectableBatches.value.length) return false
    return selectableBatches.value.every((b) => selectedIds.value.has(b.id))
})

const selectedBatches = computed(() => {
    if (!props.batches?.data) return []
    return props.batches.data.filter(b => selectedIds.value.has(b.id))
})

const canCancel = computed(() => selectedBatches.value.length > 0 && selectedBatches.value.every(b => b.status === 'pending' || b.status === 'processing'))
const canRetry = computed(() => selectedBatches.value.length > 0 && selectedBatches.value.every(b => b.status === 'failed'))

const bulkAction = (routeName, message) => {
    if (!confirm(message)) return
    router.post(route(routeName), { ids: selectedArray.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    })
}

const cancelBatch = (id) => {
    if (!confirm('Cancel this batch?')) return
    router.post(route('station.api.batches.cancel', id), {}, { preserveScroll: true })
}

const retryBatch = (id) => {
    if (!confirm('Retry this batch?')) return
    router.post(route('station.api.batches.retry', id), {}, { preserveScroll: true })
}

const selectedStatus = ref(props.filters?.status || '')
const selectedConnection = ref(props.filters?.connection || '')

const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(5000) })
const pollTimer = ref(null)
const isRefreshing = ref(false)

const refreshData = () => {
    if (isRefreshing.value) return
    isRefreshing.value = true
    router.reload({
        only: ['batches'],
        preserveScroll: true,
        onFinish: () => { isRefreshing.value = false },
    })
}

const stopPolling = () => { clearInterval(pollTimer.value); pollTimer.value = null }

watch([() => autoRefresh.enabled.value, () => autoRefresh.interval.value], ([enabled, ms]) => {
    stopPolling()
    if (enabled) { pollTimer.value = setInterval(refreshData, ms) }
}, { immediate: true })

onUnmounted(stopPolling)

const applyFilters = () => {
    router.get(route('station.batches'), {
        status: selectedStatus.value || undefined,
        connection: selectedConnection.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch([selectedStatus, selectedConnection], applyFilters)

const statusColors = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-500/10 dark:text-gray-400',
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString()
}

const getProgress = (batch) => {
    if (!batch.total_jobs || batch.total_jobs === 0) return 0
    return Math.round(((batch.processed_jobs || 0) / batch.total_jobs) * 100)
}
</script>

<template>
    <Head title="Batches - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-foreground">Batches History</h1>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard title="Pending" :value="stats.pending" icon="clock" color="yellow" />
                <StatCard title="Processing" :value="stats.processing" icon="refresh" color="blue" />
                <StatCard title="Completed" :value="stats.completed" icon="check" color="emerald" />
                <StatCard title="Failed" :value="stats.failed" icon="x" color="red" />
            </div>

            <!-- Filters -->
            <div class="bg-card border border-border rounded-lg p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="connection" class="block text-sm font-medium text-muted-foreground">
                            Driver
                        </label>
                        <select
                            id="connection"
                            v-model="selectedConnection"
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        >
                            <option value="">All Drivers</option>
                            <option v-for="conn in connections" :key="conn" :value="conn">
                                {{ conn }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-muted-foreground">
                            Status
                        </label>
                        <select
                            id="status"
                            v-model="selectedStatus"
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        >
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="hasSelection" class="bg-card border border-border rounded-lg p-4 flex items-center gap-3">
                <span class="text-sm text-muted-foreground">{{ selectedIds.size }} selected</span>
                <Button v-if="canCancel" variant="outline" size="sm" @click="bulkAction('station.api.batches.bulk.cancel', `Cancel ${selectedIds.size} selected batches?`)">Cancel Selected</Button>
                <Button v-if="canRetry" variant="outline" size="sm" @click="bulkAction('station.api.batches.bulk.retry', `Retry ${selectedIds.size} selected batches?`)">Retry Selected</Button>
                <Button variant="ghost" size="sm" @click="clearSelection">Clear</Button>
            </div>

            <!-- Batches Table -->
            <div class="bg-card border border-border rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-secondary">
                            <tr>
                                <th class="px-6 py-3 w-10">
                                    <Checkbox
                                        :model-value="allSelected"
                                        @update:model-value="toggleAll(selectableBatches.map(b => b.id))"
                                    />
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Batch
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Progress
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Jobs
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Created At
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-card divide-y divide-border">
                            <tr v-for="batch in batches.data" :key="batch.id" class="hover:bg-secondary/50 transition-colors">
                                <td class="px-6 py-4 w-10">
                                    <Checkbox
                                        v-if="isBatchSelectable(batch)"
                                        :model-value="selectedIds.has(batch.id)"
                                        @update:model-value="toggleId(batch.id)"
                                    />
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <div class="text-sm font-medium text-foreground">
                                        {{ batch.name || 'Unnamed Batch' }}
                                    </div>
                                    <div class="text-xs text-muted-foreground font-mono">
                                        {{ batch.id }}
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <span :class="[statusColors[batch.status] || statusColors.pending, 'px-2 py-0.5 inline-flex text-xs font-medium rounded']">
                                        {{ batch.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-full bg-secondary rounded-full h-2.5 mr-2">
                                            <div
                                                class="bg-emerald-500 h-2.5 rounded-full"
                                                :style="{ width: getProgress(batch) + '%' }"
                                            ></div>
                                        </div>
                                        <span class="text-sm text-muted-foreground">{{ getProgress(batch) }}%</span>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                                    {{ batch.processed_jobs || 0 }} / {{ batch.total_jobs || 0 }}
                                    <span v-if="batch.failed_jobs > 0" class="text-red-400">
                                        ({{ batch.failed_jobs }} failed)
                                    </span>
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ formatDate(batch.created_at) }}
                                </td>
                                <td class="px-6 py-3.5 whitespace-nowrap text-sm font-medium space-x-2">
                                    <Link
                                        :href="route('station.batches.show', batch.id)"
                                        class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        View
                                    </Link>
                                    <button
                                        v-if="batch.status === 'pending' || batch.status === 'processing'"
                                        class="text-amber-600 hover:text-amber-500 dark:text-amber-400 dark:hover:text-amber-300"
                                        @click="cancelBatch(batch.id)"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        v-if="batch.status === 'failed'"
                                        class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                        @click="retryBatch(batch.id)"
                                    >
                                        Retry
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!batches.data || batches.data.length === 0">
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-muted-foreground">
                                    No batches found
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="batches" />
            </div>
        </div>
    </StationLayout>
</template>
