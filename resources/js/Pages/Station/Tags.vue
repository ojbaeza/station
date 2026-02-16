<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { inject, onUnmounted, ref, watch } from 'vue'
import StationLayout from './Layout.vue'
import Pagination from './Components/Pagination.vue'

const props = defineProps({
    tags: Object,
    filters: Object,
    connections: { type: Array, default: () => [] },
})

const search = ref(props.filters?.search || '')
const selectedConnection = ref(props.filters?.connection || '')

let debounceTimer = null

const applyFilters = () => {
    router.get(route('station.tags'), {
        search: search.value || undefined,
        connection: selectedConnection.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

watch(selectedConnection, applyFilters)
watch(search, () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(applyFilters, 300)
})

const autoRefresh = inject('autoRefresh', { enabled: ref(false), interval: ref(5000), focused: ref(true) })
let refreshTimer = null

const loadTags = () => {
    if (!autoRefresh.focused.value) return
    router.reload({ only: ['tags'], preserveScroll: true })
}

watch([() => autoRefresh.enabled.value, () => autoRefresh.interval.value], () => {
    clearInterval(refreshTimer)
    if (autoRefresh.enabled.value) {
        refreshTimer = setInterval(loadTags, autoRefresh.interval.value)
    }
}, { immediate: true })

onUnmounted(() => {
    clearInterval(refreshTimer)
    clearTimeout(debounceTimer)
})
</script>

<template>
    <Head title="Tags - Station" />
    <StationLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-foreground">Tags</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Job tags and their counts. Click a tag to view its jobs.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                    <select
                        v-if="connections.length > 0"
                        v-model="selectedConnection"
                        class="block w-full sm:w-auto min-w-[160px] pl-3 pr-10 py-2 text-sm border-border bg-secondary text-foreground focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 rounded-md"
                    >
                        <option value="">All Connections</option>
                        <option v-for="conn in connections" :key="conn" :value="conn">{{ conn }}</option>
                    </select>
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search tags..."
                            class="w-full sm:w-64 pl-9 pr-3 py-2 text-sm rounded-md border border-border bg-secondary text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                        />
                    </div>
                </div>
            </div>

            <!-- Tags list -->
            <div v-if="tags?.data?.length > 0" class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-secondary/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tag</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">Jobs</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="tag in tags.data"
                            :key="tag.tag"
                            class="hover:bg-secondary/30 transition-colors"
                        >
                            <td class="px-6 py-3">
                                <Link
                                    :href="route('station.jobs') + '?tag=' + encodeURIComponent(tag.tag)"
                                    class="text-sm font-medium text-foreground hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                                >
                                    {{ tag.tag }}
                                </Link>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-muted text-muted-foreground">
                                    {{ tag.count.toLocaleString() }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Pagination :data="tags" />
            </div>

            <!-- Empty state -->
            <div v-else class="bg-card rounded-xl border border-border shadow-sm p-12 text-center">
                <p class="text-sm text-muted-foreground">
                    {{ search ? 'No tags matching "' + search + '".' : 'No tagged jobs found.' }}
                </p>
            </div>
        </div>
    </StationLayout>
</template>
