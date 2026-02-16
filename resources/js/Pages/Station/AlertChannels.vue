<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import StationLayout from './Layout.vue'

const props = defineProps({
    channels: Array,
    channelTypes: Object,
})

const showModal = ref(false)
const editingChannel = ref(null)
const formErrors = ref({})
const form = ref({
    name: '',
    type: 'slack',
    enabled: true,
    config: {},
})

const resetForm = () => {
    form.value = { name: '', type: 'slack', enabled: true, config: {} }
    editingChannel.value = null
    formErrors.value = {}
}

const openCreate = () => {
    resetForm()
    showModal.value = true
}

const openEdit = (channel) => {
    editingChannel.value = channel.id
    form.value = {
        name: channel.name,
        type: channel.type,
        enabled: channel.enabled,
        config: { ...channel.config },
    }
    showModal.value = true
}

const configFields = {
    slack: [{ key: 'webhook_url', label: 'Webhook URL', type: 'text', placeholder: 'https://hooks.slack.com/services/...', required: true }],
    discord: [{ key: 'webhook_url', label: 'Webhook URL', type: 'text', placeholder: 'https://discord.com/api/webhooks/...', required: true }],
    teams: [{ key: 'webhook_url', label: 'Webhook URL', type: 'text', placeholder: 'https://outlook.office.com/webhook/...', required: true }],
    google_chat: [{ key: 'webhook_url', label: 'Webhook URL', type: 'text', placeholder: 'https://chat.googleapis.com/v1/spaces/...', required: true }],
    webhook: [
        { key: 'url', label: 'URL', type: 'text', placeholder: 'https://example.com/webhook', required: true },
        { key: 'secret', label: 'Secret (optional)', type: 'text', placeholder: 'HMAC secret', required: false },
    ],
    email: [{ key: 'recipients', label: 'Recipients (comma-separated)', type: 'text', placeholder: 'ops@example.com, dev@example.com', required: true }],
    log: [{ key: 'channel', label: 'Log Channel', type: 'text', placeholder: 'stack (default Laravel channel)', required: false }],
}

const getConfigValue = (key) => {
    if (key === 'recipients' && Array.isArray(form.value.config[key])) {
        return form.value.config[key].join(', ')
    }
    return form.value.config[key] || ''
}

const setConfigValue = (key, value) => {
    if (key === 'recipients') {
        form.value.config[key] = value.split(',').map(s => s.trim()).filter(Boolean)
    } else {
        form.value.config[key] = value
    }
}

const submitForm = () => {
    formErrors.value = {}
    const url = editingChannel.value
        ? route('station.api.alerts.channels.update', editingChannel.value)
        : route('station.api.alerts.channels.store')
    const method = editingChannel.value ? 'PUT' : 'POST'

    fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify(form.value),
    }).then((response) => {
        if (response.ok) {
            showModal.value = false
            router.reload()
        } else if (response.status === 422) {
            response.json().then(data => { formErrors.value = data.errors || {} })
        }
    })
}

const testingChannel = ref(null)
const testResult = ref(null)
let testResultTimer = null

const showTestResult = (type, message) => {
    testResult.value = { type, message }
    clearTimeout(testResultTimer)
    testResultTimer = setTimeout(() => { testResult.value = null }, 5000)
}

const testChannel = (id) => {
    testingChannel.value = id
    testResult.value = null
    const channel = props.channels.find(ch => ch.id === id)
    fetch(route('station.api.alerts.channels.test', id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    }).then(async (response) => {
        testingChannel.value = null
        if (response.ok) {
            showTestResult('success', `Test notification sent to ${channel?.name || 'channel'}.`)
        } else {
            let msg = 'Test failed. Check channel configuration.'
            try { const data = await response.json(); msg = data.error || data.message || msg } catch {}
            showTestResult('error', msg)
        }
    }).catch(() => {
        testingChannel.value = null
        showTestResult('error', 'Test failed. Could not reach the server.')
    })
}

const hasLogChannel = computed(() => (props.channels || []).some(ch => ch.type === 'log'))

const openQuickLog = () => {
    editingChannel.value = null
    formErrors.value = {}
    form.value = { name: 'Dev Log', type: 'log', enabled: true, config: { channel: 'stack' } }
    showModal.value = true
}

const deleteChannel = (id) => {
    if (!confirm('Delete this channel?')) return
    fetch(route('station.api.alerts.channels.destroy', id), {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': getCsrfToken() },
    }).then((response) => {
        if (response.status === 409) {
            response.json().then(data => alert(data.error))
        } else {
            router.reload()
        }
    })
}

