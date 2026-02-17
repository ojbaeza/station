<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import HealthIndicator from './Components/HealthIndicator.vue'
import ConnectionCardStats from './Components/ConnectionCardStats.vue'

const props = defineProps({
    connections: Object,
    driverList: Array,
    health: Object,
    driverInfo: { type: Object, default: () => ({}) },
})

const liveConnections = ref({ ...props.connections })
const liveHealth = ref(props.health || {})
const liveDriverInfo = ref(props.driverInfo || {})
const driverTimeSeries = ref({})
const workerData = ref({ workers: {}, pauseStatus: {}, supervisor: { running: false } })
const actionLoading = ref({})
const actionErrors = ref({})
const selectedPeriod = ref('1h')
const processManagementDisabled = ref(false)

// Launch form state
const launchConnection = ref('')
const launchQueue = ref('default')
const launchWorkers = ref(1)
const launchError = ref('')
const launchSuccess = ref('')

// Connection tab bar state (persisted to localStorage)
const CONN_STORAGE_KEY = 'station:queues:active-conn'
const activeConnection = ref((() => {
    try { return localStorage.getItem(CONN_STORAGE_KEY) || '' } catch { return '' }
})())

const driverLabels = {
    rabbitmq: 'RabbitMQ',
    redis: 'Redis',
    sqs: 'Amazon SQS',
    beanstalkd: 'Beanstalkd',
    kafka: 'Apache Kafka',
}

// Auto-refresh via inject
const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(5000) })
const pollTimer = ref(null)

function getCsrfHeaders() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content
    if (meta) return { 'X-CSRF-TOKEN': meta }
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    if (match) return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) }
    return {}
}

const connectionNames = computed(() => Object.keys(liveConnections.value))

// Ordered connections: default connection first, rest in original order
const orderedConnectionNames = computed(() => {
    const names = connectionNames.value
    const def = names.find(n => liveConnections.value[n]?.is_default)
    if (!def) return names
    return [def, ...names.filter(n => n !== def)]
})

const defaultConnection = computed(() => {
    for (const [name, conn] of Object.entries(liveConnections.value)) {
        if (conn.is_default) return name
    }
    return connectionNames.value[0] || ''
})

// Merged connections: connectivity data + process data
const mergedConnections = computed(() => {
    const result = {}
    for (const [name, conn] of Object.entries(liveConnections.value)) {
        const allWorkers = workerData.value.workers[name]?.workers || []
        const workersByPid = Object.fromEntries(allWorkers.map(w => [w.pid, w]))
        const supervisors = allWorkers.filter(w => (w.role || '') === 'supervisor')

        // Enrich supervisor children: replace bare PIDs with full worker objects
        const childPids = new Set()
        for (const sup of supervisors) {
            sup.childWorkers = (sup.children || []).map(pid => {
                childPids.add(typeof pid === 'object' ? pid.pid : pid)
                return workersByPid[pid] || { pid, queue: null, cpu: 0, memory_mb: 0 }
            })
        }

        // Standalone workers: exclude supervisors and children
        const standalone = allWorkers.filter(w => (w.role || '') !== 'supervisor' && !childPids.has(w.pid))

        const pause = workerData.value.pauseStatus[name] || {}

        // Determine if any queue is paused from live pause data
        let isPaused = conn.paused
        for (const qs of Object.values(pause)) {
            if (typeof qs === 'object' && qs?.paused) {
                isPaused = true
                break
            }
        }

        const supervisorChildCount = supervisors.reduce((sum, s) => sum + (s.childWorkers?.length || 0), 0)

        result[name] = {
            ...conn,
            paused: isPaused,
            supervisors,
            workerProcesses: standalone,
            liveWorkerCount: standalone.length + supervisorChildCount,
            totalProcessCount: allWorkers.length,
        }
    }
    return result
})

const totalWorkers = computed(() => {
    return Object.values(mergedConnections.value).reduce((sum, c) => sum + (c.liveWorkerCount || 0), 0)
})

