<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Layout from './Layout.vue'

const props = defineProps({
  definitions: { type: Array, default: () => [] },
})

// Pagination
const currentPage = ref(1)
const perPage = 10

const totalPages = computed(() => Math.ceil(props.definitions.length / perPage))

const paginatedDefs = computed(() => {
  const start = (currentPage.value - 1) * perPage
  return props.definitions.slice(start, start + perPage)
})

function goToPage(page) {
  if (page >= 1 && page <= totalPages.value) currentPage.value = page
}

// Expand/collapse
const expandedDef = ref(null)

function toggleExpand(defName) {
  expandedDef.value = expandedDef.value === defName ? null : defName
  if (expandedDef.value) nextTick(() => autoFitDef(defName))
}

// Zoom & pan controls
const defScales = ref({})
const defPans = ref({})

function applyDefTransform(defName, wrapper) {
  const content = wrapper.querySelector('[data-flow-content]')
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!content || !clip) return
  const s = defScales.value[defName] || 1
  const pan = defPans.value[defName] || { x: 0, y: 0 }
  content.style.transform = `translate(${pan.x}px,${pan.y}px) scale(${s})`
  clip.style.height = (content.offsetHeight * s) + 'px'
}

function autoFitDef(defName) {
  const wrapper = document.querySelector(`[data-def-flow="${defName}"]`)
  if (!wrapper) return
  const content = wrapper.querySelector('[data-flow-content]')
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!content || !clip) return
  content.style.transform = 'scale(1)'
  clip.style.height = 'auto'
  const scale = Math.min(1, clip.clientWidth / content.scrollWidth)
  defScales.value[defName] = scale
  defPans.value[defName] = { x: 0, y: 0 }
  applyDefTransform(defName, wrapper)
}

function zoomDef(defName, delta) {
  const wrapper = document.querySelector(`[data-def-flow="${defName}"]`)
  if (!wrapper) return
  let scale = Math.round(((defScales.value[defName] || 1) + delta) * 10) / 10
  scale = Math.max(0.3, Math.min(1.5, scale))
  defScales.value[defName] = scale
  applyDefTransform(defName, wrapper)
}

function fitDef(defName) { autoFitDef(defName) }

function defZoomLabel(defName) {
  return Math.round((defScales.value[defName] || 1) * 100) + '%'
}

