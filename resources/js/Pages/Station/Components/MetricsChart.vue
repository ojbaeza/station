<script setup>
import { computed } from 'vue'

const props = defineProps({
    title: { type: String, required: true },
    labels: { type: Array, required: true },
    data: { type: Array, required: true },
    color: { type: String, default: '#10b981' },
    unit: { type: String, default: '' },
    link: { type: Object, default: null },
})

const maxValue = computed(() => {
    const max = Math.max(...(props.data || []), 0)
    return max > 0 ? max : 1
})

const hasData = computed(() => {
    return props.data && props.data.length > 1 && props.data.some(v => v > 0)
})

const formatValue = (val) => {
    if (val === null || val === undefined) return { text: '0', unit: props.unit }
    if (props.unit === 'ms') {
        if (val >= 60000) return { text: (val / 60000).toFixed(1), unit: 'min' }
        if (val >= 1000) return { text: (val / 1000).toFixed(1), unit: 's' }
        return { text: Number.isInteger(val) ? val.toLocaleString() : val.toFixed(0), unit: 'ms' }
    }
    return { text: Number.isInteger(val) ? val.toLocaleString() : val.toFixed(2), unit: props.unit }
}

const latestFormatted = computed(() => {
    if (!props.data || props.data.length === 0) return { text: '0', unit: props.unit }
    return formatValue(props.data[props.data.length - 1])
})

const latestValue = computed(() => latestFormatted.value.text)

const bars = computed(() => {
    if (!props.data || props.data.length === 0) return []
    const max = maxValue.value
    return props.data.map((val, i) => ({
        value: val || 0,
        height: max > 0 ? Math.max(((val || 0) / max) * 100, 2) : 2,
        label: props.labels[i] || '',
    }))
})

const gradientId = computed(() => 'grad-' + props.title.replace(/[^a-zA-Z0-9]/g, ''))

// Generate SVG path for area chart
const svgPath = computed(() => {
    if (!props.data || props.data.length < 2) return { line: '', area: '' }
    const max = maxValue.value
    const width = 400
    const height = 120
    const padding = 2
    const points = props.data.map((val, i) => ({
        x: padding + (i / (props.data.length - 1)) * (width - padding * 2),
        y: height - padding - ((val || 0) / max) * (height - padding * 2),
        value: val || 0,
        label: props.labels[i] || '',
    }))

    // Smooth curve using cardinal spline
    let line = `M ${points[0].x} ${points[0].y}`
    for (let i = 1; i < points.length; i++) {
        const prev = points[i - 1]
        const curr = points[i]
        const cpx = (prev.x + curr.x) / 2
        line += ` C ${cpx} ${prev.y}, ${cpx} ${curr.y}, ${curr.x} ${curr.y}`
    }

    const last = points[points.length - 1]
    const area = `${line} L ${last.x} ${height} L ${points[0].x} ${height} Z`

    // Percentage positions for HTML dot overlay (avoids SVG stretching)
    const pctPoints = points.map(pt => ({
        left: (pt.x / width) * 100,
        top: (pt.y / height) * 100,
        value: pt.value,
        label: pt.label,
    }))

    return { line, area, points: pctPoints }
})
</script>

<template>
    <div class="bg-card border border-border rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-medium text-foreground">
                {{ title }}
                <span v-if="unit" class="text-xs font-normal text-muted-foreground ml-1">({{ unit }})</span>
            </h3>
            <div class="flex items-center gap-3">
                <a v-if="link" :href="link.href" class="text-xs text-indigo-500 hover:text-indigo-400">{{ link.text }}</a>
                <span v-if="hasData && latestValue !== '0'" class="text-lg font-semibold" :style="{ color }">
                    {{ latestValue }}<span v-if="unit" class="text-xs font-normal text-muted-foreground ml-0.5">{{ latestFormatted.unit }}</span>
                </span>
            </div>
        </div>

        <!-- SVG area chart when data is available -->
        <div v-if="hasData" class="h-32 relative">
            <svg viewBox="0 0 400 120" preserveAspectRatio="none" class="w-full h-full">
                <defs>
                    <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="color" stop-opacity="0.4" />
                        <stop offset="100%" :stop-color="color" stop-opacity="0.05" />
                    </linearGradient>
                </defs>
                <!-- Area fill -->
                <path :d="svgPath.area" :fill="`url(#${gradientId})`" />
                <!-- Line -->
                <path :d="svgPath.line" fill="none" :stroke="color" stroke-width="1.5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </svg>
            <!-- Data point dots (HTML overlay to avoid SVG stretching) -->
            <div
                v-for="(pt, i) in svgPath.points"
                :key="i"
                class="absolute -translate-x-1/2 -translate-y-1/2 group"
                :style="{ left: pt.left + '%', top: pt.top + '%' }"
            >
                <!-- Visible dot -->
                <div
                    class="w-2.5 h-2.5 rounded-full border-2"
                    :style="{ backgroundColor: color, borderColor: 'hsl(var(--card))' }"
                ></div>
                <!-- Larger hover target -->
                <div
                    class="absolute inset-0 -m-3 cursor-default"
                    :title="pt.label + ': ' + formatValue(pt.value).text + ' ' + formatValue(pt.value).unit"
                ></div>
                <!-- Custom tooltip -->
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 text-[10px] font-medium text-white bg-gray-900 dark:bg-gray-700 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-10 shadow-lg">
                    {{ pt.label }}: {{ formatValue(pt.value).text }} {{ formatValue(pt.value).unit }}
                </div>
            </div>
            <!-- X-axis labels -->
            <div class="flex justify-between mt-1 text-[10px] text-muted-foreground">
                <span v-if="labels.length > 0">{{ labels[0] }}</span>
                <span v-if="labels.length > 2">{{ labels[Math.floor(labels.length / 2)] }}</span>
                <span v-if="labels.length > 1">{{ labels[labels.length - 1] }}</span>
            </div>
        </div>

        <!-- Skeleton placeholder when no data -->
        <div v-else class="h-32 flex flex-col justify-end">
            <div class="flex items-end gap-1 h-24">
                <div
                    v-for="i in 20"
                    :key="i"
                    class="flex-1 rounded-t bg-secondary animate-pulse"
                    :style="{ height: (8 + Math.sin(i * 0.7) * 6 + Math.random() * 4) + '%', animationDelay: (i * 80) + 'ms' }"
                ></div>
            </div>
            <p class="text-xs text-muted-foreground text-center mt-2">No data for this period</p>
        </div>
    </div>
</template>
