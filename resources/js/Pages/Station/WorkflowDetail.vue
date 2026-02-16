<template>
  <Layout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
          <Link
            :href="route('station.workflows')"
            class="rounded-lg bg-secondary p-2 text-muted-foreground hover:bg-secondary/80 hover:text-foreground"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-foreground">
              {{ instance.definition_name }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
              Instance: {{ instance.id }}
            </p>
          </div>
        </div>
        <div class="flex items-center space-x-3">
          <span :class="statusClass(instance.status)" class="px-2 py-0.5 inline-flex text-xs font-medium rounded">
            {{ instance.status }}
          </span>
          <button
            v-if="instance.status === 'running'"
            @click="pause"
            class="inline-flex items-center rounded-lg bg-amber-500/20 px-4 py-2 text-sm font-medium text-amber-400 hover:bg-amber-500/30"
          >
            Pause
          </button>
          <button
            v-if="instance.status === 'paused'"
            @click="resume"
            class="inline-flex items-center rounded-lg bg-emerald-500/20 px-4 py-2 text-sm font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/30"
          >
            Resume
          </button>
          <button
            v-if="['running', 'paused'].includes(instance.status)"
            @click="cancel"
            class="inline-flex items-center rounded-lg bg-red-500/20 px-4 py-2 text-sm font-medium text-red-400 hover:bg-red-500/30"
          >
            Cancel
          </button>
        </div>
      </div>

      <!-- Progress -->
      <div class="rounded-lg bg-card border border-border p-6">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-foreground">Progress</h2>
          <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-500">{{ instance.progress }}%</span>
        </div>
        <div class="h-4 w-full rounded-full bg-secondary">
          <div
            :style="{ width: instance.progress + '%' }"
            :class="progressClass(instance.status)"
            class="h-4 rounded-full transition-all duration-500"
          ></div>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
          <div>
            <p class="text-sm text-muted-foreground">Started</p>
            <p class="text-sm font-medium text-foreground">{{ formatDate(instance.started_at) }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Duration</p>
            <p class="text-sm font-medium text-foreground">{{ formatDuration(instance.duration) }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Current Step</p>
            <p class="text-sm font-medium text-foreground">{{ instance.current_step || '-' }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Connection</p>
            <p class="text-sm font-medium text-foreground">{{ instance.connection || 'default' }}</p>
          </div>
          <div>
            <p class="text-sm text-muted-foreground">Completed</p>
            <p class="text-sm font-medium text-foreground">{{ formatDate(instance.completed_at) }}</p>
          </div>
        </div>
      </div>

      <!-- Step Flow -->
      <div class="rounded-lg bg-card border border-border">
        <div class="border-b border-border px-6 py-4">
          <h2 class="text-lg font-semibold text-foreground">Step Flow</h2>
        </div>
        <div v-if="layers.length > 0" class="p-6">
          <div data-detail-flow class="relative">
            <!-- Zoom controls -->
            <div class="absolute top-1 right-1 z-10 flex items-center gap-0.5 rounded border border-border bg-card/80 backdrop-blur-sm px-1 py-0.5">
              <button @click="zoomFlow(-0.1)" class="px-1 text-xs text-muted-foreground hover:text-foreground leading-none">−</button>
              <span class="text-[10px] text-muted-foreground min-w-[32px] text-center">{{ zoomLabel }}</span>
              <button @click="zoomFlow(0.1)" class="px-1 text-xs text-muted-foreground hover:text-foreground leading-none">+</button>
              <button @click="autoFitFlow()" class="px-1 text-xs text-muted-foreground hover:text-foreground leading-none" title="Fit to width">⊞</button>
            </div>
            <div data-flow-clip class="overflow-hidden cursor-grab active:cursor-grabbing" @mousedown="onFlowPointerDown">
              <div data-flow-content class="flex items-center gap-1 py-2 w-fit" style="transform-origin: top left">
                <template v-for="(layer, layerIdx) in layers" :key="layerIdx">
                  <!-- Connector arrow between layers -->
                  <div v-if="layerIdx > 0" class="flex items-center justify-center self-center px-1">
                    <svg class="h-6 w-10 text-muted-foreground/40" viewBox="0 0 40 24" fill="none">
                      <path d="M0 12h32M28 6l8 6-8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </div>

                  <!-- Layer column (vertically stacked nodes, grouped by parallel_group) -->
                  <div class="flex flex-col gap-3">
                    <template v-for="(gItem, gIdx) in groupLayer(layer)" :key="gIdx">
                      <!-- Parallel group card -->
                      <div
                        v-if="gItem.group && gItem.steps.length > 1"
                        class="rounded-lg border border-indigo-300/40 dark:border-indigo-500/20 bg-indigo-50/30 dark:bg-indigo-500/5 px-2.5 pb-2.5"
                      >
                        <span class="text-[9px] font-medium text-indigo-600/70 dark:text-indigo-400/70 uppercase tracking-wider">{{ gItem.group }}</span>
                        <div class="flex flex-col gap-2 mt-1.5">
                          <div
                            v-for="step in gItem.steps"
                            :key="step.name"
                            :class="stepNodeClass(stepStatus(step.name))"
                            class="relative flex items-center gap-3 rounded-lg border px-4 py-3 min-w-[180px]"
                          >
                            <div :class="stepIconBgClass(stepStatus(step.name))" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full">
                              <svg v-if="stepStatus(step.name) === 'completed'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                              <svg v-else-if="stepStatus(step.name) === 'running'" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                              <svg v-else-if="stepStatus(step.name) === 'failed'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                              <svg v-else-if="stepStatus(step.name) === 'skipped'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd" /></svg>
                              <span v-else class="h-2 w-2 rounded-full bg-current"></span>
                            </div>
                            <div class="min-w-0">
                              <p class="text-sm font-medium truncate">{{ step.name }}</p>
                              <p class="text-xs mt-0.5" :class="stepStatusTextClass(stepStatus(step.name))">{{ stepStatus(step.name) }}</p>
                            </div>
                          </div>
                        </div>
                      </div>
                      <!-- Regular step(s) -->
                      <template v-else>
                        <div
                          v-for="step in gItem.steps"
                          :key="step.name"
                          :class="stepNodeClass(stepStatus(step.name))"
                          class="relative flex items-center gap-3 rounded-lg border px-4 py-3 min-w-[180px]"
                        >
                          <div :class="stepIconBgClass(stepStatus(step.name))" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full">
                            <svg v-if="stepStatus(step.name) === 'completed'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                            <svg v-else-if="stepStatus(step.name) === 'running'" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <svg v-else-if="stepStatus(step.name) === 'failed'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                            <svg v-else-if="stepStatus(step.name) === 'skipped'" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd" /></svg>
                            <span v-else class="h-2 w-2 rounded-full bg-current"></span>
                          </div>
                          <div class="min-w-0">
                            <p class="text-sm font-medium truncate">{{ step.name }}</p>
                            <p class="text-xs mt-0.5" :class="stepStatusTextClass(stepStatus(step.name))">{{ stepStatus(step.name) }}</p>
                          </div>
                        </div>
                      </template>
                    </template>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </div>

        <!-- Fallback flat list when no definition steps available -->
        <div v-else class="divide-y divide-border">
          <div
            v-for="(status, stepName) in instance.step_statuses"
            :key="stepName"
            class="flex items-center justify-between px-6 py-4"
          >
            <div class="flex items-center space-x-4">
              <div :class="stepIconBgClass(status)" class="flex h-8 w-8 items-center justify-center rounded-full">
                <svg v-if="status === 'completed'" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <svg v-else-if="status === 'running'" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else-if="status === 'failed'" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
                <svg v-else-if="status === 'skipped'" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd" />
                </svg>
                <span v-else class="h-2 w-2 rounded-full bg-current"></span>
              </div>
              <div>
                <h3 class="text-sm font-medium text-foreground">{{ stepName }}</h3>
              </div>
            </div>
            <span :class="stepBadgeClass(status)" class="rounded-full px-2 py-1 text-xs font-medium">
              {{ status }}
            </span>
          </div>
          <div v-if="Object.keys(instance.step_statuses || {}).length === 0" class="px-6 py-8 text-center text-muted-foreground">
            No steps executed yet
          </div>
        </div>
      </div>

      <!-- Error -->
      <div v-if="instance.error" class="rounded-lg bg-red-500/10 border border-red-500/50 p-6">
        <div class="flex items-start">
          <svg class="mr-3 h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
          </svg>
          <div>
            <h3 class="text-sm font-medium text-red-400">Error</h3>
            <p class="mt-1 text-sm text-red-300">{{ instance.error }}</p>
          </div>
        </div>
      </div>

      <!-- Context & Input -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-lg bg-card border border-border">
          <div class="border-b border-border px-6 py-4">
            <h2 class="text-lg font-semibold text-foreground">Input</h2>
          </div>
          <div class="p-6">
            <pre class="overflow-x-auto rounded-lg bg-secondary p-4 text-sm text-foreground">{{ JSON.stringify(instance.input, null, 2) }}</pre>
          </div>
        </div>
        <div class="rounded-lg bg-card border border-border">
          <div class="border-b border-border px-6 py-4">
            <h2 class="text-lg font-semibold text-foreground">Context</h2>
          </div>
          <div class="p-6">
            <pre class="overflow-x-auto rounded-lg bg-secondary p-4 text-sm text-foreground">{{ JSON.stringify(instance.context, null, 2) }}</pre>
          </div>
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Layout from './Layout.vue'

const props = defineProps({
  instance: {
    type: Object,
    required: true
  },
  definitionSteps: {
    type: Array,
    default: () => []
  }
})

/**
 * Compute topological layers from definition steps for the flow visualization.
 * Each layer contains steps that can execute in parallel (same depth in the DAG).
 * Virtual completion steps (parallel group gates) are excluded.
 */
const layers = computed(() => {
  const steps = props.definitionSteps
  if (!steps || steps.length === 0) return []

  // Build map of ALL steps (including virtual)
  const allStepMap = {}
  for (const s of steps) {
    allStepMap[s.name] = s
  }

  // Resolve dependencies through virtual steps to real steps
  const resolveRealDeps = (deps, visited = new Set()) => {
    const resolved = []
    for (const dep of deps) {
      if (visited.has(dep)) continue
      visited.add(dep)
      const depStep = allStepMap[dep]
      if (!depStep) continue
      if (depStep.virtual) {
        // Virtual step: resolve to its own dependencies
        resolved.push(...resolveRealDeps(depStep.dependencies || [], visited))
      } else {
        resolved.push(dep)
      }
    }
    return resolved
  }

  // Filter out virtual steps for display
  const realSteps = steps.filter(s => !s.virtual)
  if (realSteps.length === 0) return []

  const realStepMap = {}
  for (const s of realSteps) {
    realStepMap[s.name] = s
  }

  // Build resolved dependency map (real step → real deps only)
  const resolvedDeps = {}
  for (const s of realSteps) {
    resolvedDeps[s.name] = resolveRealDeps(s.dependencies || [])
  }

  // Compute depth for each step (longest path from root)
  const depths = {}
  const getDepth = (name, visited = new Set()) => {
    if (depths[name] !== undefined) return depths[name]
    if (visited.has(name)) return 0
    visited.add(name)

    const deps = resolvedDeps[name] || []
    if (deps.length === 0) {
      depths[name] = 0
      return 0
    }

    let maxDepth = 0
    for (const dep of deps) {
      const depDepth = getDepth(dep, visited)
      if (depDepth + 1 > maxDepth) maxDepth = depDepth + 1
    }
    depths[name] = maxDepth
    return maxDepth
  }

  for (const s of realSteps) {
    getDepth(s.name)
  }

  // Group steps by depth into layers
  const layerMap = {}
  for (const s of realSteps) {
    const d = depths[s.name] ?? 0
    if (!layerMap[d]) layerMap[d] = []
    layerMap[d].push(s)
  }

  const maxLayer = Math.max(...Object.keys(layerMap).map(Number))
  const result = []
  for (let i = 0; i <= maxLayer; i++) {
    if (layerMap[i]) result.push(layerMap[i])
  }

  return result
})

// Group a layer's steps by parallel_group
function groupLayer(layer) {
  const groups = []
  const seen = new Set()
  for (const step of layer) {
    const pg = step.parallel_group || null
    if (pg && !seen.has(pg)) {
      seen.add(pg)
      groups.push({ group: pg, steps: layer.filter(s => s.parallel_group === pg) })
    } else if (!pg) {
      groups.push({ group: null, steps: [step] })
    }
  }
  return groups
}

// Zoom & pan controls
const flowScale = ref(1)
const flowPan = ref({ x: 0, y: 0 })

function applyFlowTransform() {
  const wrapper = document.querySelector('[data-detail-flow]')
  if (!wrapper) return
  const content = wrapper.querySelector('[data-flow-content]')
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!content || !clip) return
  const s = flowScale.value
  const pan = flowPan.value
  content.style.transform = `translate(${pan.x}px,${pan.y}px) scale(${s})`
  clip.style.height = (content.offsetHeight * s) + 'px'
}

function autoFitFlow() {
  const wrapper = document.querySelector('[data-detail-flow]')
  if (!wrapper) return
  const content = wrapper.querySelector('[data-flow-content]')
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!content || !clip) return
  content.style.transform = 'scale(1)'
  clip.style.height = 'auto'
  const scale = Math.min(1, clip.clientWidth / content.scrollWidth)
  flowScale.value = scale
  flowPan.value = { x: 0, y: 0 }
  applyFlowTransform()
}

function zoomFlow(delta) {
  let scale = Math.round((flowScale.value + delta) * 10) / 10
  scale = Math.max(0.3, Math.min(1.5, scale))
  flowScale.value = scale
  applyFlowTransform()
}

const zoomLabel = computed(() => Math.round(flowScale.value * 100) + '%')

// Drag-to-pan
function onFlowPointerDown(e) {
  if (e.target.closest('button')) return
  const wrapper = document.querySelector('[data-detail-flow]')
  if (!wrapper) return
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!clip) return
  e.preventDefault()
  clip.style.cursor = 'grabbing'
  const startX = e.clientX, startY = e.clientY
  const origX = flowPan.value.x, origY = flowPan.value.y
  function onMove(ev) {
    flowPan.value = { x: origX + (ev.clientX - startX), y: origY + (ev.clientY - startY) }
    applyFlowTransform()
  }
  function onUp() {
    clip.style.cursor = ''
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
  }
  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

let _resizeTimer
function onResize() {
  clearTimeout(_resizeTimer)
  _resizeTimer = setTimeout(() => { if (layers.value.length > 0) autoFitFlow() }, 150)
}
onMounted(() => {
  window.addEventListener('resize', onResize)
  if (layers.value.length > 0) nextTick(() => autoFitFlow())
})
onUnmounted(() => window.removeEventListener('resize', onResize))

function stepStatus(name) {
  return props.instance.step_statuses?.[name] || 'pending'
}

function statusClass(status) {
  const classes = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    paused: 'bg-purple-100 text-purple-800 dark:bg-purple-500/10 dark:text-purple-400',
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-500/10 dark:text-gray-400',
  }
  return classes[status] || classes.pending
}

function progressClass(status) {
  const classes = {
    running: 'bg-blue-500',
    completed: 'bg-emerald-500',
    failed: 'bg-red-500',
    paused: 'bg-amber-500'
  }
  return classes[status] || 'bg-muted-foreground'
}

function stepNodeClass(status) {
  const classes = {
    completed: 'border-emerald-500/30 bg-emerald-500/5',
    queued: 'border-amber-500/30 bg-amber-500/5',
    running: 'border-blue-500/30 bg-blue-500/5',
    failed: 'border-red-500/30 bg-red-500/5',
    skipped: 'border-border bg-secondary/50',
    pending: 'border-border bg-card',
  }
  return classes[status] || classes.pending
}

function stepIconBgClass(status) {
  const classes = {
    completed: 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400',
    queued: 'bg-amber-500/20 text-amber-600 dark:text-amber-400',
    running: 'bg-blue-500/20 text-blue-400',
    failed: 'bg-red-500/20 text-red-400',
    skipped: 'bg-secondary text-muted-foreground',
    pending: 'bg-secondary text-muted-foreground'
  }
  return classes[status] || classes.pending
}

function stepStatusTextClass(status) {
  const classes = {
    completed: 'text-emerald-600 dark:text-emerald-400',
    queued: 'text-amber-600 dark:text-amber-400',
    running: 'text-blue-400',
    failed: 'text-red-400',
    skipped: 'text-muted-foreground',
    pending: 'text-muted-foreground',
  }
  return classes[status] || 'text-muted-foreground'
}

function stepBadgeClass(status) {
  const classes = {
    completed: 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-400',
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    failed: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
    skipped: 'bg-gray-100 text-gray-800 dark:bg-gray-500/10 dark:text-gray-400',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
  }
  return classes[status] || classes.pending
}

function formatDate(date) {
  if (!date) return '-'
  return new Date(date).toLocaleString()
}

function formatDuration(seconds) {
  if (!seconds) return '-'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`
}

function pause() {
  router.post(route('station.api.workflows.pause', props.instance.id))
}

function resume() {
  router.post(route('station.api.workflows.resume', props.instance.id))
}

function cancel() {
  if (confirm('Are you sure you want to cancel this workflow?')) {
    router.post(route('station.api.workflows.cancel', props.instance.id))
  }
}
</script>
