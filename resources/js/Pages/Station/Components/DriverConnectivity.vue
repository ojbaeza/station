<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
    drivers: {
        type: Object,
        default: () => ({}),
    },
})

const liveDrivers = ref({ ...props.drivers })
const checking = ref(false)
let pollInterval = null

const driverLabels = {
    rabbitmq: 'RabbitMQ',
    redis: 'Redis',
    sqs: 'Amazon SQS',
    beanstalkd: 'Beanstalkd',
    kafka: 'Apache Kafka',
}

const POLL_INTERVAL = 15000

// Order connections: default first
const orderedDrivers = computed(() => {
    const entries = Object.entries(liveDrivers.value)
    const def = entries.find(([, info]) => info.is_default)
    if (!def) return entries
    return [def, ...entries.filter(([name]) => name !== def[0])]
})

async function pollDrivers() {
    checking.value = true
    try {
        const response = await fetch(route('station.api.drivers'), {
            credentials: 'same-origin',
        })
        if (response.ok) {
            liveDrivers.value = await response.json()
        }
    } catch {
        // Silently fail — stale data is acceptable
    } finally {
        checking.value = false
    }
}

onMounted(() => {
    pollDrivers()
    pollInterval = setInterval(pollDrivers, POLL_INTERVAL)
})

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
        <div
            v-for="[name, info] in orderedDrivers"
            :key="name"
            class="inline-flex items-center gap-1.5 text-sm whitespace-nowrap transition-opacity"
            :class="checking ? 'opacity-60' : ''"
        >
            <span class="relative flex h-2 w-2 flex-shrink-0">
                <span
                    v-if="checking"
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-zinc-400 opacity-75"
                ></span>
                <span
                    v-else-if="info.connected"
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"
                ></span>
                <span
                    :class="checking ? 'bg-zinc-400' : info.connected ? 'bg-emerald-500' : 'bg-red-500'"
                    class="relative inline-flex rounded-full h-2 w-2"
                ></span>
            </span>
            <span class="font-medium text-foreground">{{ driverLabels[info.driver] || info.driver || name }}</span>
            <span v-if="info.is_default" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-secondary text-muted-foreground">default</span>
        </div>
        <div v-if="orderedDrivers.length === 0 && !checking" class="text-sm text-muted-foreground">
            No queue connections configured
        </div>
        <div v-if="orderedDrivers.length === 0 && checking" class="flex items-center gap-2 text-sm text-muted-foreground">
            <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Checking connections...
        </div>
    </div>
</template>
