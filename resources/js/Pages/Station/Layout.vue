<script setup>
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted, provide } from 'vue'
import DriverConnectivity from './Components/DriverConnectivity.vue'

const sidebarOpen = ref(false)
const sidebarCollapsed = ref(false)
const themeMode = ref('system')

// Auto-refresh state
const autoRefreshEnabled = ref(true)
const refreshIntervalMs = ref(5000)

onMounted(() => {
    try {
        sidebarCollapsed.value = localStorage.getItem('station-sidebar-collapsed') === 'true'
    } catch {
        // localStorage unavailable
    }
    try {
        themeMode.value = localStorage.getItem('station-theme') || 'system'
    } catch {
        // localStorage unavailable
    }
    try {
        const savedAR = localStorage.getItem('station-auto-refresh')
        if (savedAR !== null) autoRefreshEnabled.value = savedAR !== 'false'
        const savedInterval = localStorage.getItem('station-refresh-interval')
        if (savedInterval) refreshIntervalMs.value = Number(savedInterval)
    } catch {
        // localStorage unavailable
    }
    applyTheme()
})

watch(sidebarCollapsed, (value) => {
    try {
        localStorage.setItem('station-sidebar-collapsed', String(value))
    } catch {}
})

const applyTheme = () => {
    const html = document.documentElement
    if (themeMode.value === 'dark') {
        html.classList.add('dark')
    } else if (themeMode.value === 'light') {
        html.classList.remove('dark')
    } else {
        // system
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            html.classList.add('dark')
        } else {
            html.classList.remove('dark')
        }
    }
}

const cycleTheme = () => {
    const order = ['system', 'light', 'dark']
    const idx = order.indexOf(themeMode.value)
    themeMode.value = order[(idx + 1) % order.length]
    try {
        localStorage.setItem('station-theme', themeMode.value)
    } catch {}
    applyTheme()
}

const themeTooltip = computed(() => {
    return themeMode.value === 'system' ? 'Theme: System' : themeMode.value === 'light' ? 'Theme: Light' : 'Theme: Dark'
})

const toggleAutoRefresh = () => {
    autoRefreshEnabled.value = !autoRefreshEnabled.value
    try {
        localStorage.setItem('station-auto-refresh', String(autoRefreshEnabled.value))
    } catch {}
}

const persistRefreshInterval = () => {
    refreshIntervalMs.value = Number(refreshIntervalMs.value)
    try {
        localStorage.setItem('station-refresh-interval', String(refreshIntervalMs.value))
    } catch {}
}

// Window focus detection for auto-refresh optimization
const windowFocused = ref(true)
const onVisibilityChange = () => { windowFocused.value = !document.hidden }
onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange))
onUnmounted(() => document.removeEventListener('visibilitychange', onVisibilityChange))

// Provide auto-refresh for child pages
provide('autoRefresh', {
    enabled: autoRefreshEnabled,
    interval: refreshIntervalMs,
    focused: windowFocused,
})

const navigation = [
    { name: 'Dashboard', href: route('station.dashboard'), icon: 'home' },
    { name: 'Connections', href: route('station.connections'), icon: 'queue' },
    {
        name: 'Jobs', href: route('station.jobs'), icon: 'briefcase',
        children: [
            { name: 'Pending', href: route('station.pending'), icon: 'clock' },
            { name: 'Failed', href: route('station.failed'), icon: 'x-circle' },
            { name: 'Stuck', href: route('station.stuck'), icon: 'exclamation-triangle' },
            { name: 'Completed', href: route('station.completed'), icon: 'check-circle' },
            { name: 'Silenced', href: route('station.silenced'), icon: 'eye-off' },
        ],
    },
    { name: 'Batches', href: route('station.batches'), icon: 'collection' },
    {
        name: 'Workflows', href: route('station.workflows'), icon: 'workflow',
        children: [
            { name: 'Definitions', href: route('station.workflows.definitions'), icon: 'template' },
        ],
    },
    { name: 'Tags', href: route('station.tags'), icon: 'tag' },
    {
        name: 'Metrics', href: route('station.metrics'), icon: 'trending-up',
        children: [
            { name: 'Queues', href: route('station.metrics.queues'), icon: 'queue' },
            { name: 'Records', href: route('station.metrics.records'), icon: 'table' },
        ],
    },
    {
        name: 'Alerts', href: route('station.alerts'), icon: 'bell',
        children: [
            { name: 'Rules', href: route('station.alerts.rules'), icon: 'adjustments' },
            { name: 'Channels', href: route('station.alerts.channels'), icon: 'signal' },
        ],
    },
    { name: 'Audit Log', href: route('station.audit-log'), icon: 'document' },
]

