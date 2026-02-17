<script setup>
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Badge } from '@/Components/ui/badge'
import { Button } from '@/Components/ui/button'

const props = defineProps({
    driverList: {
        type: Array,
        default: () => [],
    },
    active: {
        type: Boolean,
        default: true,
    },
})

const workerStatus = ref({})
const pauseStatus = ref({})
const supervisorStatus = ref({ running: false, pid: null, connection: null, queue: null, workers: 0 })
const loading = ref({})
let pollInterval = null

// Supervisor launch form
const formConnection = ref(props.driverList[0]?.value || '')
const formQueue = ref('default')
const formWorkers = ref(1)
const supervisorLoading = ref(false)

const defaultConnection = window.__stationDefaultConnection || null

const POLL_ACTIVE = 15000
const POLL_INACTIVE = 60000

async function pollStatus() {
    try {
        const res = await fetch(route('station.api.workers.dashboard-status'), { credentials: 'same-origin' })
        if (res.ok) {
            const data = await res.json()
            workerStatus.value = data.workers ?? {}
            pauseStatus.value = data.pauseStatus ?? {}
            supervisorStatus.value = data.supervisor ?? { running: false, pid: null, connection: null, queue: null, workers: 0 }
        }
    } catch {
        // Silently fail
    }
}

function resetPollInterval() {
    if (pollInterval) clearInterval(pollInterval)
    const interval = props.active ? POLL_ACTIVE : POLL_INACTIVE
    pollInterval = setInterval(pollStatus, interval)
}

watch(() => props.active, (isActive) => {
    resetPollInterval()
    if (isActive) pollStatus()
})

function getDriverStatus(connection) {
    const info = workerStatus.value[connection]
    const isPaused = pauseStatus.value[connection]?.paused || false

    if (!info || !info.running) {
        return { label: 'Stopped', dotClass: 'bg-zinc-500', textClass: 'text-muted-foreground' }
    }
    if (isPaused) {
        const count = info.workers?.length || 0
        return {
            label: `Paused (${count} worker${count !== 1 ? 's' : ''})`,
            dotClass: 'bg-orange-500',
            textClass: 'text-orange-400',
        }
    }
    const count = info.workers?.length || 0
    return {
        label: `Running (${count} worker${count !== 1 ? 's' : ''})`,
        dotClass: 'bg-emerald-500',
        textClass: 'text-emerald-600 dark:text-emerald-400',
    }
}

function isDriverRunning(connection) {
    return workerStatus.value[connection]?.running || false
}

function isDriverPaused(connection) {
    return pauseStatus.value[connection]?.paused || false
}

const hasAnyRunning = computed(() => {
    return props.driverList.some(d => isDriverRunning(d.value))
})

function getSupervisors(connection) {
    const workers = workerStatus.value[connection]?.workers || []
    return workers.filter(w => w.role === 'supervisor')
}

function getStandaloneWorkers(connection) {
    const workers = workerStatus.value[connection]?.workers || []
    const supervisorPids = new Set(getSupervisors(connection).map(s => s.pid))
    const childPids = new Set(getSupervisors(connection).flatMap(s => s.children || []))
    return workers.filter(w => !supervisorPids.has(w.pid) && !childPids.has(w.pid))
}

function getChildWorkers(connection, supervisorPid) {
    const workers = workerStatus.value[connection]?.workers || []
    const supervisor = workers.find(w => w.pid === supervisorPid)
    const childPids = new Set(supervisor?.children || [])
    return workers.filter(w => childPids.has(w.pid))
}

function startWorker(connection) {
    loading.value[`start-${connection}`] = true
    router.post(route('station.api.workers.start'), { connection }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value[`start-${connection}`] = false
            pollStatus()
        },
    })
}

function stopWorker(connection) {
    loading.value[`stop-${connection}`] = true
    router.post(route('station.api.workers.stop'), { connection }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value[`stop-${connection}`] = false
            pollStatus()
        },
    })
}

function stopExternalWorker(pid) {
    loading.value[`stop-ext-${pid}`] = true
    router.post(route('station.api.workers.stop-external'), { pid }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value[`stop-ext-${pid}`] = false
            pollStatus()
        },
    })
}

function pauseQueue(connection) {
    loading.value[`pause-${connection}`] = true
    router.post(route('station.api.queues.pause'), { queue: 'default', connection }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value[`pause-${connection}`] = false
            pollStatus()
        },
    })
}

function resumeQueue(connection) {
    loading.value[`resume-${connection}`] = true
    router.post(route('station.api.queues.resume'), { queue: 'default', connection }, {
        preserveScroll: true,
        onFinish: () => {
            loading.value[`resume-${connection}`] = false
            pollStatus()
        },
    })
}

function togglePause(connection) {
    if (isDriverPaused(connection)) {
        resumeQueue(connection)
    } else {
        pauseQueue(connection)
    }
}

function startAllWorkers() {
    const stopped = props.driverList.filter(d => !isDriverRunning(d.value))
    stopped.forEach(d => startWorker(d.value))
}

