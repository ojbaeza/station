<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import StationLayout from './Layout.vue'

const props = defineProps({
    rules: Array,
    alertTypes: Object,
    channels: Array,
})

const showCreateModal = ref(false)
const editingRule = ref(null)
const form = ref({
    name: '',
    type: 'high_failure_rate',
    condition: {},
    channel_ids: [],
    window: 300,
    cooldown: 300,
    enabled: true,
})

const resetForm = () => {
    form.value = {
        name: '',
        type: 'high_failure_rate',
        condition: {},
        channel_ids: [],
        window: 300,
        cooldown: 300,
        enabled: true,
    }
    editingRule.value = null
}

const openCreate = () => {
    resetForm()
    showCreateModal.value = true
}

const openEdit = (rule) => {
    editingRule.value = rule.id
    form.value = {
        name: rule.name,
        type: rule.type,
        condition: rule.condition || {},
        channel_ids: rule.channel_ids || [],
        window: rule.window,
        cooldown: rule.cooldown,
        enabled: rule.enabled,
    }
    showCreateModal.value = true
}

const submitForm = () => {
    if (editingRule.value) {
        fetch(route('station.api.alerts.rules.update', editingRule.value), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify(form.value),
        }).then((response) => {
            if (response.ok) {
                showCreateModal.value = false
                router.reload()
            }
        })
    } else {
        fetch(route('station.api.alerts.rules.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
            body: JSON.stringify(form.value),
        }).then((response) => {
            if (response.ok) {
                showCreateModal.value = false
                router.reload()
            }
        })
    }
}

const toggleRule = (id) => {
    fetch(route('station.api.alerts.rules.toggle', id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    }).then((response) => {
        if (response.ok) router.reload({ preserveScroll: true })
    })
}

const testingRule = ref(null)
const testResult = ref(null)
let testResultTimer = null

const showTestResult = (type, message) => {
    testResult.value = { type, message }
    clearTimeout(testResultTimer)
    testResultTimer = setTimeout(() => { testResult.value = null }, 5000)
}

const testRule = (id) => {
    testingRule.value = id
    testResult.value = null
    const rule = (props.rules || []).find(r => r.id === id)
    fetch(route('station.api.alerts.rules.test', id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    }).then(async (response) => {
        testingRule.value = null
        if (response.ok) {
            showTestResult('success', `Test alert fired for rule: ${rule?.name || 'rule'}. Check your configured channels.`)
        } else {
            let msg = 'Test failed. Check channel configuration.'
            try { const data = await response.json(); msg = data.error || data.message || msg } catch {}
            showTestResult('error', msg)
        }
    }).catch(() => {
        testingRule.value = null
        showTestResult('error', 'Test failed. Could not reach the server.')
    })
}

const deleteRule = (id) => {
    if (!confirm('Delete this alert rule?')) return
    fetch(route('station.api.alerts.rules.destroy', id), {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': getCsrfToken() },
    }).then(() => router.reload())
}

const toggleChannel = (channelId) => {
    const idx = form.value.channel_ids.indexOf(channelId)
    if (idx > -1) {
        form.value.channel_ids.splice(idx, 1)
    } else {
        form.value.channel_ids.push(channelId)
    }
}

const channelById = (id) => {
    return (props.channels || []).find(ch => ch.id === id)
}

const channelNames = (ids) => {
    return (ids || []).map(id => {
        const ch = channelById(id)
        return ch ? ch.name : id
    })
}

const getCsrfToken = () => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return match ? decodeURIComponent(match[1]) : ''
}

const severityColor = (type) => {
    const colors = {
        high_failure_rate: 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-500/10',
        queue_backup: 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-500/10',
        stuck_jobs: 'text-orange-600 bg-orange-50 dark:text-orange-400 dark:bg-orange-500/10',
        worker_down: 'text-rose-600 bg-rose-50 dark:text-rose-400 dark:bg-rose-500/10',
        custom: 'text-blue-600 bg-blue-50 dark:text-blue-400 dark:bg-blue-500/10',
    }
    return colors[type] || colors.custom
}

const typeBadgeColor = (type) => {
    const colors = {
        slack: 'text-purple-600 bg-purple-50 dark:text-purple-400 dark:bg-purple-500/10',
        discord: 'text-indigo-600 bg-indigo-50 dark:text-indigo-400 dark:bg-indigo-500/10',
        teams: 'text-blue-600 bg-blue-50 dark:text-blue-400 dark:bg-blue-500/10',
        google_chat: 'text-green-600 bg-green-50 dark:text-green-400 dark:bg-green-500/10',
        webhook: 'text-orange-600 bg-orange-50 dark:text-orange-400 dark:bg-orange-500/10',
        email: 'text-rose-600 bg-rose-50 dark:text-rose-400 dark:bg-rose-500/10',
        log: 'text-zinc-600 bg-zinc-50 dark:text-zinc-400 dark:bg-zinc-500/10',
    }
    return colors[type] || ''
}
</script>