const toggleEnabled = (channel) => {
    fetch(route('station.api.alerts.channels.update', channel.id), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        body: JSON.stringify({ enabled: !channel.enabled }),
    }).then(() => router.reload({ preserveScroll: true }))
}

const getCsrfToken = () => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return match ? decodeURIComponent(match[1]) : ''
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
    return colors[type] || colors.webhook
}
</script>

<template>
    <Head title="Alert Channels - Station" />
    <StationLayout>
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-foreground">Alert Channels</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Configure notification destinations for alert rules.</p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="!hasLogChannel"
                        @click="openQuickLog"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-lg border border-border text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                    >
                        Quick Add Log Channel
                    </button>
                    <button
                        @click="openCreate"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors"
                    >
                        Create Channel
                    </button>
                </div>
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

            <!-- Channels table -->
            <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="channel in channels" :key="channel.id" class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-foreground">{{ channel.name }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="[typeBadgeColor(channel.type), 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium']">
                                    {{ channelTypes[channel.type] || channel.type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="toggleEnabled(channel)" :class="[channel.enabled ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600', 'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200']">
                                    <span :class="[channel.enabled ? 'translate-x-5' : 'translate-x-0', 'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 mt-0.5 ml-0.5']"></span>
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <button @click="testChannel(channel.id)" :disabled="testingChannel === channel.id" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 disabled:opacity-50">{{ testingChannel === channel.id ? 'Testing...' : 'Test' }}</button>
                                <button @click="openEdit(channel)" class="text-muted-foreground hover:text-foreground">Edit</button>
                                <button @click="deleteChannel(channel.id)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Delete</button>
                            </td>
                        </tr>
                        <tr v-if="!channels || channels.length === 0">
                            <td colspan="4" class="px-6 py-12 text-center text-sm text-muted-foreground">
                                No alert channels configured. Create one or run <code class="px-1.5 py-0.5 bg-muted rounded text-xs">station:alerts:check --seed</code> to seed defaults.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showModal = false"></div>
                    <div class="relative bg-card rounded-xl shadow-2xl max-w-lg w-full border border-border">
                        <div class="border-b border-border px-6 py-4">
                            <h3 class="text-lg font-semibold text-foreground">{{ editingChannel ? 'Edit Channel' : 'Create Alert Channel' }}</h3>
                            <p class="mt-0.5 text-sm text-muted-foreground">{{ editingChannel ? 'Modify the channel configuration.' : 'Add a new notification destination.' }}</p>
                        </div>

                        <form @submit.prevent="submitForm" class="px-6 py-5 space-y-5">
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1.5">Name</label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="e.g. Ops Slack"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground/50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors"
                                />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1.5">Type</label>
                                <select
                                    v-model="form.type"
                                    @change="form.config = {}"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors appearance-none bg-[length:16px_16px] bg-[right_12px_center] bg-no-repeat"
                                    style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e&quot;)"
                                >
                                    <option v-for="(label, value) in channelTypes" :key="value" :value="value">{{ label }}</option>
                                </select>
                            </div>

                            <!-- Dynamic config fields -->
                            <div v-for="field in (configFields[form.type] || [])" :key="field.key">
                                <label class="block text-sm font-medium text-foreground mb-1.5">{{ field.label }}</label>
                                <input
                                    :type="field.type"
                                    :value="getConfigValue(field.key)"
                                    @input="setConfigValue(field.key, $event.target.value)"
                                    :placeholder="field.placeholder"
                                    :required="field.required"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground/50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition-colors"
                                />
                            </div>

                            <div v-if="Object.keys(formErrors).length" class="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 p-3">
                                <p v-for="(messages, field) in formErrors" :key="field" class="text-sm text-red-600 dark:text-red-400">
                                    {{ Array.isArray(messages) ? messages[0] : messages }}
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    @click="form.enabled = !form.enabled"
                                    :class="[form.enabled ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600', 'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200']"
                                >
                                    <span :class="[form.enabled ? 'translate-x-5' : 'translate-x-0', 'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 mt-0.5 ml-0.5']"></span>
                                </button>
                                <span class="text-sm text-foreground">Enabled</span>
                            </div>

                            <div class="flex justify-end gap-3 pt-3 border-t border-border -mx-6 px-6 mt-6">
                                <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground rounded-lg hover:bg-muted transition-colors">Cancel</button>
                                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">{{ editingChannel ? 'Update' : 'Create' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </StationLayout>
</template>
