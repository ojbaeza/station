<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    data: Object,
})

// Window the links: Previous, 1, ..., current-1, current, current+1, ..., last, Next
const windowedLinks = computed(() => {
    if (!props.data?.links) return []
    const all = props.data.links
    // First and last are Previous/Next
    const prev = all[0]
    const next = all[all.length - 1]
    const pages = all.slice(1, -1)

    if (pages.length <= 7) return all

    const current = props.data.current_page
    const last = pages.length
    const window = new Set([1, 2, current - 1, current, current + 1, last - 1, last])
    const result = [prev]
    let prevPage = 0

    for (const p of pages) {
        const num = parseInt(p.label)
        if (isNaN(num)) continue
        if (!window.has(num)) continue
        if (num - prevPage > 1) {
            result.push({ url: null, label: '&hellip;', active: false, ellipsis: true })
        }
        result.push(p)
        prevPage = num
    }

    result.push(next)
    return result
})
</script>

<template>
    <div v-if="data.last_page > 1" class="bg-card px-4 py-3 flex items-center justify-between border-t border-border sm:px-6">
        <div class="flex-1 flex justify-between sm:hidden">
            <Link
                v-if="data.prev_page_url"
                :href="data.prev_page_url"
                class="relative inline-flex items-center px-4 py-2 border border-border text-sm font-medium rounded-md text-foreground bg-secondary hover:bg-secondary/80"
            >
                Previous
            </Link>
            <Link
                v-if="data.next_page_url"
                :href="data.next_page_url"
                class="ml-3 relative inline-flex items-center px-4 py-2 border border-border text-sm font-medium rounded-md text-foreground bg-secondary hover:bg-secondary/80"
            >
                Next
            </Link>
        </div>
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-muted-foreground">
                    Showing
                    <span class="font-medium text-foreground">{{ data.from }}</span>
                    to
                    <span class="font-medium text-foreground">{{ data.to }}</span>
                    of
                    <span class="font-medium text-foreground">{{ data.total }}</span>
                    results
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md -space-x-px" aria-label="Pagination">
                    <template v-for="(link, index) in windowedLinks" :key="index">
                        <span
                            v-if="link.ellipsis"
                            class="relative inline-flex items-center px-4 py-2 border border-border bg-card text-sm font-medium text-muted-foreground cursor-default"
                            v-html="link.label"
                        />
                        <Link
                            v-else
                            :href="link.url || '#'"
                            :class="[
                                link.active
                                    ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400'
                                    : 'bg-card border-border text-muted-foreground hover:bg-secondary hover:text-foreground',
                                !link.url ? 'cursor-not-allowed opacity-50' : '',
                                'relative inline-flex items-center px-4 py-2 border text-sm font-medium'
                            ]"
                            v-html="link.label"
                        />
                    </template>
                </nav>
            </div>
        </div>
    </div>
</template>