<template>
    <Head title="Alerts - Station" />
    <StationLayout>
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-foreground">Alert Rules</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Configure alert rules for queue monitoring.</p>
                </div>
                <button
                    @click="openCreate"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors"
                >
                    Create Rule
                </button>
            </div>

            <!-- Test result toast -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-1"
            >
                <div v-if="testResult" :class="[
                    'flex items-center justify-between rounded-lg border px-4 py-3 text-sm',
                    testResult.type === 'success'
                        ? 'bg-emerald-50 dark:bg-emerald-500/10 border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-400'
                        : 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400',
                ]">
                    <span>{{ testResult.message }}</span>
                    <button @click="testResult = null" class="ml-4 opacity-60 hover:opacity-100">&times;</button>
                </div>
            </Transition>

            <!-- Rules table -->
            <div class="bg-card rounded-xl border border-border shadow-xs overflow-hidden">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Channels</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Cooldown</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="rule in rules" :key="rule.id" class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-foreground">{{ rule.name }}</span>
                                <span v-if="rule.source === 'config'" class="ml-2 text-xs text-muted-foreground">(config)</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="[severityColor(rule.type), 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium']">
                                    {{ alertTypes[rule.type] || rule.type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="name in channelNames(rule.channel_ids)" :key="name" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-muted text-muted-foreground">
                                        {{ name }}
                                    </span>
                                    <span v-if="!rule.channel_ids || rule.channel_ids.length === 0" class="text-xs text-muted-foreground">None</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">
                                {{ Math.floor(rule.cooldown / 60) }}m
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="toggleRule(rule.id)" :class="[rule.enabled ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600', 'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200']">
                                    <span :class="[rule.enabled ? 'translate-x-5' : 'translate-x-0', 'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 mt-0.5 ml-0.5']"></span>
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <button @click="testRule(rule.id)" :disabled="testingRule === rule.id" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 disabled:opacity-50">{{ testingRule === rule.id ? 'Testing...' : 'Test' }}</button>
                                <button @click="openEdit(rule)" class="text-muted-foreground hover:text-foreground">Edit</button>
                                <button @click="deleteRule(rule.id)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Delete</button>
                            </td>
                        </tr>
                        <tr v-if="!rules || rules.length === 0">
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-muted-foreground">
                                No alert rules configured. Create one or run <code class="px-1.5 py-0.5 bg-muted rounded text-xs">station:alerts:check --seed</code> to seed defaults.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showCreateModal = false"></div>
                    <div class="relative bg-card rounded-xl shadow-2xl max-w-lg w-full border border-border">
                        <!-- Header -->
                        <div class="border-b border-border px-6 py-4">
                            <h3 class="text-lg font-semibold text-foreground">{{ editingRule ? 'Edit Rule' : 'Create Alert Rule' }}</h3>
                            <p class="mt-0.5 text-sm text-muted-foreground">{{ editingRule ? 'Modify the alert rule configuration.' : 'Define a new monitoring alert for your queues.' }}</p>
                        </div>

                        <!-- Body -->
                        <form @submit.prevent="submitForm" class="px-6 py-5 space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1.5">Name</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="e.g. High failure rate alert"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground/50 focus:outline-hidden focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1.5">Type</label>
                                <select
                                    v-model="form.type"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-hidden focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors appearance-none bg-[length:16px_16px] bg-[right_12px_center] bg-no-repeat"
                                    style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e&quot;)"
                                >
                                    <option v-for="(label, value) in alertTypes" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-2">Channels</label>
                                <div v-if="channels && channels.length > 0" class="flex flex-wrap gap-3">
                                    <button
                                        v-for="channel in channels"
                                        :key="channel.id"
                                        type="button"
                                        @click="toggleChannel(channel.id)"
                                        :class="[
                                            'inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border text-sm font-medium transition-colors',
                                            form.channel_ids.includes(channel.id)
                                                ? 'border-indigo-500 bg-indigo-500/10 text-indigo-400'
                                                : 'border-border bg-background text-muted-foreground hover:border-muted-foreground/50 hover:text-foreground',
                                        ]"
                                    >
                                        <svg v-if="form.channel_ids.includes(channel.id)" class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                                        <span v-else class="h-3.5 w-3.5 rounded border border-current opacity-40"></span>
                                        {{ channel.name }}
                                        <span :class="[typeBadgeColor(channel.type), 'text-[10px] px-1.5 py-0.5 rounded-full']">{{ channel.type }}</span>
                                    </button>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">No channels configured. <a :href="route('station.alerts.channels')" class="text-indigo-500 hover:text-indigo-400">Create one first.</a></p>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-foreground mb-1.5">Window (seconds)</label>
                                    <input
                                        v-model.number="form.window"
                                        type="number"
                                        min="60"
                                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-hidden focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-foreground mb-1.5">Cooldown (seconds)</label>
                                    <input
                                        v-model.number="form.cooldown"
                                        type="number"
                                        min="60"
                                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-hidden focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors"
                                    />
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex justify-end gap-3 pt-3 border-t border-border -mx-6 px-6 mt-6">
                                <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground rounded-lg hover:bg-muted transition-colors">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">{{ editingRule ? 'Update' : 'Create' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </StationLayout>
</template>