function pauseAllWorkers() {
    const running = props.driverList.filter(d => isDriverRunning(d.value))
    running.forEach(d => pauseQueue(d.value))
}

function stopAllWorkers() {
    const running = props.driverList.filter(d => isDriverRunning(d.value))
    running.forEach(d => stopWorker(d.value))
}

// Supervisor methods
function startSupervisor() {
    supervisorLoading.value = true
    router.post(route('station.api.supervisor.start'), {
        connection: formConnection.value,
        queue: formQueue.value,
        workers: formWorkers.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            supervisorLoading.value = false
            pollStatus()
        },
    })
}

function stopSupervisor() {
    supervisorLoading.value = true
    router.post(route('station.api.supervisor.stop'), {}, {
        preserveScroll: true,
        onFinish: () => {
            supervisorLoading.value = false
            pollStatus()
        },
    })
}

onMounted(() => {
    pollStatus()
    resetPollInterval()
})

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
    <div class="space-y-6">
        <!-- Launch Workers -->
        <div class="rounded-xl border border-emerald-500/30 bg-card/50 overflow-hidden relative">
            <div class="absolute inset-0 bg-linear-to-r from-emerald-500/5 to-transparent pointer-events-none"></div>
            <div class="relative p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="h-7 w-7 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                        <svg class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-sm font-semibold">Launch Workers</h3>
                    <!-- Supervisor status -->
                    <template v-if="supervisorStatus.running">
                        <span class="ml-2 relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span class="text-xs text-emerald-600 dark:text-emerald-400">
                            Supervisor PID {{ supervisorStatus.pid }}
                            <span v-if="supervisorStatus.connection" class="text-muted-foreground">· {{ supervisorStatus.connection }}/{{ supervisorStatus.queue }}</span>
                            <span class="text-muted-foreground">· {{ supervisorStatus.workers }} workers</span>
                        </span>
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-6 text-[10px] px-2 ml-auto"
                            :disabled="supervisorLoading"
                            @click="stopSupervisor"
                        >
                            Stop Supervisor
                        </Button>
                    </template>
                </div>
                <div v-if="!supervisorStatus.running" class="flex flex-wrap lg:flex-nowrap items-end gap-3">
                    <div class="w-full sm:w-auto flex-1 min-w-[140px] space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Connection</label>
                        <div class="flex h-9 items-center px-3 rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-emerald-500/50 transition-all">
                            <select
                                v-model="formConnection"
                                class="h-full w-full bg-transparent text-sm focus:outline-hidden cursor-pointer"
                            >
                                <option v-for="d in driverList" :key="d.value" :value="d.value">{{ d.label }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="w-full sm:w-auto flex-1 min-w-[100px] space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Queue</label>
                        <input
                            v-model="formQueue"
                            type="text"
                            class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:outline-hidden focus:ring-2 focus:ring-emerald-500/50 transition-all"
                            placeholder="default"
                        />
                    </div>
                    <div class="w-24 space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Workers</label>
                        <div class="flex h-9 rounded-lg border border-input bg-background overflow-hidden">
                            <button
                                type="button"
                                class="w-8 flex items-center justify-center text-zinc-400 hover:text-white hover:bg-zinc-700/50 transition-colors"
                                @click="formWorkers = Math.max(1, formWorkers - 1)"
                            >&minus;</button>
                            <span class="flex-1 text-sm text-center tabular-nums flex items-center justify-center">{{ formWorkers }}</span>
                            <button
                                type="button"
                                class="w-8 flex items-center justify-center text-zinc-400 hover:text-white hover:bg-zinc-700/50 transition-colors"
                                @click="formWorkers = Math.min(10, formWorkers + 1)"
                            >+</button>
                        </div>
                    </div>
                    <button
                        class="h-9 px-4 inline-flex items-center justify-center gap-2 rounded-lg text-sm font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-all disabled:opacity-50"
                        :disabled="supervisorLoading"
                        @click="startSupervisor"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Launch
                    </button>
                </div>
            </div>
        </div>

        <!-- Worker Controls -->
        <div class="rounded-xl border border-emerald-500/30 bg-card/50 overflow-hidden relative">
            <div class="absolute inset-0 bg-linear-to-r from-emerald-500/5 to-transparent pointer-events-none"></div>
            <div class="relative p-5">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <div class="h-7 w-7 rounded-lg bg-emerald-500/10 flex items-center justify-center">
                                <svg class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <h3 class="text-sm font-semibold">Worker Controls</h3>
                        </div>
                        <!-- Status legend -->
                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs translate-y-px">
                            <div class="flex items-center gap-1.5">
                                <span class="inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                <span class="text-muted-foreground">Running</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
                                <span class="text-muted-foreground">Paused</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="inline-flex rounded-full h-2 w-2 bg-zinc-500"></span>
                                <span class="text-muted-foreground">Stopped</span>
                            </div>
                        </div>
                    </div>
                    <!-- Bulk actions -->
                    <div class="flex gap-1.5">
                        <button
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-all disabled:opacity-50"
                            @click="startAllWorkers"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Quick Start All
                        </button>
                        <button
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 bg-orange-500/10 text-orange-400 border border-orange-500/30 hover:bg-orange-500/20 transition-all disabled:opacity-50"
                            :disabled="!hasAnyRunning"
                            @click="pauseAllWorkers"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Pause All
                        </button>
                        <button
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 bg-red-500/10 text-red-400 border border-red-500/30 hover:bg-red-500/20 transition-all disabled:opacity-50"
                            :disabled="!hasAnyRunning"
                            @click="stopAllWorkers"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                            </svg>
                            Stop All
                        </button>
                    </div>
                </div>

                <!-- Driver cards grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <div
                        v-for="driver in driverList"
                        :key="driver.value"
                        class="rounded-xl border border-border bg-card/50 p-3 space-y-2"
                    >
                        <!-- Driver header -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-2.5 w-2.5">
                                    <span
                                        v-if="isDriverRunning(driver.value) && !isDriverPaused(driver.value)"
                                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"
                                    ></span>
                                    <span :class="getDriverStatus(driver.value).dotClass" class="relative inline-flex rounded-full h-2.5 w-2.5"></span>
                                </span>
                                <span class="font-semibold text-sm">{{ driver.label }}</span>
                                <Badge v-if="defaultConnection === driver.value" variant="outline" class="text-[10px] px-1.5 py-0 text-emerald-600 dark:text-emerald-400 border-emerald-500/30">DEFAULT</Badge>
                            </div>
                            <span :class="getDriverStatus(driver.value).textClass" class="text-xs">
                                {{ getDriverStatus(driver.value).label }}
                            </span>
                        </div>

                        <!-- Action buttons -->
                        <div class="flex gap-1.5">
                            <button
                                class="flex-1 inline-flex items-center justify-center gap-1 rounded text-sm font-medium h-7 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 transition-all disabled:opacity-50"
                                :disabled="loading[`start-${driver.value}`] || isDriverRunning(driver.value)"
                                @click="startWorker(driver.value)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Quick Start
                            </button>
                            <button
                                class="flex-1 inline-flex items-center justify-center gap-1 rounded text-sm font-medium h-7 bg-orange-500/10 text-orange-400 border border-orange-500/30 hover:bg-orange-500/20 transition-all disabled:opacity-50"
                                :disabled="!isDriverRunning(driver.value)"
                                @click="togglePause(driver.value)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ isDriverPaused(driver.value) ? 'Resume' : 'Pause' }}
                            </button>
                            <button
                                class="flex-1 inline-flex items-center justify-center gap-1 rounded text-sm font-medium h-7 bg-red-500/10 text-red-400 border border-red-500/30 hover:bg-red-500/20 transition-all disabled:opacity-50"
                                :disabled="loading[`stop-${driver.value}`] || !isDriverRunning(driver.value)"
                                @click="stopWorker(driver.value)"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                                </svg>
                                Stop
                            </button>
                        </div>

                        <!-- Worker hierarchy (when running) -->
                        <div v-if="isDriverRunning(driver.value) && workerStatus[driver.value]?.workers?.length" class="space-y-1 mt-1">
                            <!-- Supervisors with children -->
                            <template v-for="sup in getSupervisors(driver.value)" :key="sup.pid">
                                <div class="flex items-center justify-between text-xs bg-zinc-500/5 rounded-lg px-3 py-2 border border-zinc-500/10">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                        <span class="text-zinc-400 font-mono">PID {{ sup.pid }}</span>
                                        <span class="text-zinc-500">supervisor · {{ sup.children?.length || 0 }} worker{{ (sup.children?.length || 0) !== 1 ? 's' : '' }}</span>
                                    </div>
                                    <button
                                        class="text-red-400/70 hover:text-red-400 hover:bg-red-500/10 px-2 py-0.5 rounded transition-all"
                                        @click="stopExternalWorker(sup.pid)"
                                    >
                                        Stop
                                    </button>
                                </div>
                                <!-- Child workers -->
                                <div
                                    v-for="child in getChildWorkers(driver.value, sup.pid)"
                                    :key="child.pid"
                                    class="flex items-center justify-between text-xs bg-zinc-500/5 rounded-lg px-3 py-2 border border-zinc-500/10 ml-4"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="text-zinc-600">&#x2514;</span>
                                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                        <span class="text-zinc-400 font-mono">PID {{ child.pid }}</span>
                                        <span class="text-zinc-500">worker</span>
                                    </div>
                                </div>
                            </template>
                            <!-- Standalone workers (no supervisor parent) -->
                            <div
                                v-for="worker in getStandaloneWorkers(driver.value)"
                                :key="worker.pid"
                                class="flex items-center justify-between text-xs bg-zinc-500/5 rounded-lg px-3 py-2 border border-zinc-500/10"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span class="text-zinc-400 font-mono">PID {{ worker.pid }}</span>
                                    <span class="text-zinc-500">worker</span>
                                </div>
                                <button
                                    class="text-red-400/70 hover:text-red-400 hover:bg-red-500/10 px-2 py-0.5 rounded transition-all"
                                    @click="stopExternalWorker(worker.pid)"
                                >
                                    Stop
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
