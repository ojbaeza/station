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
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

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

const applyFilters = () => {
    router.get(route('station.pending'), {
        queue: selectedQueue.value || undefined,
        connection: selectedConnection.value || undefined,
        tag: selectedTag.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch([selectedQueue, selectedConnection, selectedTag], applyFilters)

const filterByTag = (tag) => {
    selectedTag.value = tag
}
</script>

<template>
    <Head title="Pending Jobs - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <div class="flex items-center text-sm mb-1">
                        <Link :href="route('station.jobs')" class="text-muted-foreground hover:text-foreground">Jobs</Link>
                        <span class="text-muted-foreground mx-1">/</span>
                        <span>Pending</span>
                    </div>
                    <h1 class="text-2xl font-semibold text-foreground">Pending Jobs</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Jobs waiting to be processed</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-card border border-border rounded-lg p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                            list="tag-suggestions"
                            placeholder="Filter by tag..."
                            class="mt-1 block w-full pl-3 pr-3 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
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
                <Button variant="outline" size="sm" @click="bulkAction('station.api.jobs.bulk.cancel', `Cancel ${selectedIds.size} selected jobs?`)">Cancel Selected</Button>
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
