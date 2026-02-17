<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import StationLayout from './Layout.vue'

const props = defineProps({
    job: Object,
    events: Array,
})

const retrying = ref(false)
const cancelling = ref(false)
const newTag = ref('')
const addingTag = ref(false)

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

const formatJson = (data) => {
    if (!data) return '-'
    try {
        return JSON.stringify(typeof data === 'string' ? JSON.parse(data) : data, null, 2)
    } catch {
        return data
    }
}

const retryJob = async () => {
    retrying.value = true
    try {
        await fetch(route('station.api.jobs.retry', props.job.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
        router.reload()
    } finally {
        retrying.value = false
    }
}

const addTag = async () => {
    if (!newTag.value.trim()) return
    addingTag.value = true
    try {
        await fetch(route('station.api.jobs.tags.add', props.job.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
            body: JSON.stringify({ tag: newTag.value.trim() }),
        })
        newTag.value = ''
        router.reload()
    } finally {
        addingTag.value = false
    }
}

const removeTag = async (tag) => {
    try {
        await fetch(route('station.api.jobs.tags.remove', { id: props.job.id, tag }), {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
        })
        router.reload()
    } catch {
        // silently fail
    }
}

const cancelJob = async () => {
    if (!confirm('Are you sure you want to cancel this job?')) return

    cancelling.value = true
    try {
        await fetch(route('station.api.jobs.cancel', props.job.id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        })
        router.reload()
    } finally {
        cancelling.value = false
    }
}
</script>

<template>
    <Head :title="`Job ${job.id} - Station`" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">
                        {{ job.name }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ job.id }}
                    </p>
                </div>
                <div class="flex space-x-3">
                    <button
                        v-if="job.status === 'failed'"
                        @click="retryJob"
                        :disabled="retrying"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 disabled:opacity-50"
                    >
                        {{ retrying ? 'Retrying...' : 'Retry' }}
                    </button>
                    <button
                        v-if="['pending', 'processing'].includes(job.status)"
                        @click="cancelJob"
                        :disabled="cancelling"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                    >
                        {{ cancelling ? 'Cancelling...' : 'Cancel' }}
                    </button>
                </div>
            </div>

            <!-- Job Details -->
            <div class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-foreground">
                        Job Details
                    </h3>
                </div>
                <div class="px-4 py-5 sm:p-6">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Status</dt>
                            <dd class="mt-1">
                                <span :class="[statusColors[job.status], 'px-2 py-0.5 inline-flex text-xs font-medium rounded']">
                                    {{ job.status }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Queue</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ job.queue }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Attempts</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ job.attempts || 1 }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Max Tries</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ job.max_tries || 1 }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Created At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(job.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Started At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(job.started_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Completed At</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ formatDate(job.completed_at) }}</dd>
                        </div>
                        <div v-if="job.batch_id">
                            <dt class="text-sm font-medium text-muted-foreground">Batch ID</dt>
                            <dd class="mt-1 text-sm text-foreground">{{ job.batch_id }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-muted-foreground">Tags</dt>
                            <dd class="mt-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-for="tag in (job.tags || [])"
                                        :key="tag"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-indigo-500/10 text-indigo-400"
                                    >
                                        {{ tag }}
                                        <button
                                            @click="removeTag(tag)"
                                            class="hover:text-red-400 transition-colors"
                                            title="Remove tag"
                                        >
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </span>
                                    <form @submit.prevent="addTag" class="inline-flex items-center gap-1">
                                        <input
                                            v-model="newTag"
                                            type="text"
                                            placeholder="Add tag..."
                                            class="w-24 px-2 py-0.5 text-xs border-border bg-secondary text-foreground rounded focus:outline-hidden focus:ring-1 focus:ring-emerald-500"
                                        />
                                        <button
                                            type="submit"
                                            :disabled="addingTag || !newTag.trim()"
                                            class="px-2 py-0.5 text-xs rounded bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/30 disabled:opacity-50"
                                        >
                                            Add
                                        </button>
                                    </form>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Payload -->
            <div class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-foreground">
                        Payload
                    </h3>
                </div>
                <div class="px-4 py-5 sm:p-6">
                    <pre class="text-sm text-foreground bg-secondary p-4 rounded-lg overflow-x-auto">{{ formatJson(job.payload) }}</pre>
                </div>
            </div>

            <!-- Exception (if failed) -->
            <div v-if="job.exception" class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-red-400">
                        Exception
                    </h3>
                </div>
                <div class="px-4 py-5 sm:p-6">
                    <pre class="text-sm text-red-400 bg-red-500/10 p-4 rounded-lg overflow-x-auto whitespace-pre-wrap">{{ job.exception }}</pre>
                </div>
            </div>

            <!-- Events Timeline -->
            <div class="bg-card border border-border rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-border">
                    <h3 class="text-lg leading-6 font-medium text-foreground">
                        Events Timeline
                    </h3>
                </div>
                <div class="px-4 py-5 sm:p-6">
                    <div v-if="events && events.length > 0" class="flow-root">
                        <ul class="-mb-8">
                            <li v-for="(event, index) in events" :key="event.id" class="relative pb-8">
                                <span v-if="index !== events.length - 1" class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-border" aria-hidden="true"></span>
                                <div class="relative flex space-x-3">
                                    <div>
                                        <span class="h-8 w-8 rounded-full bg-emerald-600 flex items-center justify-center ring-8 ring-card">
                                            <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                        <div>
                                            <p class="text-sm text-muted-foreground">
                                                {{ event.event }}
                                            </p>
                                        </div>
                                        <div class="text-right text-sm whitespace-nowrap text-muted-foreground">
                                            {{ formatDate(event.occurred_at) }}
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No events recorded</p>
                </div>
            </div>
        </div>
    </StationLayout>
</template>
