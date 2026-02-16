<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Checkbox } from '@/Components/ui/checkbox'

const props = defineProps({
    jobs: Array,
    showPagination: {
        type: Boolean,
        default: false,
    },
    selectable: {
        type: Boolean,
        default: false,
    },
    selectedIds: {
        type: Set,
        default: () => new Set(),
    },
})

const emit = defineEmits(['toggle-id', 'toggle-all', 'filter-tag'])

const isSelectable = (job) => job.status !== 'completed'

const selectableJobs = computed(() => (props.jobs || []).filter(isSelectable))

const allSelected = computed(() => {
    if (!selectableJobs.value.length) return false
    return selectableJobs.value.every((job) => props.selectedIds.has(job.id))
})

const statusColors = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString()
}

const truncate = (str, length = 50) => {
    if (!str) return '-'
    return str.length > length ? str.substring(0, length) + '...' : str
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border">
            <thead class="bg-secondary">
                <tr>
                    <th v-if="selectable" class="px-6 py-3 w-10">
                        <Checkbox
                            :model-value="allSelected"
                            @update:model-value="emit('toggle-all', selectableJobs.map(j => j.id))"
                        />
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Job</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Queue</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tags</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Attempts</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Created</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-card divide-y divide-border">
                <tr v-for="job in jobs" :key="job.id" class="hover:bg-secondary/50 transition-colors">
                    <td v-if="selectable" class="px-6 py-4 w-10">
                        <Checkbox
                            v-if="isSelectable(job)"
                            :model-value="selectedIds.has(job.id)"
                            @update:model-value="emit('toggle-id', job.id)"
                        />
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap">
                        <div class="text-sm font-medium text-foreground">
                            {{ truncate(job.name, 40) }}
                        </div>
                        <div class="text-xs text-muted-foreground font-mono">
                            {{ job.id }}
                        </div>
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                        {{ job.queue }}
                    </td>
                    <td class="px-6 py-3.5">
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="tag in (job.tags || [])"
                                :key="tag"
                                class="px-1.5 py-0.5 text-xs rounded bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500/20 transition-colors cursor-pointer"
                                @click="emit('filter-tag', tag)"
                            >
                                {{ tag }}
                            </button>
                        </div>
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap">
                        <span :class="[statusColors[job.status] || statusColors.pending, 'px-2 py-0.5 inline-flex text-xs font-medium rounded']">
                            {{ job.status }}
                        </span>
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground tabular-nums">
                        {{ job.attempts || 1 }}
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                        {{ formatDate(job.created_at) }}
                    </td>
                    <td class="px-6 py-3.5 whitespace-nowrap text-sm font-medium">
                        <Link
                            :href="route('station.jobs.show', job.id)"
                            class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                        >
                            View
                        </Link>
                    </td>
                </tr>
                <tr v-if="!jobs || jobs.length === 0">
                    <td :colspan="selectable ? 8 : 7" class="px-6 py-8 text-center text-sm text-muted-foreground">
                        No jobs found
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
