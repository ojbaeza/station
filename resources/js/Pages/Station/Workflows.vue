<script setup>
import { ref, computed, inject, onUnmounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Layout from './Layout.vue'
import Pagination from './Components/Pagination.vue'
import StatCard from './Components/StatCard.vue'
import { Checkbox } from '@/Components/ui/checkbox'
import { Button } from '@/Components/ui/button'
import { useBulkSelection } from '@/composables/useBulkSelection'

const { selectedIds, toggleId, toggleAll, clearSelection, hasSelection, selectedArray } = useBulkSelection()

const props = defineProps({
  instances: {
    type: Object,
    default: () => ({ data: [], total: 0, page: 1, per_page: 25, last_page: 1, from: null, to: null, links: [] })
  },
  stats: {
    type: Object,
    default: () => ({
      pending: 0,
      running: 0,
      paused: 0,
      completed: 0,
      failed: 0,
      cancelled: 0,
    })
  },
  connections: {
    type: Array,
    default: () => []
  },
  filters: {
    type: Object,
    default: () => ({})
  }
})

const instanceData = computed(() => props.instances?.data || [])

const isInstanceSelectable = (instance) => instance.status !== 'completed' && instance.status !== 'cancelled'

const selectableInstances = computed(() => instanceData.value.filter(isInstanceSelectable))

const allInstancesSelected = computed(() => {
  if (!selectableInstances.value.length) return false
  return selectableInstances.value.every((i) => selectedIds.value.has(i.id))
})

const selectedWorkflows = computed(() => instanceData.value.filter(i => selectedIds.value.has(i.id)))

const canPause = computed(() => selectedWorkflows.value.length > 0 && selectedWorkflows.value.every(i => i.status === 'running'))
const canResume = computed(() => selectedWorkflows.value.length > 0 && selectedWorkflows.value.every(i => i.status === 'paused'))
const canCancel = computed(() => selectedWorkflows.value.length > 0 && selectedWorkflows.value.every(i => i.status === 'running' || i.status === 'paused'))

function bulkAction(routeName, message) {
  if (!confirm(message)) return
  router.post(route(routeName), { ids: selectedArray.value }, {
    preserveScroll: true,
    onSuccess: () => clearSelection(),
  })
}

// Filters (server-side)
const selectedStatus = ref(props.filters?.status || '')
const selectedDefinition = ref(props.filters?.definition || '')
const selectedConnection = ref(props.filters?.connection || '')

const applyFilters = () => {
  router.get(route('station.workflows'), {
    status: selectedStatus.value || undefined,
    definition: selectedDefinition.value || undefined,
    connection: selectedConnection.value || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
  })
}

watch([selectedStatus, selectedDefinition, selectedConnection], applyFilters)

// Auto-refresh via inject
const autoRefresh = inject('autoRefresh', { enabled: ref(true), interval: ref(5000) })
const pollTimer = ref(null)

function refresh() {
  router.reload({ only: ['instances', 'stats'] })
}

const stopPolling = () => { clearInterval(pollTimer.value); pollTimer.value = null }

const effectiveInterval = computed(() => {
  const base = autoRefresh.interval.value
  return autoRefresh.focused?.value === false ? base * 6 : base
})

watch([() => autoRefresh.enabled.value, effectiveInterval], ([enabled, ms]) => {
  stopPolling()
  if (enabled) { pollTimer.value = setInterval(refresh, ms) }
}, { immediate: true })

watch(() => autoRefresh.focused?.value, (focused) => {
  if (focused && autoRefresh.enabled.value) refresh()
})

onUnmounted(stopPolling)

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

function pauseWorkflow(id) {
  if (!confirm('Pause this workflow?')) return
  router.post(route('station.api.workflows.pause', id))
}

function resumeWorkflow(id) {
  if (!confirm('Resume this workflow?')) return
  router.post(route('station.api.workflows.resume', id))
}

function cancelWorkflow(id) {
  if (confirm('Are you sure you want to cancel this workflow?')) {
    router.post(route('station.api.workflows.cancel', id))
  }
}
</script>

<template>
  <Layout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-foreground">Workflows</h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Monitor running workflow instances
          </p>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Pending"
          :value="stats.pending"
          icon="clock"
          color="yellow"
        />
        <StatCard
          title="Running"
          :value="stats.running"
          icon="play"
          color="blue"
        />
        <StatCard
          title="Completed"
          :value="stats.completed"
          icon="check"
          color="emerald"
        />
        <StatCard
          title="Failed"
          :value="stats.failed"
          icon="x"
          color="red"
        />
      </div>

      <!-- Filters -->
      <div class="bg-card border border-border rounded-lg p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-muted-foreground">Status</label>
            <select
              v-model="selectedStatus"
              class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
            >
              <option value="">All Statuses</option>
              <option value="pending">Pending</option>
              <option value="running">Running</option>
              <option value="completed">Completed</option>
              <option value="failed">Failed</option>
              <option value="paused">Paused</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-muted-foreground">Definition</label>
            <input
              v-model="selectedDefinition"
              type="text"
              placeholder="Filter by definition name..."
              class="mt-1 block w-full pl-3 pr-3 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
            />
          </div>
          <div v-if="connections.length > 0">
            <label class="block text-sm font-medium text-muted-foreground">Connection</label>
            <select
              v-model="selectedConnection"
              class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-border bg-secondary text-foreground focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
            >
              <option value="">All Connections</option>
              <option v-for="conn in connections" :key="conn" :value="conn">{{ conn }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Bulk Actions -->
      <div v-if="hasSelection" class="bg-card border border-border rounded-lg p-4 flex items-center gap-3">
        <span class="text-sm text-muted-foreground">{{ selectedIds.size }} selected</span>
        <Button v-if="canPause" variant="outline" size="sm" @click="bulkAction('station.api.workflows.bulk.pause', `Pause ${selectedIds.size} selected workflows?`)">Pause Selected</Button>
        <Button v-if="canResume" variant="outline" size="sm" @click="bulkAction('station.api.workflows.bulk.resume', `Resume ${selectedIds.size} selected workflows?`)">Resume Selected</Button>
        <Button v-if="canCancel" variant="outline" size="sm" @click="bulkAction('station.api.workflows.bulk.cancel', `Cancel ${selectedIds.size} selected workflows?`)">Cancel Selected</Button>
        <Button variant="ghost" size="sm" @click="clearSelection">Clear</Button>
      </div>

      <div class="rounded-lg bg-card border border-border">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border">
            <thead class="bg-secondary">
              <tr>
                <th class="px-6 py-3 w-10">
                  <Checkbox
                    :model-value="allInstancesSelected"
                    @update:model-value="toggleAll(selectableInstances.map(i => i.id))"
                  />
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Workflow
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Status
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Connection
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Progress
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Started
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Duration
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody class="bg-card divide-y divide-border">
              <tr v-for="instance in instanceData" :key="instance.id" class="hover:bg-secondary/50 transition-colors">
                <td class="px-6 py-4 w-10">
                  <Checkbox
                    v-if="isInstanceSelectable(instance)"
                    :model-value="selectedIds.has(instance.id)"
                    @update:model-value="toggleId(instance.id)"
                  />
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap">
                  <div class="text-sm font-medium text-foreground">
                    {{ instance.definition_name }}
                  </div>
                  <Link
                    :href="route('station.workflows.show', instance.id)"
                    class="text-xs text-muted-foreground font-mono hover:text-indigo-600 dark:hover:text-indigo-400"
                  >
                    {{ instance.id }}
                  </Link>
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap">
                  <span :class="statusClass(instance.status)" class="px-2 py-0.5 inline-flex text-xs font-medium rounded">
                    {{ instance.status }}
                  </span>
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                  {{ instance.connection || '-' }}
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap">
                  <div class="flex items-center">
                    <div class="w-full bg-secondary rounded-full h-2.5 mr-2">
                      <div
                        :style="{ width: instance.progress + '%' }"
                        :class="progressClass(instance.status)"
                        class="h-2.5 rounded-full"
                      ></div>
                    </div>
                    <span class="text-sm text-muted-foreground">{{ instance.progress }}%</span>
                  </div>
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                  {{ formatDate(instance.started_at) }}
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap text-sm text-muted-foreground">
                  {{ formatDuration(instance.duration) }}
                </td>
                <td class="px-6 py-3.5 whitespace-nowrap text-sm font-medium space-x-2">
                  <Link
                    :href="route('station.workflows.show', instance.id)"
                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                  >
                    View
                  </Link>
                  <button
                    v-if="instance.status === 'running'"
                    @click="pauseWorkflow(instance.id)"
                    class="text-amber-600 hover:text-amber-500 dark:text-amber-400 dark:hover:text-amber-300"
                  >
                    Pause
                  </button>
                  <button
                    v-if="instance.status === 'paused'"
                    @click="resumeWorkflow(instance.id)"
                    class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                  >
                    Resume
                  </button>
                  <button
                    v-if="['running', 'paused'].includes(instance.status)"
                    @click="cancelWorkflow(instance.id)"
                    class="text-red-600 hover:text-red-500 dark:text-red-400 dark:hover:text-red-300"
                  >
                    Cancel
                  </button>
                </td>
              </tr>
              <tr v-if="instanceData.length === 0">
                <td colspan="8" class="px-6 py-8 text-center text-sm text-muted-foreground">
                  No workflow instances found
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination :data="instances" />
      </div>
    </div>
  </Layout>
</template>
