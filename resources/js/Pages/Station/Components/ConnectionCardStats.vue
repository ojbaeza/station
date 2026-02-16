<script setup>
import { computed } from 'vue'
import MetricsChart from './MetricsChart.vue'

const props = defineProps({
    info: {
        type: Object,
        default: null,
    },
    timeSeries: {
        type: Object,
        default: null,
    },
    dashboardUrl: {
        type: String,
        default: null,
    },
})

const hasQueues = computed(() => {
    return props.info?.queues && Object.keys(props.info.queues).length > 0
})

const hiddenQueuesCount = computed(() => {
    if (!props.info?.queues_total) return 0
    return props.info.queues_total - Object.keys(props.info.queues || {}).length
})

const formatBytes = (bytes) => {
    if (!bytes) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0'
    return num.toLocaleString()
}

const driverLabels = {
    rabbitmq: 'RabbitMQ',
    redis: 'Redis',
    sqs: 'Amazon SQS',
    beanstalkd: 'Beanstalkd',
    kafka: 'Apache Kafka',
}

const hasTimeSeries = computed(() => {
    if (!props.timeSeries) return false
    return (props.timeSeries.queue_size?.length || 0) > 1
})

const queueDepthChart = computed(() => {
    if (!hasTimeSeries.value) return null
    const points = props.timeSeries.queue_size
    return {
        labels: points.map(p => p.time),
        data: points.map(p => p.value),
    }
})

const opsRateChart = computed(() => {
    if (!hasTimeSeries.value) return null
    const points = props.timeSeries.ops_rate
    return {
        labels: points.map(p => p.time),
        data: points.map(p => p.value),
    }
})

</script>