// Drag-to-pan
function onFlowPointerDown(e, defName) {
  if (e.target.closest('button')) return
  const wrapper = document.querySelector(`[data-def-flow="${defName}"]`)
  if (!wrapper) return
  const clip = wrapper.querySelector('[data-flow-clip]')
  if (!clip) return
  e.preventDefault()
  clip.style.cursor = 'grabbing'
  const startX = e.clientX, startY = e.clientY
  const pan = defPans.value[defName] || { x: 0, y: 0 }
  const origX = pan.x, origY = pan.y
  function onMove(ev) {
    defPans.value[defName] = { x: origX + (ev.clientX - startX), y: origY + (ev.clientY - startY) }
    applyDefTransform(defName, wrapper)
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
  _resizeTimer = setTimeout(() => { if (expandedDef.value) autoFitDef(expandedDef.value) }, 150)
}
onMounted(() => window.addEventListener('resize', onResize))
onUnmounted(() => window.removeEventListener('resize', onResize))

// Compute layers (DAG topological sort)
function computeDefLayers(def) {
  const steps = def.steps
  if (!steps || typeof steps !== 'object') return []
  const entries = Object.entries(steps)
  if (entries.length === 0) return []

  const allStepMap = {}
  for (const [name, step] of entries) {
    allStepMap[name] = { name, ...step }
  }

  function resolveRealDeps(deps, visited = new Set()) {
    const resolved = []
    for (const dep of (deps || [])) {
      if (visited.has(dep)) continue
      visited.add(dep)
      const depStep = allStepMap[dep]
      if (!depStep) continue
      if (depStep.virtual) {
        resolved.push(...resolveRealDeps(depStep.dependencies || [], visited))
      } else {
        resolved.push(dep)
      }
    }
    return resolved
  }

  const realSteps = entries.filter(([, s]) => !s.virtual).map(([name, s]) => ({ name, ...s }))
  if (realSteps.length === 0) return []

  const resolvedDeps = {}
  for (const s of realSteps) {
    resolvedDeps[s.name] = resolveRealDeps(s.dependencies || [])
  }

  const depths = {}
  function getDepth(name, visited = new Set()) {
    if (depths[name] !== undefined) return depths[name]
    if (visited.has(name)) return 0
    visited.add(name)
    const deps = resolvedDeps[name] || []
    if (deps.length === 0) { depths[name] = 0; return 0 }
    let maxD = 0
    for (const dep of deps) {
      const d = getDepth(dep, new Set(visited))
      if (d + 1 > maxD) maxD = d + 1
    }
    depths[name] = maxD
    return maxD
  }
  for (const s of realSteps) getDepth(s.name)

  const layerMap = {}
  for (const s of realSteps) {
    const d = depths[s.name] || 0
    if (!layerMap[d]) layerMap[d] = []
    layerMap[d].push(s)
  }
  const maxLayer = Math.max(...Object.keys(layerMap).map(Number))
  const result = []
  for (let i = 0; i <= maxLayer; i++) {
    if (layerMap[i]) result.push(layerMap[i])
  }
  return result
}

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

// Run workflow
const runLoading = ref(null)
const runError = ref('')
const runSuccess = ref('')

async function runWorkflow(defName) {
  runLoading.value = defName
  runError.value = ''
  runSuccess.value = ''

  try {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content
    const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    if (meta) headers['X-CSRF-TOKEN'] = meta
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    if (match) headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1])

    const res = await fetch(route('station.api.workflows.run'), {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify({ definition: defName }),
    })
    const data = await res.json()
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`)
    runSuccess.value = data.id
    setTimeout(() => { runSuccess.value = '' }, 8000)
  } catch (e) {
    runError.value = e.message || 'Failed to run workflow'
    setTimeout(() => { runError.value = '' }, 8000)
  } finally {
    runLoading.value = null
  }
}
</script>

<template>
  <Head title="Workflow Definitions - Station" />

  <Layout>
    <div class="space-y-6">
      <!-- Header -->
      <div>
        <div class="flex items-center gap-2 text-sm text-muted-foreground mb-1">
          <Link :href="route('station.workflows')" class="hover:text-foreground transition-colors">Workflows</Link>
          <span>/</span>
          <span class="text-foreground">Definitions</span>
        </div>
        <h1 class="text-2xl font-semibold text-foreground">Workflow Definitions</h1>
        <p class="mt-1 text-sm text-muted-foreground">
          Registered workflow definitions and their step configurations
        </p>
      </div>

      <!-- Toast messages -->
      <div v-if="runSuccess" class="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 rounded-lg p-4">
        <p class="text-sm text-emerald-700 dark:text-emerald-400 flex items-center gap-2">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          Dispatched:
          <Link :href="route('station.workflows.show', runSuccess)" class="text-indigo-600 dark:text-indigo-400 hover:underline font-mono text-xs">{{ runSuccess }}</Link>
        </p>
      </div>
      <div v-if="runError" class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-lg p-4">
        <p class="text-sm text-red-700 dark:text-red-400 flex items-center gap-2">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ runError }}
        </p>
      </div>

      <!-- Definitions List -->
      <div class="space-y-3">
        <div
          v-for="def in paginatedDefs"
          :key="def.name"
          class="rounded-lg border border-border bg-card"
        >
          <!-- Collapsed row -->
          <div class="flex items-center gap-3 px-5 py-4">
            <button
              @click="toggleExpand(def.name)"
              class="flex-shrink-0 p-0.5 rounded hover:bg-secondary transition-colors"
            >
              <svg v-if="expandedDef === def.name" class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
              <svg v-else class="h-4 w-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </button>

            <div class="flex items-center gap-2 flex-1 min-w-0">
              <h3 class="text-sm font-semibold text-foreground truncate">{{ def.name }}</h3>
              <span
                :class="def.source === 'database'
                  ? 'bg-purple-100 text-purple-800 dark:bg-purple-500/10 dark:text-purple-400'
                  : 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400'"
                class="px-1.5 py-0.5 text-[10px] font-medium rounded uppercase flex-shrink-0"
              >
                {{ def.source === 'database' ? 'Custom' : 'Code' }}
              </span>
              <span class="text-xs text-muted-foreground flex-shrink-0">{{ Object.keys(def.steps || {}).length }} steps</span>
              <span v-if="def.timeout" class="text-xs text-muted-foreground flex-shrink-0">Timeout: {{ def.timeout }}s</span>
            </div>

            <button
              @click="runWorkflow(def.name)"
              :disabled="runLoading === def.name"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors flex-shrink-0"
            >
              <svg v-if="runLoading !== def.name" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
              </svg>
              <svg v-else class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
              Run
            </button>
          </div>

          <!-- Expanded step flow -->
          <div
            v-if="expandedDef === def.name && Object.keys(def.steps || {}).length > 0"
            class="border-t border-border px-5 py-4"
          >
            <div v-if="computeDefLayers(def).length > 0" :data-def-flow="def.name" class="relative">
              <div class="absolute top-1 right-1 z-10 flex items-center gap-0.5 rounded border border-border bg-card/80 backdrop-blur-sm px-1 py-0.5">
                <button type="button" @click="zoomDef(def.name, -0.1)" class="p-0.5 text-muted-foreground hover:text-foreground transition-colors" title="Zoom out"><svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4" /></svg></button>
                <span class="text-[10px] text-muted-foreground tabular-nums w-8 text-center select-none">{{ defZoomLabel(def.name) }}</span>
                <button type="button" @click="zoomDef(def.name, 0.1)" class="p-0.5 text-muted-foreground hover:text-foreground transition-colors" title="Zoom in"><svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg></button>
                <button type="button" @click="fitDef(def.name)" class="p-0.5 text-muted-foreground hover:text-foreground transition-colors ml-0.5" title="Fit to view"><svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4m8 0h4v4m0 8v4h-4m-8 0H4v-4" /></svg></button>
              </div>
              <div data-flow-clip class="overflow-hidden cursor-grab active:cursor-grabbing" @mousedown="onFlowPointerDown($event, def.name)">
                <div data-flow-content class="flex items-center gap-2 py-2 w-fit" style="transform-origin: top left">
                  <template v-for="(layer, layerIdx) in computeDefLayers(def)" :key="layerIdx">
                    <div v-if="layerIdx > 0" class="flex items-center justify-center self-center px-1">
                      <svg class="h-5 w-8 text-muted-foreground/40" viewBox="0 0 32 20" fill="none">
                        <path d="M0 10h24M20 4l8 6-8 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                      </svg>
                    </div>
                    <div class="flex flex-col gap-2">
                      <template v-for="(gItem, gIdx) in groupLayer(layer)" :key="gIdx">
                        <div
                          v-if="gItem.group && gItem.steps.length > 1"
                          class="rounded-lg border border-indigo-300/40 dark:border-indigo-500/20 bg-indigo-50/30 dark:bg-indigo-500/5 px-2 pb-2"
                        >
                          <span class="text-[9px] font-medium text-indigo-600/70 dark:text-indigo-400/70 uppercase tracking-wider">{{ gItem.group }}</span>
                          <div class="flex flex-col gap-1 mt-1">
                            <div
                              v-for="step in gItem.steps"
                              :key="step.name"
                              class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 min-w-[140px]"
                            >
                              <span class="h-1.5 w-1.5 rounded-full bg-indigo-500/60 flex-shrink-0"></span>
                              <p class="text-xs font-medium text-foreground truncate">{{ step.name }}</p>
                            </div>
                          </div>
                        </div>
                        <template v-else>
                          <div
                            v-for="step in gItem.steps"
                            :key="step.name"
                            class="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 min-w-[140px]"
                          >
                            <span class="h-1.5 w-1.5 rounded-full bg-indigo-500/60 flex-shrink-0"></span>
                            <p class="text-xs font-medium text-foreground truncate">{{ step.name }}</p>
                          </div>
                        </template>
                      </template>
                    </div>
                  </template>
                </div>
              </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">No displayable steps</p>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-between">
        <span class="text-xs text-muted-foreground">{{ definitions.length }} total</span>
        <div class="flex items-center gap-1">
          <button
            @click="goToPage(currentPage - 1)"
            :disabled="currentPage <= 1"
            class="inline-flex items-center justify-center h-8 min-w-[2rem] px-2 text-xs font-medium rounded-lg border transition-colors"
            :class="currentPage <= 1 ? 'border-border text-muted-foreground/40 cursor-not-allowed' : 'border-border text-muted-foreground hover:bg-secondary hover:text-foreground'"
          >&laquo;</button>
          <template v-for="page in totalPages" :key="page">
            <button
              @click="goToPage(page)"
              class="inline-flex items-center justify-center h-8 min-w-[2rem] px-2 text-xs font-medium rounded-lg border transition-colors"
              :class="page === currentPage ? 'border-emerald-500 bg-emerald-500/10 text-emerald-400' : 'border-border text-muted-foreground hover:bg-secondary hover:text-foreground'"
            >{{ page }}</button>
          </template>
          <button
            @click="goToPage(currentPage + 1)"
            :disabled="currentPage >= totalPages"
            class="inline-flex items-center justify-center h-8 min-w-[2rem] px-2 text-xs font-medium rounded-lg border transition-colors"
            :class="currentPage >= totalPages ? 'border-border text-muted-foreground/40 cursor-not-allowed' : 'border-border text-muted-foreground hover:bg-secondary hover:text-foreground'"
          >&raquo;</button>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="definitions.length === 0" class="rounded-lg border border-border bg-card p-8 text-center">
        <svg class="mx-auto h-12 w-12 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-foreground">No workflow definitions</h3>
        <p class="mt-1 text-sm text-muted-foreground">Register definitions in your AppServiceProvider to see them here.</p>
      </div>
    </div>
  </Layout>
</template>
