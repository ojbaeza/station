<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import StationLayout from './Layout.vue'
import JobsTable from './Components/JobsTable.vue'

const props = defineProps({
    batch: Object,
    jobs: Array,
})

const cancelling = ref(false)
const retrying = ref(false)

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

const getProgress = () => {
    if (!props.batch.total_jobs || props.batch.total_jobs === 0) return 0
    return Math.round(((props.batch.processed_jobs || 0) / props.batch.total_jobs) * 100)
}

const cancelBatch = () => {
    if (!confirm('Are you sure you want to cancel this batch?')) return
    cancelling.value = true
    router.post(route('station.api.batches.cancel', props.batch.id), {}, {
        preserveScroll: true,
        onFinish: () => { cancelling.value = false },
    })
}

const retryBatch = () => {
    retrying.value = true
    router.post(route('station.api.batches.retry', props.batch.id), {}, {
        preserveScroll: true,
        onFinish: () => { retrying.value = false },
    })
}

const filterByTag = (tag) => {
    router.get(route('station.jobs'), { tag })
}
</script>

<template>
    <Head :title="`Batch ${batch.id} - Station`" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ batch.name || 'Unnamed Batch' }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ batch.id }}
                    </p>
                </div>
                <div class="flex space-x-3">
                    <button
                        v-if="batch.status === 'failed'"
                        @click="retryBatch"
                        :disabled="retrying"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 disabled:opacity-50"
                    >
                        {{ retrying ? 'Retrying...' : 'Retry Failed Jobs' }}
                    </button>
                    <button
                        v-if="['pending', 'processing'].includes(batch.status)"
                        @click="cancelBatch"
                        :disabled="cancelling"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                    >
                        {{ cancelling ? 'Cancelling...' : 'Cancel Batch' }}
                    </button>
                </div>
            </div>

            <!-- Batch Details -->
            <div class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-foreground">
                        Batch Details
                    </h3>
                </div>
                <div class="px-4 py-5 sm:p-6">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Status</dt>
                            <dd class="mt-1">
                                <span :class="[statusColors[batch.status], 'px-2 py-0.5 inline-flex text-xs font-medium rounded']">
                                    {{ batch.status }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Total Jobs</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ batch.total_jobs || 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Processed Jobs</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ batch.processed_jobs || 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Failed Jobs</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ batch.failed_jobs || 0 }}</dd>
                        </div>
                    </dl>

                    <!-- Progress Bar -->
                    <div class="mt-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-muted-foreground">Progress</span>
                            <span class="text-sm font-medium text-foreground">{{ getProgress() }}%</span>
                        </div>
                        <div class="w-full bg-secondary rounded-full h-4">
                            <div
                                class="bg-emerald-500 h-4 rounded-full transition-all duration-300"
                                :style="{ width: getProgress() + '%' }"
                            ></div>
                        </div>
                    </div>

                    <!-- Timestamps -->
                    <dl class="mt-6 grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-3">
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Created At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(batch.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Started At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(batch.started_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Finished At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(batch.finished_at) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Batch Jobs -->
            <div class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-foreground">
                        Jobs in Batch
                    </h3>
                </div>
                <JobsTable :jobs="jobs" @filter-tag="filterByTag" />
            </div>
        </div>
    </StationLayout>
</template>
