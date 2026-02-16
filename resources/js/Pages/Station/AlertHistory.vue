<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import StationLayout from './Layout.vue'
import Pagination from './Components/Pagination.vue'

const props = defineProps({
    history: Object,
    alertTypes: Object,
    filters: Object,
})

const selectedType = ref(props.filters?.type || '')
const selectedSeverity = ref(props.filters?.severity || '')
const selectedResolved = ref(props.filters?.resolved ?? '')

const applyFilters = () => {
    const params = {}
    if (selectedType.value) params.type = selectedType.value
    if (selectedSeverity.value) params.severity = selectedSeverity.value
    if (selectedResolved.value !== '') params.resolved = selectedResolved.value
    router.get(route('station.alerts.history'), params, { preserveState: true })
}

const resolveAlert = (id) => {
    router.post(route('station.api.alerts.history.resolve', id), {}, { preserveScroll: true })
}

const severityBadge = (severity) => {
    const classes = {
        critical: 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400',
        warning: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400',
        info: 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400',
    }
    return classes[severity] || classes.info
}

const formatDate = (dateStr) => {
    if (!dateStr) return '-'
    return new Date(dateStr).toLocaleString()
}
</script>

<template>
    <Head title="Alert History - Station" />
    <StationLayout>
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-foreground">Alert History</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Log of all triggered alerts.</p>
                </div>
                <Link :href="route('station.alerts')" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                    Manage Rules
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 items-center">
                <select v-model="selectedType" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground">
                    <option value="">All Types</option>
                    <option v-for="(label, value) in alertTypes" :key="value" :value="value">{{ label }}</option>
                </select>
                <select v-model="selectedSeverity" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground">
                    <option value="">All Severities</option>
                    <option value="critical">Critical</option>
                    <option value="warning">Warning</option>
                    <option value="info">Info</option>
                </select>
                <select v-model="selectedResolved" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground">
                    <option value="">All</option>
                    <option :value="false">Unresolved</option>
                    <option :value="true">Resolved</option>
                </select>
            </div>

            <!-- History table -->
            <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Rule</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Severity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Message</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="alert in history?.data" :key="alert.id" class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(alert.created_at) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-foreground">{{ alert.rule_name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="[severityBadge(alert.severity), 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium']">
                                    {{ alert.severity }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-muted-foreground max-w-md truncate">{{ alert.message }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="alert.resolved" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400">
                                    Resolved
                                </span>
                                <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-400">
                                    Active
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button v-if="!alert.resolved" @click="resolveAlert(alert.id)" class="text-emerald-600 hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-300">
                                    Resolve
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!history?.data || history.data.length === 0">
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-muted-foreground">No alerts have been triggered.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination v-if="history?.last_page > 1" :paginator="history" />
        </div>
    </StationLayout>
</template>
