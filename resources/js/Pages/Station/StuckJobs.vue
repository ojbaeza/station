<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import { Checkbox } from '@/Components/ui/checkbox'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import { useBulkSelection } from '@/composables/useBulkSelection'

const props = defineProps({
    jobs: {
        type: Array,
        default: () => [],
    },
    threshold: {
        type: Number,
        default: 300,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
})

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const allSelected = computed(() => {
    if (!props.jobs?.length) return false
    return props.jobs.every((j) => selectedIds.value.has(j.id))
})

const bulkStrategy = ref('graceful')

const bulkCancel = () => {
    if (!confirm(`Cancel ${selectedIds.value.size} selected stuck jobs?`)) return
    router.post(route('station.api.jobs.bulk.cancel'), { ids: selectedArray.value }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    })
}

const bulkRecover = () => {
    if (!confirm(`Recover ${selectedIds.value.size} selected stuck jobs using "${bulkStrategy.value}" strategy?`)) return
    router.post(route('station.api.stuck.bulk.recover'), {
        ids: selectedArray.value,
        strategy: bulkStrategy.value,
    }, {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    })
}

const cancelJob = (id) => {
    if (!confirm('Cancel this stuck job?')) return
    router.post(route('station.api.jobs.cancel', id), {}, { preserveScroll: true })
}

const recoverJob = (id, strategy = 'graceful') => {
    router.post(route('station.api.stuck.recover', id), { strategy }, {
        preserveScroll: true,
    })
}

const thresholdInput = ref(props.filters?.threshold || '')

const applyFilters = () => {
    router.get(route('station.stuck'), {
        threshold: thresholdInput.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(5000) })
const pollTimer = ref(null)

const startPolling = () => {
    router.reload({ only: ['jobs'], preserveScroll: true })
}
const stopPolling = () => { clearInterval(pollTimer.value); pollTimer.value = null }

const effectiveInterval = computed(() => {
    const base = autoRefresh.interval.value
    return autoRefresh.focused?.value === false ? base * 6 : base
})

watch([() => autoRefresh.enabled.value, effectiveInterval], ([enabled, ms]) => {
    stopPolling()
    if (enabled) { pollTimer.value = setInterval(startPolling, ms) }
}, { immediate: true })

watch(() => autoRefresh.focused?.value, (focused) => {
    if (focused && autoRefresh.enabled.value) startPolling()
})

onUnmounted(stopPolling)

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString()
}

