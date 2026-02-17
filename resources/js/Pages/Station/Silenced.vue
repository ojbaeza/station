<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import StationLayout from './Layout.vue'
import JobsTable from './Components/JobsTable.vue'
import Pagination from './Components/Pagination.vue'
import { Button } from '@/Components/ui/button'
import { useBulkSelection } from '@/composables/useBulkSelection'

const props = defineProps({
    jobs: Object,
    filters: Object,
    queues: Array,
    connections: Array,
    availableTags: { type: Array, default: () => [] },
    silencedClasses: { type: Array, default: () => [] },
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const selectedJobs = computed(() => {
    if (!props.jobs?.data) return []
    return props.jobs.data.filter(j => selectedIds.value.has(j.id))
})

const canCancel = computed(() => selectedJobs.value.length > 0 && selectedJobs.value.every(j => j.status === 'pending' || j.status === 'processing'))

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
    router.get(route('station.silenced'), {
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
    <Head title="Silenced Jobs - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center text-sm mb-1">
                        <Link :href="route('station.jobs')" class="text-muted-foreground hover:text-foreground">Jobs</Link>
                        <span class="text-muted-foreground mx-1">/</span>
                        <span>Silenced</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-foreground">Silenced Jobs</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Jobs hidden from the main dashboard</p>
                </div>
            </div>

            <!-- Not Configured State -->
            <div v-if="!silencedClasses?.length" class="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 rounded-lg p-6">
                <div class="flex gap-3">
                    <svg class="h-5 w-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm">
                        <p class="font-medium text-blue-800 dark:text-blue-300">No Silenced Job Classes Configured</p>
                        <p class="mt-1 text-blue-700 dark:text-blue-400/80">Add job classes to <code class="font-mono text-xs bg-blue-100 dark:bg-blue-500/20 px-1 py-0.5 rounded">config/station.php</code> under the <code class="font-mono text-xs bg-blue-100 dark:bg-blue-500/20 px-1 py-0.5 rounded">silenced</code> key to hide them from the main Jobs page and Dashboard stats.</p>
                    </div>
                </div>
            </div>

            <template v-else>
                <!-- Info Box -->
                <div class="bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 rounded-lg p-4">
                    <div class="flex gap-3">
                        <svg class="h-5 w-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="text-sm">
                            <p class="font-medium text-blue-800 dark:text-blue-300">Silenced Jobs</p>
                            <p class="mt-1 text-blue-700 dark:text-blue-400/80">These job classes are hidden from the main Jobs page and Dashboard stats. Configure silenced classes in <code class="font-mono text-xs bg-blue-100 dark:bg-blue-500/20 px-1 py-0.5 rounded">config/station.php</code> under the <code class="font-mono text-xs bg-blue-100 dark:bg-blue-500/20 px-1 py-0.5 rounded">silenced</code> key.</p>
                        </div>
                    </div>
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
            </template>
        </div>
    </StationLayout>
</template>
