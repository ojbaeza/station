<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import Pagination from './Components/Pagination.vue'
import { Checkbox } from '@/Components/ui/checkbox'
import { Button } from '@/Components/ui/button'
import { useBulkSelection } from '@/composables/useBulkSelection'

const props = defineProps({
    jobs: Object,
    filters: Object,
    queues: Array,
    connections: Array,
    availableTags: { type: Array, default: () => [] },
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const allSelected = computed(() => {
    if (!props.jobs.data?.length) return false
    return props.jobs.data.every((j) => selectedIds.value.has(j.id))
})

const bulkAction = (routeName, message) => {
    if (!confirm(message)) return
    router.post(route(routeName), { ids: selectedArray.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    })
}

const selectedQueue = ref(props.filters?.queue || '')
const selectedConnection = ref(props.filters?.connection || '')
const selectedTag = ref(props.filters?.tag || '')
const retryingAll = ref(false)

const applyFilters = () => {
    router.get(route('station.failed'), {
        queue: selectedQueue.value || undefined,
        connection: selectedConnection.value || undefined,
        tag: selectedTag.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch([selectedQueue, selectedConnection, selectedTag], applyFilters)

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString()
}

const truncate = (str, length = 50) => {
    if (!str) return '-'
    return str.length > length ? str.substring(0, length) + '...' : str
}

const retryJob = async (id) => {
    if (!confirm('Retry this failed job?')) return
    try {
        const response = await fetch(route('station.api.failed.retry', id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
        const data = await response.json()

        if (!response.ok) {
            alert(data.error || 'Failed to retry job')
            return
        }

        router.reload()
    } catch (error) {
        alert('Failed to retry job: ' + error.message)
    }
}

const retryAll = async () => {
    if (!confirm('Are you sure you want to retry all failed jobs?')) return

    retryingAll.value = true
    try {
        await fetch(route('station.api.failed.retry-all'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ queue: selectedQueue.value || undefined }),
        })
        router.reload()
    } finally {
        retryingAll.value = false
    }
}

</script>

<template>
    <Head title="Failed Jobs - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm text-muted-foreground mb-1">
                        <Link :href="route('station.jobs')" class="hover:text-foreground transition-colors">Jobs</Link>
                        <span>/</span>
                        <span class="text-foreground">Failed</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-foreground">Failed Jobs</h1>
                </div>
                <div class="flex space-x-3">
                    <button
                        @click="retryAll"
                        :disabled="retryingAll || !jobs.data?.length"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 disabled:opacity-50"
                    >
                        {{ retryingAll ? 'Retrying...' : 'Retry All' }}
                    </button>
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
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
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
                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        >
                            <option value="">All Queues</option>
                            <option v-for="queue in queues" :key="queue" :value="queue">
                                {{ queue }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="tag" class="block text-sm font-medium text-muted-foreground">
                            Tag
                        </label>
                        <input
                            id="tag"
                            v-model="selectedTag"
                            list="failed-tag-suggestions"
                            placeholder="Filter by tag..."
                            class="mt-1 block w-full pl-3 pr-3 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                        />
                        <datalist id="failed-tag-suggestions">
                            <option v-for="tag in availableTags" :key="tag" :value="tag" />
                        </datalist>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="hasSelection" class="bg-card border border-border rounded-lg p-4 flex items-center gap-3">
                <span class="text-sm text-muted-foreground">{{ selectedIds.size }} selected</span>
                <Button variant="outline" size="sm" @click="bulkAction('station.api.failed.bulk.retry', `Retry ${selectedIds.size} selected failed jobs?`)">Retry Selected</Button>
                <Button variant="ghost" size="sm" @click="clearSelection">Clear</Button>
            </div>

            <!-- Failed Jobs Table -->
            <div class="bg-card border border-border rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-secondary">
                            <tr>
                                <th class="px-6 py-3 w-10">
                                    <Checkbox
                                        :model-value="allSelected"
                                        @update:model-value="toggleAll(jobs.data.map(j => j.id))"
                                    />
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Job
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Queue
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Exception
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Failed At
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-card divide-y divide-border">
                            <tr v-for="job in jobs.data" :key="job.id">
                                <td class="px-6 py-4 w-10">
                                    <Checkbox
                                        :model-value="selectedIds.has(job.id)"
                                        @update:model-value="toggleId(job.id)"
                                    />
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-foreground">
                                        {{ truncate(job.name, 40) }}
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ job.id }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ job.queue }}
                                </td>
                                <td class="px-6 py-4 text-sm text-red-400">
                                    {{ truncate(job.exception, 80) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ formatDate(job.failed_at) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <button
                                        @click="retryJob(job.id)"
                                        class="text-emerald-600 dark:text-emerald-500 hover:text-emerald-600 dark:hover:text-emerald-400"
                                    >
                                        Retry
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!jobs.data || jobs.data.length === 0">
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-muted-foreground">
                                    No failed jobs found
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :data="jobs" />
            </div>
        </div>
    </StationLayout>
</template>