const currentUrl = computed(() => window.location.pathname)

const isActive = (href) => {
    try {
        const url = new URL(href, window.location.origin)
        return window.location.pathname === url.pathname
    } catch {
        return false
    }
}

const isParentActive = (item) => {
    if (isActive(item.href)) return true
    if (item.children) {
        return item.children.some(child => isActive(child.href))
    }
    return false
}

const iconPaths = {
    home: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    queue: 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01',
    briefcase: 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
    'x-circle': 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    'exclamation-triangle': 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
    collection: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
    workflow: 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z',
    'trending-up': 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
    cog: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    clock: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    'check-circle': 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    'eye-off': 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21',
    template: 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm0 8a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zm12 0a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z',
    table: 'M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
    bell: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    adjustments: 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75',
    signal: 'M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.808-3.808-9.98 0-13.788m13.788 0c3.808 3.808 3.808 9.98 0 13.788M12 12h.008v.008H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
    tag: 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z',
    document: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    'chevron-left': 'M15 19l-7-7 7-7',
    'chevron-right': 'M9 5l7 7-7 7',
    'chevron-down': 'M19 9l-7 7-7-7',
    refresh: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
    sun: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z',
    moon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
    monitor: 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
}

const themeIcon = computed(() => {
    if (themeMode.value === 'light') return iconPaths.sun
    if (themeMode.value === 'dark') return iconPaths.moon
    return iconPaths.monitor
})
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <!-- Mobile sidebar -->
        <div v-if="sidebarOpen" class="fixed inset-0 flex z-40 lg:hidden" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-black/50" @click="sidebarOpen = false"></div>
            <div class="relative flex-1 flex flex-col max-w-xs w-full bg-card border-r border-border shadow-xl">
                <div class="absolute top-0 right-0 -mr-12 pt-2">
                    <button
                        type="button"
                        class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none"
                        @click="sidebarOpen = false"
                    >
                        <span class="sr-only">Close sidebar</span>
                        <svg class="h-6 w-6 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                    <div class="flex-shrink-0 flex items-center px-4">
                        <div class="flex items-center gap-3">
                            <div class="h-9 w-9 rounded-lg bg-indigo-600 flex items-center justify-center">
                                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 4h8a4 4 0 014 4v6a4 4 0 01-4 4H8a4 4 0 01-4-4V8a4 4 0 014-4z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l-2 4m8-4l2 4M9 11h.01M15 11h.01M8 14h8" />
                                </svg>
                            </div>
                            <span class="text-xl font-bold text-foreground">Station</span>
                        </div>
                    </div>
                    <nav class="mt-6 px-3 space-y-1">
                        <template v-for="item in navigation" :key="item.name">
                            <Link
                                :href="item.href"
                                :class="[
                                    isActive(item.href)
                                        ? 'bg-indigo-50 text-indigo-700 border-l-2 border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400'
                                        : 'text-muted-foreground hover:bg-secondary hover:text-foreground border-l-2 border-transparent',
                                    'group flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-r-lg transition-colors'
                                ]"
                            >
                                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[item.icon]" />
                                </svg>
                                {{ item.name }}
                            </Link>
                            <!-- Mobile children -->
                            <template v-if="item.children && isParentActive(item)">
                                <Link
                                    v-for="child in item.children"
                                    :key="child.name"
                                    :href="child.href"
                                    :class="[
                                        isActive(child.href)
                                            ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400'
                                            : 'text-muted-foreground hover:bg-secondary hover:text-foreground',
                                        'group flex items-center gap-3 pl-11 pr-3 py-2 text-sm font-medium rounded-r-lg transition-colors'
                                    ]"
                                >
                                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[child.icon]" />
                                    </svg>
                                    {{ child.name }}
                                </Link>
                            </template>
                        </template>
                    </nav>
                </div>
                <!-- Mobile footer -->
                <div class="flex-shrink-0 border-t border-border p-3">
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('station.settings')"
                            class="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground hover:text-foreground rounded-lg hover:bg-secondary transition-colors"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths.cog" />
                            </svg>
                            Settings
                        </Link>
                        <button
                            @click="cycleTheme"
                            class="p-2 text-muted-foreground hover:text-foreground rounded-lg hover:bg-secondary transition-colors"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="themeIcon" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Static sidebar for desktop -->
        <div
            :class="[
                sidebarCollapsed ? 'lg:w-16' : 'lg:w-64',
                'hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 transition-all duration-300'
            ]"
        >
            <div class="flex-1 flex flex-col min-h-0 border-r border-border bg-card">
                <div :class="['flex-1 flex flex-col pt-6 pb-4', sidebarCollapsed ? 'overflow-visible' : 'overflow-y-auto']">
                    <div :class="['flex items-center flex-shrink-0', sidebarCollapsed ? 'justify-center px-2' : 'px-5']">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0">
                                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 4h8a4 4 0 014 4v6a4 4 0 01-4 4H8a4 4 0 01-4-4V8a4 4 0 014-4z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l-2 4m8-4l2 4M9 11h.01M15 11h.01M8 14h8" />
                                </svg>
                            </div>
                            <div v-if="!sidebarCollapsed">
                                <span class="text-xl font-bold text-foreground">Station</span>
                                <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Queue Dashboard</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="!sidebarCollapsed" class="mt-8 px-4">
                        <p class="px-3 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">Navigation</p>
                    </div>

                    <nav :class="[sidebarCollapsed ? 'mt-6' : 'mt-3', 'flex-1 px-3 space-y-0.5']">
                        <template v-for="item in navigation" :key="item.name">
                            <Link
                                :href="item.href"
                                :class="[
                                    isActive(item.href)
                                        ? 'bg-indigo-50 text-indigo-700 border-l-2 border-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400'
                                        : 'text-muted-foreground hover:bg-secondary hover:text-foreground border-l-2 border-transparent',
                                    sidebarCollapsed ? 'justify-center px-2 border-l-0' : 'gap-3 px-3',
                                    'group relative flex items-center py-2.5 text-sm font-medium rounded-r-lg transition-colors'
                                ]"
                            >
                                <svg
                                    :class="[
                                        isActive(item.href) ? 'text-indigo-600 dark:text-indigo-400' : 'text-muted-foreground group-hover:text-foreground',
                                        'h-5 w-5 flex-shrink-0 transition-colors'
                                    ]"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[item.icon]" />
                                </svg>
                                <template v-if="!sidebarCollapsed">
                                    {{ item.name }}
                                </template>
                                <span
                                    v-if="sidebarCollapsed"
                                    class="absolute left-full ml-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-white bg-gray-900 dark:bg-gray-700 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-50 shadow-lg"
                                >
                                    {{ item.name }}
                                </span>
                            </Link>
                            <!-- Desktop children (visible when parent or child active, hidden when collapsed) -->
                            <template v-if="item.children && !sidebarCollapsed && isParentActive(item)">
                                <Link
                                    v-for="child in item.children"
                                    :key="child.name"
                                    :href="child.href"
                                    :class="[
                                        isActive(child.href)
                                            ? 'text-indigo-700 dark:text-indigo-400'
                                            : 'text-muted-foreground hover:text-foreground',
                                        'group flex items-center gap-2.5 pl-11 pr-3 py-1.5 text-sm transition-colors rounded-lg'
                                    ]"
                                >
                                    <svg
                                        :class="[
                                            isActive(child.href) ? 'text-indigo-600 dark:text-indigo-400' : 'text-muted-foreground group-hover:text-foreground',
                                            'h-4 w-4 flex-shrink-0 transition-colors'
                                        ]"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths[child.icon]" />
                                    </svg>
                                    {{ child.name }}
                                </Link>
                            </template>
                        </template>
                    </nav>
                </div>
                <!-- Sidebar footer -->
                <div class="flex-shrink-0 border-t border-border p-3">
                    <div class="flex items-center" :class="sidebarCollapsed ? 'flex-col gap-2' : 'gap-1'">
                        <Link
                            :href="route('station.settings')"
                            class="group relative p-2 text-muted-foreground hover:text-foreground rounded-lg hover:bg-secondary transition-colors"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPaths.cog" />
                            </svg>
                            <span
                                v-if="sidebarCollapsed"
                                class="absolute left-full ml-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-white bg-gray-900 dark:bg-gray-700 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-50 shadow-lg"
                            >
                                Settings
                            </span>
                        </Link>
                        <button
                            @click="cycleTheme"
                            class="group relative p-2 text-muted-foreground hover:text-foreground rounded-lg hover:bg-secondary transition-colors"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="themeIcon" />
                            </svg>
                            <span
                                v-if="sidebarCollapsed"
                                class="absolute left-full ml-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-white bg-gray-900 dark:bg-gray-700 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-50 shadow-lg"
                            >
                                {{ themeTooltip }}
                            </span>
                        </button>
                        <div v-if="!sidebarCollapsed" class="flex-1"></div>
                        <button
                            @click="sidebarCollapsed = !sidebarCollapsed"
                            class="group relative p-2 text-muted-foreground hover:text-foreground rounded-lg hover:bg-secondary transition-colors"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="sidebarCollapsed ? iconPaths['chevron-right'] : iconPaths['chevron-left']" />
                            </svg>
                            <span
                                v-if="sidebarCollapsed"
                                class="absolute left-full ml-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium text-white bg-gray-900 dark:bg-gray-700 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-50 shadow-lg"
                            >
                                Expand sidebar
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main content -->
        <div
            :class="[
                sidebarCollapsed ? 'lg:pl-16' : 'lg:pl-64',
                'flex flex-col flex-1 transition-all duration-300'
            ]"
        >
            <div class="sticky top-0 z-10 lg:hidden bg-background border-b border-border flex items-center justify-between px-3 py-2">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="h-10 w-10 inline-flex items-center justify-center rounded-md text-muted-foreground hover:text-foreground focus:outline-none"
                        @click="sidebarOpen = true"
                    >
                        <span class="sr-only">Open sidebar</span>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="flex items-center gap-2.5">
                        <div class="h-9 w-9 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0">
                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 4h8a4 4 0 014 4v6a4 4 0 01-4 4H8a4 4 0 01-4-4V8a4 4 0 014-4z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l-2 4m8-4l2 4M9 11h.01M15 11h.01M8 14h8" />
                            </svg>
                        </div>
                        <span class="text-lg font-bold text-foreground leading-none">Station</span>
                    </div>
                </div>
                <!-- Mobile auto-refresh -->
                <div class="flex items-center gap-2 bg-muted/40 rounded-lg px-3 py-1.5">
                    <button @click="toggleAutoRefresh" class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground py-1 px-2 whitespace-nowrap">
                        <span class="h-2.5 w-2.5 rounded-full flex-shrink-0" :class="autoRefreshEnabled ? 'bg-emerald-500' : 'bg-zinc-500'"></span>
                        Auto-Refresh
                    </button>
                    <select v-model="refreshIntervalMs" @change="persistRefreshInterval" class="text-sm bg-secondary border-border rounded pl-2 pr-7 py-1 text-muted-foreground">
                        <option :value="1000">1s</option>
                        <option :value="3000">3s</option>
                        <option :value="5000">5s</option>
                        <option :value="10000">10s</option>
                        <option :value="30000">30s</option>
                        <option :value="60000">60s</option>
                    </select>
                </div>
            </div>

            <!-- Desktop connections + auto-refresh bar -->
            <div class="hidden lg:block px-4 sm:px-6 lg:px-8 pt-3 pb-1">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 bg-muted/40 rounded-lg px-4 py-2.5">
                    <DriverConnectivity :drivers="{}" />
                    <div class="flex-1 min-w-0"></div>
                    <button @click="toggleAutoRefresh" class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors py-1 px-2">
                        <span class="h-2.5 w-2.5 rounded-full" :class="autoRefreshEnabled ? 'bg-emerald-500' : 'bg-zinc-500'"></span>
                        Auto-Refresh
                    </button>
                    <select v-model="refreshIntervalMs" @change="persistRefreshInterval" class="text-sm bg-secondary border-border rounded pl-2 pr-7 py-1 text-muted-foreground focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option :value="1000">1s</option>
                        <option :value="3000">3s</option>
                        <option :value="5000">5s</option>
                        <option :value="10000">10s</option>
                        <option :value="30000">30s</option>
                        <option :value="60000">60s</option>
                    </select>
                </div>
            </div>

            <main class="flex-1">
                <div class="py-8 -mt-4">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                        <slot />
                    </div>
                </div>
            </main>

            <!-- Footer -->
            <footer class="border-t border-border py-4 px-4 sm:px-6 lg:px-8">
                <div class="max-w-7xl mx-auto text-center text-xs text-muted-foreground">
                    Station &mdash; Queue Management for Laravel
                </div>
            </footer>
        </div>
    </div>
</template>
