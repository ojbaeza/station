<script setup>
import { computed, ref, onMounted } from 'vue'

const props = defineProps({
    workflow: {
        type: Object,
        required: true
    },
    jobs: {
        type: Array,
        default: () => []
    }
})

const containerRef = ref(null)

const statusColors = {
    pending: 'stroke-yellow-400 fill-yellow-50 dark:fill-yellow-900',
    processing: 'stroke-blue-400 fill-blue-50 dark:fill-blue-900',
    completed: 'stroke-green-400 fill-green-50 dark:fill-green-900',
    failed: 'stroke-red-400 fill-red-50 dark:fill-red-900',
}

const statusTextColors = {
    pending: 'text-yellow-800 dark:text-yellow-200',
    processing: 'text-blue-800 dark:text-blue-200',
    completed: 'text-green-800 dark:text-green-200',
    failed: 'text-red-800 dark:text-red-200',
}

// Parse workflow structure to build graph nodes
const nodes = computed(() => {
    if (!props.workflow?.steps) return []

    const steps = Object.entries(props.workflow.steps)
    const nodeWidth = 160
    const nodeHeight = 60
    const horizontalGap = 80
    const verticalGap = 40

    // Calculate levels based on dependencies
    const levels = {}
    const visited = new Set()

    const getLevel = (stepName) => {
        if (levels[stepName] !== undefined) return levels[stepName]

        const step = props.workflow.steps[stepName]
        if (!step || !step.dependencies || step.dependencies.length === 0) {
            levels[stepName] = 0
            return 0
        }

        const maxDepLevel = Math.max(...step.dependencies.map(dep => getLevel(dep)))
        levels[stepName] = maxDepLevel + 1
        return levels[stepName]
    }

    steps.forEach(([name]) => getLevel(name))

    // Group by level
    const levelGroups = {}
    Object.entries(levels).forEach(([name, level]) => {
        if (!levelGroups[level]) levelGroups[level] = []
        levelGroups[level].push(name)
    })

    // Position nodes
    const result = []
    Object.entries(levelGroups).forEach(([level, names]) => {
        const levelNum = parseInt(level)
        names.forEach((name, index) => {
            const job = props.jobs.find(j => j.workflow_step === name)
            result.push({
                name,
                x: levelNum * (nodeWidth + horizontalGap) + 20,
                y: index * (nodeHeight + verticalGap) + 20,
                width: nodeWidth,
                height: nodeHeight,
                status: job?.status || 'pending',
                dependencies: props.workflow.steps[name]?.dependencies || [],
            })
        })
    })

    return result
})

// Calculate edges between nodes
const edges = computed(() => {
    const result = []

    nodes.value.forEach(node => {
        node.dependencies.forEach(dep => {
            const sourceNode = nodes.value.find(n => n.name === dep)
            if (sourceNode) {
                result.push({
                    from: sourceNode,
                    to: node,
                })
            }
        })
    })

    return result
})

const svgWidth = computed(() => {
    const maxX = Math.max(...nodes.value.map(n => n.x + n.width), 400)
    return maxX + 40
})

const svgHeight = computed(() => {
    const maxY = Math.max(...nodes.value.map(n => n.y + n.height), 200)
    return maxY + 40
})
</script>

<template>
    <div ref="containerRef" class="overflow-x-auto bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
        <svg :width="svgWidth" :height="svgHeight" class="min-w-full">
            <!-- Draw edges first (behind nodes) -->
            <g>
                <path
                    v-for="(edge, index) in edges"
                    :key="`edge-${index}`"
                    :d="`M ${edge.from.x + edge.from.width} ${edge.from.y + edge.from.height / 2}
                         C ${edge.from.x + edge.from.width + 40} ${edge.from.y + edge.from.height / 2},
                           ${edge.to.x - 40} ${edge.to.y + edge.to.height / 2},
                           ${edge.to.x} ${edge.to.y + edge.to.height / 2}`"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="text-gray-300 dark:text-gray-600"
                    marker-end="url(#arrowhead)"
                />
            </g>

            <!-- Arrow marker definition -->
            <defs>
                <marker
                    id="arrowhead"
                    markerWidth="10"
                    markerHeight="7"
                    refX="9"
                    refY="3.5"
                    orient="auto"
                >
                    <polygon
                        points="0 0, 10 3.5, 0 7"
                        fill="currentColor"
                        class="text-gray-300 dark:text-gray-600"
                    />
                </marker>
            </defs>

            <!-- Draw nodes -->
            <g v-for="node in nodes" :key="node.name">
                <rect
                    :x="node.x"
                    :y="node.y"
                    :width="node.width"
                    :height="node.height"
                    rx="8"
                    ry="8"
                    stroke-width="2"
                    :class="statusColors[node.status]"
                />
                <text
                    :x="node.x + node.width / 2"
                    :y="node.y + node.height / 2 - 8"
                    text-anchor="middle"
                    class="text-sm font-medium fill-gray-900 dark:fill-white"
                >
                    {{ node.name }}
                </text>
                <text
                    :x="node.x + node.width / 2"
                    :y="node.y + node.height / 2 + 12"
                    text-anchor="middle"
                    :class="['text-xs', statusTextColors[node.status]]"
                >
                    {{ node.status }}
                </text>
            </g>
        </svg>

        <div v-if="!workflow?.steps || Object.keys(workflow.steps).length === 0" class="text-center py-6 text-sm text-gray-500 dark:text-gray-400">
            No workflow steps defined
        </div>
    </div>
</template>
