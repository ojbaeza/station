<script setup>
import { computed } from 'vue'

const props = defineProps({
    events: {
        type: Array,
        default: () => []
    },
    showDuration: {
        type: Boolean,
        default: true
    }
})

const eventIcons = {
    dispatched: { icon: 'paper-airplane', color: 'blue' },
    started: { icon: 'play', color: 'green' },
    processing: { icon: 'refresh', color: 'blue' },
    completed: { icon: 'check', color: 'green' },
    failed: { icon: 'x-circle', color: 'red' },
    retrying: { icon: 'refresh', color: 'yellow' },
    released: { icon: 'arrow-left', color: 'gray' },
    checkpoint: { icon: 'bookmark', color: 'purple' },
    recovered: { icon: 'heart', color: 'pink' },
}

const sortedEvents = computed(() => {
    return [...props.events].sort((a, b) => {
        return new Date(a.occurred_at) - new Date(b.occurred_at)
    })
})

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString()
}

const formatDuration = (ms) => {
    if (ms < 1000) return `${ms}ms`
    if (ms < 60000) return `${(ms / 1000).toFixed(2)}s`
    return `${(ms / 60000).toFixed(2)}m`
}

const getEventConfig = (event) => {
    const type = event.event?.toLowerCase() || 'unknown'
    return eventIcons[type] || { icon: 'information-circle', color: 'gray' }
}

const getDuration = (index) => {
    if (index === 0 || !props.showDuration) return null

    const current = new Date(sortedEvents.value[index].occurred_at)
    const previous = new Date(sortedEvents.value[index - 1].occurred_at)

    return current - previous
}
</script>

<template>
    <div class="flow-root">
        <ul role="list" class="-mb-8">
            <li v-for="(event, index) in sortedEvents" :key="event.id || index" class="relative pb-8">
                <!-- Connecting line -->
                <span
                    v-if="index !== sortedEvents.length - 1"
                    class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-border"
                    aria-hidden="true"
                />

                <div class="relative flex space-x-3">
                    <!-- Event icon -->
                    <div>
                        <span
                            :class="[
                                `bg-${getEventConfig(event).color}-500`,
                                'h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-card'
                            ]"
                        >
                            <!-- Dispatched -->
                            <svg v-if="getEventConfig(event).icon === 'paper-airplane'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                            </svg>

                            <!-- Play/Started -->
                            <svg v-else-if="getEventConfig(event).icon === 'play'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>

                            <!-- Refresh/Processing -->
                            <svg v-else-if="getEventConfig(event).icon === 'refresh'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>

                            <!-- Check/Completed -->
                            <svg v-else-if="getEventConfig(event).icon === 'check'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>

                            <!-- X-Circle/Failed -->
                            <svg v-else-if="getEventConfig(event).icon === 'x-circle'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>

                            <!-- Bookmark/Checkpoint -->
                            <svg v-else-if="getEventConfig(event).icon === 'bookmark'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                            </svg>

                            <!-- Heart/Recovered -->
                            <svg v-else-if="getEventConfig(event).icon === 'heart'" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                            </svg>

                            <!-- Default/Information -->
                            <svg v-else class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                    </div>

                    <!-- Event content -->
                    <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                        <div>
                            <p class="text-sm font-medium text-foreground">
                                {{ event.event }}
                            </p>
                            <p v-if="event.data" class="mt-1 text-sm text-muted-foreground">
                                {{ typeof event.data === 'string' ? event.data : JSON.stringify(event.data) }}
                            </p>

                            <!-- Duration badge -->
                            <span
                                v-if="getDuration(index)"
                                class="mt-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-secondary text-muted-foreground"
                            >
                                +{{ formatDuration(getDuration(index)) }}
                            </span>
                        </div>

                        <div class="text-right text-sm whitespace-nowrap text-muted-foreground">
                            {{ formatDate(event.occurred_at) }}
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="!events || events.length === 0" class="text-center py-6 text-sm text-muted-foreground">
            No events recorded
        </div>
    </div>
</template>
