<script setup>
import { Head } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import StationLayout from './Layout.vue'

defineProps({
    config: Object,
})

const themeMode = ref('system')
const helpExpanded = ref(false)

onMounted(() => {
    try {
        themeMode.value = localStorage.getItem('station-theme') || 'system'
    } catch {}
})

const setTheme = (mode) => {
    themeMode.value = mode
    try {
        localStorage.setItem('station-theme', mode)
    } catch {}
    const html = document.documentElement
    if (mode === 'dark') {
        html.classList.add('dark')
    } else if (mode === 'light') {
        html.classList.remove('dark')
    } else {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            html.classList.add('dark')
        } else {
            html.classList.remove('dark')
        }
    }
}
</script>

<template>
    <Head title="Settings - Station" />

    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Settings</h1>
                <p class="mt-1 text-sm text-muted-foreground">Dashboard preferences and configuration overview</p>
            </div>

            <!-- Theme Selector -->
            <div class="bg-card border border-border rounded-lg p-5">
                <h3 class="text-sm font-semibold text-foreground mb-3">Theme</h3>
                <div class="flex gap-2">
                    <button
                        v-for="mode in ['light', 'dark', 'system']"
                        :key="mode"
                        @click="setTheme(mode)"
                        :class="[
                            themeMode === mode
                                ? 'bg-indigo-600 text-white'
                                : 'bg-secondary text-muted-foreground hover:text-foreground',
                            'px-4 py-2 text-sm font-medium rounded-md capitalize transition-colors'
                        ]"
                    >
                        {{ mode }}
                    </button>
                </div>
            </div>

            <!-- Two-column grid -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Left: Config -->
                <div class="space-y-6">
                    <!-- Current Configuration -->
                    <div class="bg-card border border-border rounded-lg">
                        <div class="px-5 py-4 border-b border-border">
                            <h3 class="text-sm font-semibold text-foreground">Current Configuration</h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Loaded from <code class="bg-secondary px-1 rounded">config/station.php</code>
                            </p>
                        </div>
                        <div class="p-5 space-y-5">
                            <!-- Driver -->
                            <div>
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Queue Driver</h4>
                                <div class="bg-secondary rounded-lg p-3">
                                    <dl>
                                        <dt class="text-xs text-muted-foreground">Default Driver</dt>
                                        <dd class="mt-0.5 text-sm font-medium text-foreground">{{ config.driver }}</dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- Dashboard Settings -->
                            <div>
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Dashboard</h4>
                                <div class="bg-secondary rounded-lg p-3">
                                    <dl class="grid grid-cols-2 gap-3">
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Enabled</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.dashboard?.enabled ? 'Yes' : 'No' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Path</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                /{{ config.dashboard?.path || 'station' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <!-- Telemetry -->
                            <div>
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Telemetry</h4>
                                <div class="bg-secondary rounded-lg p-3">
                                    <dl class="grid grid-cols-2 gap-3">
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Status</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                <span :class="config.telemetry?.enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                                                    {{ config.telemetry?.enabled ? 'Enabled' : 'Disabled' }}
                                                </span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Driver</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.telemetry?.driver || 'internal' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <!-- Alerts -->
                            <div>
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Alerts</h4>
                                <div class="bg-secondary rounded-lg p-3">
                                    <dl>
                                        <dt class="text-xs text-muted-foreground">Status</dt>
                                        <dd class="mt-0.5 text-sm font-medium text-foreground">
                                            <span :class="config.alerts?.enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                                                {{ config.alerts?.enabled ? 'Enabled' : 'Disabled' }}
                                            </span>
                                        </dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- Supervisor Settings -->
                            <div>
                                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Supervisor</h4>
                                <div class="bg-secondary rounded-lg p-3">
                                    <dl class="grid grid-cols-2 gap-3">
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Workers</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.supervisor?.defaults?.workers || 3 }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Memory Limit</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.supervisor?.defaults?.memory || 128 }} MB
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Timeout</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.supervisor?.defaults?.timeout || 60 }}s
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-muted-foreground">Max Tries</dt>
                                            <dd class="mt-0.5 text-sm font-medium text-foreground">
                                                {{ config.supervisor?.defaults?.tries || 3 }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Commands + Help -->
                <div class="space-y-6">
                    <!-- Commands -->
                    <div class="bg-card border border-border rounded-lg">
                        <div class="px-5 py-4 border-b border-border">
                            <h3 class="text-sm font-semibold text-foreground">Available Commands</h3>
                        </div>
                        <div class="p-5 space-y-3">
                            <div v-for="cmd in [
                                { name: 'station:work', desc: 'Start the queue worker supervisor' },
                                { name: 'station:status', desc: 'Display queue and worker status' },
                                { name: 'station:health', desc: 'Check system health' },
                                { name: 'station:recover', desc: 'Detect and recover stuck jobs' },
                                { name: 'station:failed', desc: 'List failed jobs' },
                                { name: 'station:retry', desc: 'Retry failed jobs' },
                                { name: 'station:prune', desc: 'Prune old records' },
                                { name: 'station:alerts:check', desc: 'Evaluate alert rules and notify' },
                            ]" :key="cmd.name">
                                <div>
                                    <code class="text-xs text-indigo-500 dark:text-indigo-400">{{ cmd.name }}</code>
                                    <p class="text-xs text-muted-foreground">{{ cmd.desc }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Help (collapsible) -->
                    <div class="bg-card border border-border rounded-lg">
                        <button
                            @click="helpExpanded = !helpExpanded"
                            class="w-full px-5 py-4 flex items-center justify-between text-left"
                        >
                            <h3 class="text-sm font-semibold text-foreground">Help & Configuration</h3>
                            <svg
                                :class="helpExpanded ? 'rotate-180' : ''"
                                class="h-4 w-4 text-muted-foreground transition-transform"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div v-if="helpExpanded" class="px-5 pb-5 border-t border-border pt-4">
                            <p class="text-sm text-muted-foreground">
                                To modify these settings, edit your <code class="bg-secondary px-1 rounded text-xs">config/station.php</code> file.
                                You can publish the configuration using:
                            </p>
                            <pre class="mt-2 text-xs text-indigo-400 dark:text-indigo-300 bg-secondary p-3 rounded-lg overflow-x-auto">php artisan vendor:publish --tag=station-config</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </StationLayout>
</template>
