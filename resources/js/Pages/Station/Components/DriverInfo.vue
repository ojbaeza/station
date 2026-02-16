<script setup>
import { Badge } from '@/Components/ui/badge'

defineProps({
    driverInfo: {
        type: Object,
        default: () => ({}),
    },
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
</script>

<template>
    <div class="space-y-4">
        <h3 class="text-lg font-semibold text-foreground">Driver Details</h3>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div
                v-for="(info, connection) in driverInfo"
                :key="connection"
                class="bg-card border border-border rounded-lg p-4"
            >
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h4 class="text-sm font-medium text-foreground">{{ connection }}</h4>
                        <p class="text-xs text-muted-foreground">{{ driverLabels[info.driver] || info.driver }}</p>
                    </div>
                    <Badge v-if="info.error" variant="destructive">Error</Badge>
                    <Badge v-else variant="default">Connected</Badge>
                </div>

                <div v-if="info.error" class="text-sm text-red-400">{{ info.error }}</div>

                <!-- Common stats -->
                <div v-else class="space-y-2 text-sm">
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
                </div>
            </div>
        </div>

        <div v-if="Object.keys(driverInfo).length === 0" class="text-center text-muted-foreground py-8">
            No driver connections configured
        </div>
    </div>
</template>
