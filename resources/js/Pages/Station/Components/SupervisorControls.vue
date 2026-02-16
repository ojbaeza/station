<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { Badge } from '@/Components/ui/badge'
import { Button } from '@/Components/ui/button'

const props = defineProps({
    driverList: {
        type: Array,
        default: () => [],
    },
})

const status = ref({ running: false, pid: null, connection: null, queue: null, workers: 0 })
const formConnection = ref(props.driverList[0]?.value || '')
const formQueue = ref('default')
const formWorkers = ref(1)
const loading = ref(false)
let pollInterval = null

async function pollStatus() {
    try {
        const response = await fetch(route('station.api.supervisor.status'), {
            credentials: 'same-origin',
        })
        if (response.ok) {
            status.value = await response.json()
        }
    } catch {
        // Silently fail
    }
}

function startSupervisor() {
    loading.value = true
    router.post(route('station.api.supervisor.start'), {
        connection: formConnection.value,
        queue: formQueue.value,
        workers: formWorkers.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value = false
            pollStatus()
        },
    })
}

function stopSupervisor() {
    loading.value = true
    router.post(route('station.api.supervisor.stop'), {}, {
        preserveScroll: true,
        onFinish: () => {
            loading.value = false
            pollStatus()
        },
    })
}

onMounted(() => {
    pollStatus()
    pollInterval = setInterval(pollStatus, 5000)
})

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
    <div>
        <!-- Running supervisor -->
        <div v-if="status.running" class="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3">
            <div class="flex items-center gap-3">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                </span>
                <div class="flex items-center gap-4">
                    <span class="text-sm font-medium text-foreground">Supervisor</span>
                    <span class="text-xs text-muted-foreground">PID {{ status.pid }}</span>
                    <span v-if="status.connection" class="text-xs text-muted-foreground">{{ status.connection }} / {{ status.queue }}</span>
                    <Badge variant="outline" class="text-[10px]">{{ status.workers }} workers</Badge>
                </div>
            </div>
            <Button variant="destructive" size="sm" class="h-7 text-xs" :disabled="loading" @click="stopSupervisor">
                Stop
            </Button>
        </div>

        <!-- Launch form (when not running) -->
        <div v-else class="rounded-lg border border-border bg-card px-4 py-3">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="inline-flex rounded-full h-2.5 w-2.5 bg-zinc-500"></span>
                    <span class="font-medium text-foreground">Supervisor</span>
                    <Badge variant="secondary" class="text-[10px]">Stopped</Badge>
                </div>
                <div class="flex flex-wrap items-end gap-3 ml-auto">
                    <div>
                        <label class="text-[10px] text-muted-foreground block mb-0.5">Connection</label>
                        <select
                            v-model="formConnection"
                            class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                        >
                            <option v-for="d in driverList" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] text-muted-foreground block mb-0.5">Queue</label>
                        <input
                            v-model="formQueue"
                            type="text"
                            class="h-7 w-24 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                            placeholder="default"
                        />
                    </div>
                    <div>
                        <label class="text-[10px] text-muted-foreground block mb-0.5">Workers</label>
                        <div class="flex items-center">
                            <button
                                type="button"
                                class="h-7 w-7 rounded-l-md border border-input bg-background text-foreground flex items-center justify-center hover:bg-accent text-xs"
                                @click="formWorkers = Math.max(1, formWorkers - 1)"
                            >&minus;</button>
                            <span class="h-7 w-8 border-y border-input bg-background text-xs font-medium tabular-nums flex items-center justify-center">{{ formWorkers }}</span>
                            <button
                                type="button"
                                class="h-7 w-7 rounded-r-md border border-input bg-background text-foreground flex items-center justify-center hover:bg-accent text-xs"
                                @click="formWorkers = Math.min(10, formWorkers + 1)"
                            >+</button>
                        </div>
                    </div>
                    <Button size="sm" class="h-7 text-xs" :disabled="loading" @click="startSupervisor">
                        Launch
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
