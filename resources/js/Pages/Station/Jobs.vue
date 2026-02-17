<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import StationLayout from './Layout.vue'
import JobsTable from './Components/JobsTable.vue'
import Pagination from './Components/Pagination.vue'
import StatCard from './Components/StatCard.vue'
import { Button } from '@/Components/ui/button'
import { useBulkSelection } from '@/composables/useBulkSelection'

const props = defineProps({
    jobs: Object,
    stats: {
        type: Object,
        default: () => ({ pending: 0, processing: 0, completed: 0, failed: 0 }),
    },
    filters: Object,
    queues: Array,
    connections: Array,
    availableTags: { type: Array, default: () => [] },
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const selectedJobs = computed(() => {
    if (!props.jobs?.data) return []
    return props.jobs.data.filter(j => selectedIds.value.has(j.id))
})

const canCancel = computed(() => selectedJobs.value.length > 0 && selectedJobs.value.every(j => j.status === 'pending' || j.status === 'processing'))
const canRetry = computed(() => selectedJobs.value.length > 0 && selectedJobs.value.every(j => j.status === 'failed'))

const bulkAction = (routeName, message) => {
    if (!confirm(message)) return
    router.post(route(routeName), { ids: selectedArray.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    })
}

const selectedQueue = ref(props.filters?.queue || '')
const selectedStatus = ref(props.filters?.status || '')
const selectedConnection = ref(props.filters?.connection || '')
const selectedTag = ref(props.filters?.tag || '')

const applyFilters = () => {
    router.get(route('station.jobs'), {
        queue: selectedQueue.value || undefined,
        status: selectedStatus.value || undefined,
        connection: selectedConnection.value || undefined,
        tag: selectedTag.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch([selectedQueue, selectedStatus, selectedConnection, selectedTag], applyFilters)

const filterByTag = (tag) => {
    selectedTag.value = tag
}
</script>

<template>
    <Head title="Jobs - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-foreground">Jobs</h1>
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
                        <label for="queue" class="block text-sm font-medium text-muted-foreground">
                            Queue
                        </label>
                        <select
                            id="queue"
                            v-model="selectedQueue"
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        >
                            <option value="">All Queues</option>
                            <option v-for="queue in queues" :key="queue" :value="queue">
                                {{ queue }}
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
                        </select>
                    </div>
                    <div>
                        <label for="tag" class="block text-sm font-medium text-muted-foreground">
                            Tag
                        </label>
                        <input
                            id="tag"
                            v-model="selectedTag"
                            list="tag-suggestions"
                            placeholder="Filter by tag..."
                            class="mt-1 block w-full pl-3 pr-3 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        />
                        <datalist id="tag-suggestions">
                            <option v-for="tag in availableTags" :key="tag" :value="tag" />
                        </datalist>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="hasSelection" class="bg-card border border-border rounded-lg p-4 flex items-center gap-3">
                <span class="text-sm text-muted-foreground">{{ selectedIds.size }} selected</span>
                <Button v-if="canCancel" variant="outline" size="sm" @click="bulkAction('station.api.jobs.bulk.cancel', `Cancel ${selectedIds.size} selected jobs?`)">Cancel Selected</Button>
                <Button v-if="canRetry" variant="outline" size="sm" @click="bulkAction('station.api.jobs.bulk.retry', `Retry ${selectedIds.size} selected jobs?`)">Retry Selected</Button>
                <Button variant="ghost" size="sm" @click="clearSelection">Clear</Button>
            </div>

            <!-- Jobs Table -->
            <div class="bg-card border border-border rounded-lg">
                <JobsTable
                    :jobs="jobs.data"
                    selectable
                    :selected-ids="selectedIds"
                    @toggle-id="toggleId"
                    @toggle-all="toggleAll"
                    @filter-tag="filterByTag"
                />
                <Pagination :data="jobs" />
            </div>
        </div>
    </StationLayout>
</template>