const anyPaused = computed(() => Object.values(mergedConnections.value).some(c => c.paused))
const anyRunning = computed(() => totalWorkers.value > 0)
const hasDefaultQueueWorker = (name) => {
    const conn = mergedConnections.value[name]
    if (!conn) return false
    const defaultQueue = liveConnections.value[name]?.config?.queue || 'default'
    const allWorkers = [...(conn.workerProcesses || []), ...(conn.supervisors?.flatMap(s => s.childWorkers || []) || [])]
    return allWorkers.some(w => w.queue === defaultQueue || w.queue?.split(',').includes(defaultQueue))
}

const allRunning = computed(() => {
    const names = connectionNames.value
    return names.length > 0 && names.every(name => hasDefaultQueueWorker(name))
})

const launchDuplicateWarning = computed(() => {
    if (!launchConnection.value) return ''
    const conn = mergedConnections.value[launchConnection.value]
    if (!conn) return ''
    const queue = launchQueue.value || 'default'
    const allWorkers = [...(conn.workerProcesses || []), ...(conn.supervisors?.flatMap(s => s.childWorkers || []) || [])]
    const exists = allWorkers.some(w => w.queue === queue || w.queue?.split(',').includes(queue))
    return exists ? `A worker is already running for ${launchConnection.value}:${queue}` : ''
})

onMounted(() => {
    if (!launchConnection.value) {
        launchConnection.value = defaultConnection.value
    }
    if (!activeConnection.value || !connectionNames.value.includes(activeConnection.value)) {
        activeConnection.value = connectionNames.value[0] || ''
    }
    pollData()
})

watch(activeConnection, (v) => {
    try { localStorage.setItem(CONN_STORAGE_KEY, v) } catch {}
})

watch(selectedPeriod, () => {
    // Clear cached time-series for active connection so it re-fetches with new period
    if (activeConnection.value) {
        driverTimeSeries.value[activeConnection.value] = null
    }
    pollData()
})

async function pollData() {
    try {
        const [connRes, dashRes, healthRes] = await Promise.all([
            fetch(route('station.api.queues.connections'), { credentials: 'same-origin' }),
            fetch(route('station.api.workers.dashboard-status'), { credentials: 'same-origin' }),
            fetch(route('station.api.health'), { credentials: 'same-origin' }),
        ])
        if (connRes.ok) liveConnections.value = await connRes.json()
        if (dashRes.ok) {
            const dashData = await dashRes.json()
            workerData.value = dashData
            if (dashData.driverInfo) liveDriverInfo.value = dashData.driverInfo
            processManagementDisabled.value = false
        }
        if (healthRes.ok) liveHealth.value = await healthRes.json()

        // Fetch time-series for the active connection
        if (activeConnection.value) {
            try {
                const tsRes = await fetch(
                    route('station.api.metrics.driver-time-series') + `?connection=${encodeURIComponent(activeConnection.value)}&period=${encodeURIComponent(selectedPeriod.value)}`,
                    { credentials: 'same-origin' },
                )
                if (tsRes.ok) driverTimeSeries.value[activeConnection.value] = await tsRes.json()
            } catch { /* ignore */ }
        }
    } catch (e) {
        if (e.message?.includes('Process management is disabled')) {
            processManagementDisabled.value = true
        }
    }
}

const stopPolling = () => { clearInterval(pollTimer.value); pollTimer.value = null }

const effectiveInterval = computed(() => {
    const base = autoRefresh.interval.value
    return autoRefresh.focused?.value === false ? base * 6 : base
})

watch([() => autoRefresh.enabled.value, effectiveInterval], ([enabled, ms]) => {
    stopPolling()
    if (enabled) { pollTimer.value = setInterval(pollData, ms) }
}, { immediate: true })

watch(() => autoRefresh.focused?.value, (focused) => {
    if (focused && autoRefresh.enabled.value) pollData()
})