const formatDuration = (seconds) => {
    if (!seconds && seconds !== 0) return '-'
    if (seconds < 60) return `${seconds}s`
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`
}

const scoreColor = (score) => {
    if (score < 0.3) return 'text-emerald-600 dark:text-emerald-400'
    if (score < 0.7) return 'text-amber-400'
    return 'text-red-400'
}

const scoreBg = (score) => {
    if (score < 0.3) return 'bg-emerald-500'
    if (score < 0.7) return 'bg-amber-500'
    return 'bg-red-500'
}

const truncate = (str, length = 40) => {
    if (!str) return '-'
    return str.length > length ? str.substring(0, length) + '...' : str
}
</script>

<template>
    <Head title="Stuck Jobs - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div>
                <div class="flex items-center gap-2 text-sm text-muted-foreground mb-1">
                    <Link :href="route('station.jobs')" class="hover:text-foreground transition-colors">Jobs</Link>
                    <span>/</span>
                    <span class="text-foreground">Stuck Jobs</span>
                </div>
                <h1 class="text-2xl font-semibold text-foreground">Stuck Jobs</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Jobs processing longer than {{ threshold }}s without heartbeat
                </p>
            </div>

            <!-- Info Box -->
            <div class="bg-card border border-border rounded-lg p-4">
                <div class="flex gap-3">
                    <svg class="h-5 w-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-sm text-muted-foreground">
                        <p><strong class="text-foreground">Stuck jobs</strong> are those processing longer than the threshold without a heartbeat update.</p>
                        <p class="mt-1">
                            <strong>Recovery strategies:</strong>
                            <span class="text-emerald-600 dark:text-emerald-400"> Graceful</span> = resume from last known state;
                            <span class="text-blue-400"> Restart</span> = re-queue from scratch;
                            <span class="text-purple-400"> Checkpoint</span> = resume from last saved checkpoint.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-card border border-border rounded-lg p-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label for="threshold" class="block text-sm font-medium text-muted-foreground">
                            Threshold (seconds)
                        </label>
                        <div class="mt-1 flex gap-2">
                            <input
                                id="threshold"
                                v-model="thresholdInput"
                                type="number"
                                min="1"
                                :placeholder="String(threshold)"
                                class="block w-full pl-3 pr-3 py-2 text-base border-border bg-secondary text-foreground focus:outline-hidden focus:ring-emerald-500 focus:border-emerald-500 sm:text-sm rounded-md"
                            />
                            <Button variant="outline" size="sm" @click="applyFilters">
                                Apply
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div v-if="hasSelection" class="bg-card border border-border rounded-lg p-4 flex items-center gap-3">
                <span class="text-sm text-muted-foreground">{{ selectedIds.size }} selected</span>
                <select
                    v-model="bulkStrategy"
                    class="rounded-md border-border bg-secondary px-3 py-1.5 text-sm text-foreground focus:ring-emerald-500 focus:border-emerald-500"
                >
                    <option value="graceful">Graceful</option>
                    <option value="restart">Restart</option>
                    <option value="checkpoint">Checkpoint</option>
                </select>
                <Button variant="outline" size="sm" @click="bulkCancel">Cancel Selected</Button>
                <Button variant="outline" size="sm" @click="bulkRecover">Recover Selected</Button>
                <Button variant="ghost" size="sm" @click="clearSelection">Clear</Button>
            </div>

            <!-- Stuck Jobs Table -->
            <div class="bg-card border border-border rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border">
                        <thead class="bg-secondary">
                            <tr>
                                <th class="px-6 py-3 w-10">
                                    <Checkbox
                                        :model-value="allSelected"
                                        @update:model-value="toggleAll(jobs.map(j => j.id))"
                                    />
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Job
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Queue
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Stuck Duration
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Stuck Score
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Attempts
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Started At
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-card divide-y divide-border">
                            <tr v-for="job in jobs" :key="job.id" class="hover:bg-secondary/50">
                                <td class="px-6 py-4 w-10">
                                    <Checkbox
                                        :model-value="selectedIds.has(job.id)"
                                        @update:model-value="toggleId(job.id)"
                                    />
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-foreground">
                                        {{ truncate(job.name) }}
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ job.id }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ job.queue }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <Badge variant="secondary">
                                        {{ job.status }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-amber-400 font-medium">
                                    {{ formatDuration(job.stuck_duration) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 h-2 rounded-full bg-secondary overflow-hidden">
                                            <div
                                                :class="scoreBg(job.stuck_score)"
                                                class="h-full rounded-full"
                                                :style="{ width: (job.stuck_score * 100) + '%' }"
                                            ></div>
                                        </div>
                                        <span :class="scoreColor(job.stuck_score)" class="text-sm font-medium">
                                            {{ (job.stuck_score || 0).toFixed(2) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ job.attempts || 0 }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                    {{ formatDate(job.started_at) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                    <button
                                        @click="cancelJob(job.id)"
                                        class="text-red-600 dark:text-red-500 hover:text-red-500 dark:hover:text-red-400"
                                        title="Cancel this stuck job"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        @click="recoverJob(job.id, 'graceful')"
                                        class="text-emerald-600 dark:text-emerald-500 hover:text-emerald-600 dark:hover:text-emerald-400"
                                        title="Resume from last known state"
                                    >
                                        Graceful
                                    </button>
                                    <button
                                        @click="recoverJob(job.id, 'restart')"
                                        class="text-blue-400 hover:text-blue-300"
                                        title="Re-queue from scratch"
                                    >
                                        Restart
                                    </button>
                                    <button
                                        @click="recoverJob(job.id, 'checkpoint')"
                                        class="text-purple-400 hover:text-purple-300"
                                        title="Resume from last saved checkpoint"
                                    >
                                        Checkpoint
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!jobs || jobs.length === 0">
                                <td colspan="9" class="px-6 py-8 text-center text-sm text-muted-foreground">
                                    No stuck jobs detected
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </StationLayout>
</template>