<template>
    <div v-if="!info" class="py-6 text-center text-sm text-muted-foreground">
        No stats available
    </div>
    <div v-else-if="info.error" class="py-4">
        <p class="text-sm text-red-400">{{ info.error }}</p>
    </div>
    <div v-else class="space-y-2 text-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-semibold text-foreground">{{ driverLabels[info.driver] || info.driver }}</span>
            <a
                v-if="dashboardUrl"
                :href="dashboardUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium text-muted-foreground hover:text-foreground bg-secondary hover:bg-secondary/80 transition-colors"
                title="Open driver dashboard"
            >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                Dashboard
            </a>
        </div>

        <div class="flex justify-between">
            <span class="text-muted-foreground">Queue Size</span>
            <span class="text-foreground font-medium">{{ formatNumber(info.size) }}</span>
        </div>

        <!-- RabbitMQ specific -->
        <template v-if="info.driver === 'rabbitmq'">
            <template v-if="info.management_api">
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Messages Ready</span>
                    <span class="text-foreground">{{ formatNumber(info.messages_ready) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Unacked</span>
                    <span class="text-foreground">{{ formatNumber(info.messages_unacked) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Consumers</span>
                    <span class="text-foreground">{{ formatNumber(info.consumers) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Publish Rate</span>
                    <span class="text-foreground">{{ info.publish_rate?.toFixed(1) || 0 }}/s</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Deliver Rate</span>
                    <span class="text-foreground">{{ info.deliver_rate?.toFixed(1) || 0 }}/s</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-muted-foreground">Memory</span>
                    <span class="text-foreground">{{ formatBytes(info.memory) }}</span>
                </div>
                <div v-if="info.dlq_size > 0" class="flex justify-between">
                    <span class="text-muted-foreground">DLQ Size</span>
                    <span class="text-red-400 font-medium">{{ formatNumber(info.dlq_size) }}</span>
                </div>
            </template>
            <div v-else class="text-xs text-muted-foreground bg-secondary/50 rounded p-2 mt-2">
                Management API unavailable - showing basic stats only
            </div>
        </template>

        <!-- Redis specific -->
        <template v-if="info.driver === 'redis'">
            <div class="flex justify-between">
                <span class="text-muted-foreground">Ready</span>
                <span class="text-foreground">{{ formatNumber(info.ready) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Delayed</span>
                <span class="text-foreground">{{ formatNumber(info.delayed) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Reserved</span>
                <span class="text-foreground">{{ formatNumber(info.reserved) }}</span>
            </div>
            <div v-if="info.memory_used" class="flex justify-between">
                <span class="text-muted-foreground">Memory Used</span>
                <span class="text-foreground">{{ formatBytes(info.memory_used) }}</span>
            </div>
            <div v-if="info.connected_clients" class="flex justify-between">
                <span class="text-muted-foreground">Clients</span>
                <span class="text-foreground">{{ formatNumber(info.connected_clients) }}</span>
            </div>
            <div v-if="info.ops_per_sec" class="flex justify-between">
                <span class="text-muted-foreground">Ops/sec</span>
                <span class="text-foreground">{{ formatNumber(info.ops_per_sec) }}</span>
            </div>
        </template>

        <!-- SQS specific -->
        <template v-if="info.driver === 'sqs'">
            <div class="flex justify-between">
                <span class="text-muted-foreground">Visible</span>
                <span class="text-foreground">{{ formatNumber(info.visible) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">In Flight</span>
                <span class="text-foreground">{{ formatNumber(info.in_flight) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Delayed</span>
                <span class="text-foreground">{{ formatNumber(info.delayed) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Visibility Timeout</span>
                <span class="text-foreground">{{ info.visibility_timeout }}s</span>
            </div>
        </template>

        <!-- Beanstalkd specific -->
        <template v-if="info.driver === 'beanstalkd'">
            <div class="flex justify-between">
                <span class="text-muted-foreground">Ready</span>
                <span class="text-foreground">{{ formatNumber(info.ready) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Reserved</span>
                <span class="text-foreground">{{ formatNumber(info.reserved) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Delayed</span>
                <span class="text-foreground">{{ formatNumber(info.delayed) }}</span>
            </div>
            <div v-if="info.buried > 0" class="flex justify-between">
                <span class="text-muted-foreground">Buried</span>
                <span class="text-red-400 font-medium">{{ formatNumber(info.buried) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Total Jobs</span>
                <span class="text-foreground">{{ formatNumber(info.total_jobs) }}</span>
            </div>
        </template>

        <!-- Kafka specific -->
        <template v-if="info.driver === 'kafka'">
            <div class="flex justify-between">
                <span class="text-muted-foreground">Brokers</span>
                <span class="text-foreground">{{ formatNumber(info.brokers) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Partitions</span>
                <span class="text-foreground">{{ formatNumber(info.partitions) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted-foreground">Total Lag</span>
                <span :class="info.total_lag > 1000 ? 'text-amber-400 font-medium' : 'text-foreground'">
                    {{ formatNumber(info.total_lag) }}
                </span>
            </div>
            <div v-if="info.consumer_lag" class="mt-2">
                <p class="text-xs text-muted-foreground mb-1">Per-Partition Lag</p>
                <div class="space-y-1">
                    <div
                        v-for="(lag, partition) in info.consumer_lag"
                        :key="partition"
                        class="flex items-center gap-2"
                    >
                        <span class="text-xs text-muted-foreground w-20 truncate">{{ partition }}</span>
                        <div class="flex-1 h-1.5 rounded-full bg-secondary overflow-hidden">
                            <div
                                class="h-full rounded-full"
                                :class="lag > 1000 ? 'bg-amber-500' : 'bg-emerald-500'"
                                :style="{ width: Math.min(100, (lag / Math.max(info.total_lag, 1)) * 100) + '%' }"
                            ></div>
                        </div>
                        <span class="text-xs text-muted-foreground w-12 text-right">{{ lag }}</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Per-Queue Breakdown (always visible with stats, graphs collapsible) -->
        <template v-if="hasQueues">
            <div class="mt-3 pt-3 border-t border-border">
                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-2">Per-Queue Breakdown</h4>
                <div class="space-y-1">
                    <div
                        v-for="(qInfo, qName) in info.queues"
                        :key="qName"
                        class="flex items-center justify-between gap-2 py-1"
                    >
                        <span class="text-xs font-mono text-foreground truncate" :title="qName">{{ qName }}</span>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-xs text-muted-foreground tabular-nums">{{ formatNumber(qInfo.size) }} jobs</span>
                            <template v-if="info.driver === 'rabbitmq'">
                                <span v-if="qInfo.consumers > 0" class="text-[10px] text-muted-foreground tabular-nums">{{ qInfo.consumers }}C</span>
                            </template>
                            <template v-if="info.driver === 'beanstalkd'">
                                <span v-if="qInfo.buried > 0" class="text-[10px] text-red-400 tabular-nums">{{ qInfo.buried }}B</span>
                                <span v-if="qInfo.watchers > 0" class="text-[10px] text-muted-foreground tabular-nums">{{ qInfo.watchers }}W</span>
                            </template>
                            <template v-if="info.driver === 'redis'">
                                <span v-if="qInfo.delayed > 0" class="text-[10px] text-amber-400 tabular-nums">{{ qInfo.delayed }}D</span>
                                <span v-if="qInfo.reserved > 0" class="text-[10px] text-muted-foreground tabular-nums">{{ qInfo.reserved }}R</span>
                            </template>
                        </div>
                    </div>
                </div>
                <p v-if="hiddenQueuesCount > 0" class="mt-1.5 text-[10px] text-muted-foreground">
                    and {{ hiddenQueuesCount }} more {{ hiddenQueuesCount === 1 ? 'queue' : 'queues' }}
                </p>
            </div>
        </template>

        <!-- Performance Graphs -->
        <template v-if="hasTimeSeries || timeSeries === null">
            <div class="mt-3 pt-3 border-t border-border">
                <div v-if="hasTimeSeries" class="space-y-3">
                    <MetricsChart
                        title="Queue Depth"
                        :labels="queueDepthChart.labels"
                        :data="queueDepthChart.data"
                        color="#6366f1"
                        unit="jobs"
                    />
                    <MetricsChart
                        title="Throughput"
                        :labels="opsRateChart.labels"
                        :data="opsRateChart.data"
                        color="#10b981"
                        unit="ops/s"
                    />
                </div>
                <div v-else-if="timeSeries === null" class="space-y-3">
                    <div v-for="label in ['Queue Depth', 'Throughput']" :key="label" class="bg-card border border-border rounded-lg p-4 animate-pulse">
                        <div class="h-4 w-24 bg-secondary rounded mb-3"></div>
                        <div class="h-48 relative">
                            <div class="absolute left-0 inset-y-0 flex flex-col justify-between py-1">
                                <div class="h-2 w-6 bg-secondary rounded"></div>
                                <div class="h-2 w-8 bg-secondary rounded"></div>
                                <div class="h-2 w-5 bg-secondary rounded"></div>
                            </div>
                            <div class="ml-10 h-full flex flex-col justify-end">
                                <svg class="w-full h-full" viewBox="0 0 200 100" preserveAspectRatio="none">
                                    <path d="M0,70 Q25,65 50,60 T100,55 T150,45 T200,50" fill="none" stroke="currentColor" stroke-width="1.5" class="text-secondary" />
                                    <path d="M0,70 Q25,65 50,60 T100,55 T150,45 T200,50 V100 H0 Z" class="text-secondary/30" fill="currentColor" />
                                </svg>
                            </div>
                            <div class="absolute bottom-0 left-10 right-0 flex justify-between">
                                <div class="h-2 w-8 bg-secondary rounded"></div>
                                <div class="h-2 w-8 bg-secondary rounded"></div>
                                <div class="h-2 w-8 bg-secondary rounded"></div>
                                <div class="h-2 w-8 bg-secondary rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-xs text-muted-foreground bg-secondary/50 rounded p-2 text-center">
                    No metrics data yet - graphs will appear after ~1 minute of polling
                </div>
            </div>
        </template>
    </div>
</template>