onUnmounted(stopPolling)

// Loading/error state helpers
async function withLoading(key, fn) {
    actionLoading.value[key] = true
    actionErrors.value[key] = ''
    try {
        await fn()
    } catch (e) {
        actionErrors.value[key] = e.message || 'Action failed'
    } finally {
        actionLoading.value[key] = false
    }
}

function isLoading(key) { return !!actionLoading.value[key] }
function errorFor(key) { return actionErrors.value[key] || '' }

async function postAction(routeName, body = {}) {
    const res = await fetch(route(routeName), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...getCsrfHeaders(),
        },
        body: JSON.stringify(body),
    })
    if (!res.ok) {
        const data = await res.json().catch(() => ({}))
        throw new Error(data.error || `Request failed (${res.status})`)
    }
    return res
}

// Connection actions
async function quickStart(name) {
    await withLoading(`start-${name}`, async () => {
        await postAction('station.api.workers.start', {
            connection: name,
            queue: liveConnections.value[name]?.config?.queue || 'default',
            workers: 1,
        })
        await pollData()
    })
}

async function togglePause(name) {
    const conn = mergedConnections.value[name]
    if (!conn) return
    const key = conn.paused ? `resume-${name}` : `pause-${name}`
    await withLoading(key, async () => {
        const routeName = conn.paused ? 'station.api.queues.resume' : 'station.api.queues.pause'
        await postAction(routeName, { queue: conn.config?.queue || 'default', connection: name })
        await pollData()
    })
}

async function stopWorkers(name) {
    if (!confirm(`Stop all workers on ${name}?`)) return
    await withLoading(`stop-${name}`, async () => {
        await postAction('station.api.workers.stop', { connection: name })
        await pollData()
    })
}

async function stopExternalWorker(pid) {
    if (!confirm(`Stop worker PID ${pid}?`)) return
    await withLoading(`stop-pid-${pid}`, async () => {
        await postAction('station.api.workers.stop-external', { pid })
        await pollData()
    })
}

async function launchWorkerAction() {
    await withLoading('launch-worker', async () => {
        launchError.value = ''
        launchSuccess.value = ''
        await postAction('station.api.supervisor.start', {
            connection: launchConnection.value,
            queue: launchQueue.value || 'default',
            workers: launchWorkers.value,
        })
        launchSuccess.value = `Launched ${launchWorkers.value} ${launchWorkers.value === 1 ? 'worker' : 'workers'} on ${launchConnection.value}:${launchQueue.value || 'default'}`
        setTimeout(() => { launchSuccess.value = '' }, 5000)
        await pollData()
    })
    if (errorFor('launch-worker')) {
        launchError.value = errorFor('launch-worker')
    }
}

// Bulk actions
async function startAll() {
    await withLoading('start-all', async () => {
        for (const name of connectionNames.value) {
            if (hasDefaultQueueWorker(name)) continue
            try {
                await postAction('station.api.workers.start', {
                    connection: name,
                    queue: liveConnections.value[name]?.config?.queue || 'default',
                    workers: 1,
                })
            } catch { /* continue */ }
        }
        await pollData()
    })
}

async function pauseAll() {
    await withLoading('pause-all', async () => {
        for (const name of connectionNames.value) {
            const conn = mergedConnections.value[name]
            if (conn && !conn.paused) {
                try {
                    await postAction('station.api.queues.pause', {
                        queue: conn.config?.queue || 'default',
                        connection: name,
                    })
                } catch { /* continue */ }
            }
        }
        await pollData()
    })
}

async function resumeAll() {
    await withLoading('resume-all', async () => {
        for (const name of connectionNames.value) {
            const conn = mergedConnections.value[name]
            if (conn?.paused) {
                try {
                    await postAction('station.api.queues.resume', {
                        queue: conn.config?.queue || 'default',
                        connection: name,
                    })
                } catch { /* continue */ }
            }
        }
        await pollData()
    })
}

async function stopAll() {
    if (!confirm('Stop all workers across all connections?')) return
    await withLoading('stop-all', async () => {
        for (const name of connectionNames.value) {
            try {
                await postAction('station.api.workers.stop', { connection: name })
            } catch { /* continue */ }
        }
        await pollData()
    })
}

async function recoverStuck() {
    await withLoading('recover', async () => {
        await postAction('station.api.recover', { strategy: 'graceful' })
        await pollData()
    })
}

function driverInfoFor(name) { return liveDriverInfo.value[name] || null }
function timeSeriesFor(name) { return driverTimeSeries.value[name] || null }

const getStatusColor = (status) => {
    const colors = {
        running: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        idle: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        paused: 'bg-secondary text-muted-foreground',
        stopped: 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',
    }
    return colors[status] || colors.idle
}
</script>

<template>
    <Head title="Connections - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Process Management Disabled Banner -->
            <div v-if="processManagementDisabled" class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-lg p-4">
                <div class="flex gap-3">
                    <svg class="h-5 w-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-sm">
                        <p class="font-medium text-amber-800 dark:text-amber-300">Process management is disabled</p>
                        <p class="mt-1 text-amber-700 dark:text-amber-400/80">
                            Set <code class="font-mono text-xs bg-amber-100 dark:bg-amber-500/20 px-1 py-0.5 rounded">STATION_PROCESS_MANAGEMENT=true</code> in your <code class="font-mono text-xs">.env</code> to enable worker management from the dashboard.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-foreground">Connections</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Worker command center &mdash; manage connections, workers, and supervisors</p>
                </div>
                <div class="flex items-center gap-3">
                    <HealthIndicator :health="liveHealth" />
                    <Link
                        v-if="liveHealth?.stuck_jobs > 0"
                        :href="route('station.stuck')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md text-white bg-amber-600 hover:bg-amber-700"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        {{ liveHealth.stuck_jobs }} Stuck Jobs
                    </Link>
                </div>
            </div>

            <!-- Health Issues -->
            <div v-if="liveHealth?.issues?.length > 0" class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/50 rounded-lg p-4">
                <h3 class="text-sm font-medium text-red-700 dark:text-red-400 mb-2">Issues Detected</h3>
                <ul class="list-disc list-inside text-sm text-red-600 dark:text-red-300 space-y-1">
                    <li v-for="issue in liveHealth.issues" :key="issue">{{ issue }}</li>
                </ul>
            </div>

            <!-- Launch Workers (always visible) -->
            <div class="rounded-lg border border-border bg-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <svg class="h-4 w-4 text-emerald-600 dark:text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h3 class="text-sm font-semibold text-foreground">Launch Workers</h3>
                </div>
                <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-end gap-3">
                    <div class="w-full sm:w-auto sm:flex-1 sm:min-w-[140px] space-y-1.5">
                        <label class="text-sm font-medium text-muted-foreground">Connection</label>
                        <select
                            v-model="launchConnection"
                            class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-1 text-sm focus:outline-hidden focus:ring-2 focus:ring-emerald-500/50 cursor-pointer"
                        >
                            <option v-for="name in connectionNames" :key="name" :value="name">
                                {{ name }}
                            </option>
                        </select>
                    </div>
                    <div class="w-full sm:w-auto sm:flex-1 sm:min-w-[100px] space-y-1.5">
                        <label class="text-sm font-medium text-muted-foreground">Queue</label>
                        <input
                            v-model="launchQueue"
                            type="text"
                            placeholder="default"
                            class="flex h-9 w-full rounded-lg border border-input bg-background px-3 py-1 text-sm focus:outline-hidden focus:ring-2 focus:ring-emerald-500/50"
                        />
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="w-24 space-y-1.5">
                            <label class="text-sm font-medium text-muted-foreground">Workers</label>
                            <div class="flex h-9 rounded-lg border border-input bg-background overflow-hidden">
                                <button
                                    @click="launchWorkers = Math.max(1, launchWorkers - 1)"
                                    class="w-8 flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                                >&minus;</button>
                                <input
                                    v-model.number="launchWorkers"
                                    type="number"
                                    min="1"
                                    max="10"
                                    class="w-8 border-0 border-x border-input bg-transparent text-sm text-foreground text-center focus:outline-hidden [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                                />
                                <button
                                    @click="launchWorkers = Math.min(10, launchWorkers + 1)"
                                    class="w-8 flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                                >+</button>
                            </div>
                        </div>
                        <button
                            @click="launchWorkerAction"
                            :disabled="isLoading('launch-worker')"
                            class="h-9 px-4 inline-flex items-center justify-center gap-2 rounded-lg text-sm font-medium border border-border text-muted-foreground hover:bg-secondary hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors disabled:opacity-50"
                        >
                            <svg v-if="!isLoading('launch-worker')" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-else class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ isLoading('launch-worker') ? 'Launching...' : 'Launch' }}
                        </button>
                    </div>
                </div>
                <p v-if="launchError" class="mt-3 text-sm text-red-500 flex items-center gap-1.5">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ launchError }}
                </p>
                <p v-if="launchSuccess" class="mt-3 text-sm text-emerald-600 dark:text-emerald-500 flex items-center gap-1.5">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ launchSuccess }}
                </p>
                <p v-if="launchDuplicateWarning && !launchSuccess" class="mt-3 text-sm text-amber-600 dark:text-amber-400 flex items-center gap-1.5">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    {{ launchDuplicateWarning }}
                </p>
            </div>

            <!-- Worker Controls -->
            <div v-if="connectionNames.length > 0" class="rounded-lg border border-border bg-card p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-emerald-600 dark:text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <h3 class="text-sm font-semibold text-foreground whitespace-nowrap">Worker Controls</h3>
                        </div>
                        <div class="hidden sm:flex flex-wrap gap-x-3 gap-y-1 text-xs translate-y-px">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span><span class="text-muted-foreground">Running</span></span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span><span class="text-muted-foreground">Paused</span></span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-zinc-500"></span><span class="text-muted-foreground">Stopped</span></span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            @click="startAll"
                            :disabled="isLoading('start-all') || allRunning"
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 border border-border text-muted-foreground hover:bg-secondary hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors disabled:opacity-50"
                        >
                            <svg v-if="!isLoading('start-all')" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <svg v-else class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ isLoading('start-all') ? 'Starting...' : 'Quick Start All' }}
                        </button>
                        <button
                            v-if="!anyPaused"
                            @click="pauseAll"
                            :disabled="isLoading('pause-all') || !anyRunning"
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 border border-border text-muted-foreground hover:bg-secondary hover:text-amber-400 transition-colors disabled:opacity-50"
                        >
                            <svg v-if="!isLoading('pause-all')" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <svg v-else class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ isLoading('pause-all') ? 'Pausing...' : 'Pause All' }}
                        </button>
                        <button
                            v-if="anyPaused"
                            @click="resumeAll"
                            :disabled="isLoading('resume-all')"
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 border border-border text-muted-foreground hover:bg-secondary hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors disabled:opacity-50"
                        >
                            <svg v-if="!isLoading('resume-all')" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <svg v-else class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ isLoading('resume-all') ? 'Resuming...' : 'Resume All' }}
                        </button>
                        <button
                            @click="stopAll"
                            :disabled="isLoading('stop-all') || !anyRunning"
                            class="inline-flex items-center justify-center gap-1 rounded-lg text-xs font-medium h-7 px-2.5 border border-border text-muted-foreground hover:bg-secondary hover:text-red-400 transition-colors disabled:opacity-50"
                        >
                            <svg v-if="!isLoading('stop-all')" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" /></svg>
                            <svg v-else class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                            {{ isLoading('stop-all') ? 'Stopping...' : 'Stop All' }}
                        </button>
                    </div>
                </div>

                <!-- Connection Tab Bar -->
                <div class="flex overflow-x-auto scrollbar-hide border-b border-border -mx-5 px-5 gap-1">
                    <button
                        v-for="name in orderedConnectionNames"
                        :key="name"
                        @click="activeConnection = name"
                        class="flex-shrink-0 sm:flex-shrink sm:flex-1 sm:min-w-0 px-3 sm:px-4 py-2.5 border-b-2 transition-colors text-left"
                        :class="activeConnection === name
                            ? 'border-indigo-500'
                            : 'border-transparent hover:border-border'"
                    >
                        <div class="flex items-center gap-2">
                            <span class="relative flex h-2.5 w-2.5 flex-shrink-0">
                                <span
                                    v-if="mergedConnections[name].totalProcessCount > 0 && !mergedConnections[name].paused"
                                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"
                                ></span>
                                <span
                                    :class="mergedConnections[name].totalProcessCount > 0 ? (mergedConnections[name].paused ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-zinc-500'"
                                    class="relative inline-flex rounded-full h-2.5 w-2.5"
                                ></span>
                            </span>
                            <span class="text-sm font-medium whitespace-nowrap" :class="activeConnection === name ? 'text-foreground' : 'text-muted-foreground'">{{ driverLabels[mergedConnections[name].driver] || mergedConnections[name].driver }}</span>
                            <span v-if="mergedConnections[name].is_default" class="hidden sm:inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400">
                                DEFAULT
                            </span>
                        </div>
                        <div class="hidden sm:block mt-0.5 pl-[18px] text-[10px] text-muted-foreground tabular-nums">
                            <span>{{ name }}</span>
                            <template v-if="mergedConnections[name].totalProcessCount > 0 && mergedConnections[name].paused"> &middot; Paused ({{ mergedConnections[name].liveWorkerCount }})</template>
                            <template v-else-if="mergedConnections[name].totalProcessCount > 0"> &middot; {{ mergedConnections[name].liveWorkerCount }} {{ mergedConnections[name].liveWorkerCount === 1 ? 'worker' : 'workers' }}</template>
                            <template v-else> &middot; Stopped</template>
                            <template v-if="mergedConnections[name].latency_ms > 0"> &middot; {{ mergedConnections[name].latency_ms }}ms</template>
                        </div>
                    </button>
                </div>

                <!-- Active Connection Panel -->
                <template v-if="activeConnection && mergedConnections[activeConnection]">
                    <div class="mt-4 space-y-4">
                        <!-- Workers Card -->
                        <div class="rounded-lg border border-border bg-card overflow-hidden">
                            <div class="px-4 py-2.5 border-b border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Workers</h4>
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        @click="quickStart(activeConnection)"
                                        :disabled="isLoading(`start-${activeConnection}`) || mergedConnections[activeConnection].liveWorkerCount > 0"
                                        class="inline-flex items-center justify-center gap-1 rounded text-xs font-medium h-6 px-2 border border-border text-muted-foreground hover:bg-secondary hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors disabled:opacity-50"
                                    >
                                        <svg v-if="!isLoading(`start-${activeConnection}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                        {{ isLoading(`start-${activeConnection}`) ? 'Starting...' : 'Quick Start' }}
                                    </button>
                                    <button
                                        @click="togglePause(activeConnection)"
                                        :disabled="isLoading(`pause-${activeConnection}`) || isLoading(`resume-${activeConnection}`) || mergedConnections[activeConnection].totalProcessCount === 0"
                                        class="inline-flex items-center justify-center gap-1 rounded text-xs font-medium h-6 px-2 border border-border text-muted-foreground transition-colors disabled:opacity-50"
                                        :class="mergedConnections[activeConnection].paused ? 'hover:bg-secondary hover:text-emerald-600 dark:hover:text-emerald-400' : 'hover:bg-secondary hover:text-amber-400'"
                                    >
                                        <template v-if="mergedConnections[activeConnection].paused">
                                            <svg v-if="!isLoading(`resume-${activeConnection}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                            Resume
                                        </template>
                                        <template v-else>
                                            <svg v-if="!isLoading(`pause-${activeConnection}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                            Pause
                                        </template>
                                    </button>
                                    <button
                                        @click="stopWorkers(activeConnection)"
                                        :disabled="isLoading(`stop-${activeConnection}`) || mergedConnections[activeConnection].totalProcessCount === 0"
                                        class="inline-flex items-center justify-center gap-1 rounded text-xs font-medium h-6 px-2 border border-border text-muted-foreground hover:bg-secondary hover:text-red-400 transition-colors disabled:opacity-50"
                                    >
                                        <svg v-if="!isLoading(`stop-${activeConnection}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" /></svg>
                                        <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                        Stop
                                    </button>
                                </div>
                            </div>
                            <div class="px-4 py-3 space-y-3">
                                <!-- Inline Error -->
                                <p v-if="errorFor(`start-${activeConnection}`) || errorFor(`stop-${activeConnection}`)" class="text-xs text-red-500 flex items-center gap-1">
                                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    {{ errorFor(`start-${activeConnection}`) || errorFor(`stop-${activeConnection}`) }}
                                </p>

                                <!-- Process Tree -->
                                <div v-if="mergedConnections[activeConnection].supervisors.length > 0 || mergedConnections[activeConnection].workerProcesses.length > 0">
                                    <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Process Tree</h4>
                                    <div class="space-y-1 text-xs font-mono overflow-x-auto">
                                        <!-- Supervisors with their children -->
                                        <template v-for="sup in mergedConnections[activeConnection].supervisors" :key="sup.pid">
                                            <div class="flex items-center gap-2 py-1">
                                                <span class="h-2 w-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
                                                <span class="text-foreground">PID {{ sup.pid }}</span>
                                                <span class="text-muted-foreground">supervisor</span>
                                                <span v-if="sup.childWorkers?.length" class="text-muted-foreground">&middot; {{ sup.childWorkers.length }} workers</span>
                                                <span v-if="sup.cpu !== undefined" title="CPU usage" class="px-1 py-0.5 rounded bg-blue-500/10 text-blue-400 text-[10px]">{{ sup.cpu }}%</span>
                                                <span v-if="sup.memory_mb !== undefined" title="Memory (RSS)" class="px-1 py-0.5 rounded bg-purple-500/10 text-purple-400 text-[10px]">{{ sup.memory_mb }} MB</span>
                                                <button
                                                    @click="stopExternalWorker(sup.pid)"
                                                    :disabled="isLoading(`stop-pid-${sup.pid}`)"
                                                    class="ml-auto text-red-500 hover:text-red-400 disabled:opacity-50 flex items-center gap-0.5"
                                                >
                                                    <svg v-if="!isLoading(`stop-pid-${sup.pid}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                    <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                                    Stop
                                                </button>
                                            </div>
                                            <div v-for="child in (sup.childWorkers || [])" :key="child.pid" class="flex items-center gap-2 py-0.5 pl-6 text-muted-foreground">
                                                <span class="text-muted-foreground/50">&hairsp;&#x2514;&#x2500;</span>
                                                <span>PID {{ child.pid }}</span>
                                                <span v-if="child.queue" class="px-1 py-0.5 rounded bg-secondary text-[10px]">{{ child.queue }}</span>
                                                <span v-if="child.cpu !== undefined" title="CPU usage" class="px-1 py-0.5 rounded bg-blue-500/10 text-blue-400 text-[10px]">{{ child.cpu }}%</span>
                                                <span v-if="child.memory_mb !== undefined" title="Memory (RSS)" class="px-1 py-0.5 rounded bg-purple-500/10 text-purple-400 text-[10px]">{{ child.memory_mb }} MB</span>
                                            </div>
                                        </template>

                                        <!-- Standalone workers (no supervisor parent) -->
                                        <template v-for="w in mergedConnections[activeConnection].workerProcesses" :key="w.pid">
                                            <div class="flex items-center gap-2 py-1">
                                                <span class="h-2 w-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
                                                <span class="text-foreground">PID {{ w.pid }}</span>
                                                <span v-if="w.queue" class="px-1 py-0.5 rounded bg-secondary text-[10px] text-muted-foreground">{{ w.queue }}</span>
                                                <span :class="getStatusColor(w.status)" class="px-1.5 py-0.5 rounded text-[10px] font-medium">
                                                    {{ w.status || 'running' }}
                                                </span>
                                                <span v-if="w.cpu !== undefined" title="CPU usage" class="px-1 py-0.5 rounded bg-blue-500/10 text-blue-400 text-[10px]">{{ w.cpu }}%</span>
                                                <span v-if="w.memory_mb !== undefined" title="Memory (RSS)" class="px-1 py-0.5 rounded bg-purple-500/10 text-purple-400 text-[10px]">{{ w.memory_mb }} MB</span>
                                                <button
                                                    @click="stopExternalWorker(w.pid)"
                                                    :disabled="isLoading(`stop-pid-${w.pid}`)"
                                                    class="ml-auto text-red-500 hover:text-red-400 disabled:opacity-50 flex items-center gap-0.5"
                                                >
                                                    <svg v-if="!isLoading(`stop-pid-${w.pid}`)" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                    <svg v-else class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                                    Stop
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div v-else class="py-4 text-center text-xs text-muted-foreground">
                                    No workers running
                                </div>
                            </div>
                        </div>

                        <!-- Driver Stats Card -->
                        <div class="rounded-lg border border-border bg-card overflow-hidden">
                            <div class="px-4 py-2.5 border-b border-border flex items-center justify-between">
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Driver Stats</h4>
                                <select
                                    v-model="selectedPeriod"
                                    class="h-7 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:outline-hidden focus:ring-2 focus:ring-emerald-500/50 cursor-pointer"
                                >
                                    <option value="5m">Last 5 min</option>
                                    <option value="15m">Last 15 min</option>
                                    <option value="1h">Last 1 hour</option>
                                    <option value="6h">Last 6 hours</option>
                                    <option value="24h">Last 24 hours</option>
                                </select>
                            </div>
                            <div class="px-4 py-3">
                                <ConnectionCardStats :info="driverInfoFor(activeConnection)" :timeSeries="timeSeriesFor(activeConnection)" :dashboardUrl="mergedConnections[activeConnection]?.dashboard_url" />
                            </div>
                        </div>

                        <!-- Config Card -->
                        <div class="rounded-lg border border-border bg-card overflow-hidden">
                            <div class="px-4 py-2.5 border-b border-border">
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Config</h4>
                            </div>
                            <div v-if="mergedConnections[activeConnection].config && Object.keys(mergedConnections[activeConnection].config).length > 0" class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-border text-sm">
                                    <tbody class="divide-y divide-border">
                                        <tr v-for="(value, key) in mergedConnections[activeConnection].config" :key="key" class="hover:bg-secondary/50">
                                            <td class="px-3 py-2 text-xs font-mono text-muted-foreground whitespace-nowrap w-1/3">{{ key }}</td>
                                            <td class="px-3 py-2 text-xs font-mono text-foreground">{{ value ?? '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="px-4 py-4 text-center text-xs text-muted-foreground">
                                No configuration available
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Empty State -->
            <div v-if="connectionNames.length === 0" class="rounded-lg border border-border bg-card p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-muted-foreground/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                </svg>
                <p class="mt-4 text-sm text-muted-foreground">No Station queue connections configured</p>
                <p class="mt-1 text-xs text-muted-foreground">Configure queue connections in your <code class="font-mono">config/queue.php</code></p>
            </div>
        </div>
    </StationLayout>
</template>
