<script setup>
import { Head } from '@inertiajs/vue3'
import StationLayout from './Layout.vue'
</script>

<template>
    <Head title="Audit Log - Station" />
    <StationLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Audit Log</h1>
                <p class="mt-1 text-sm text-muted-foreground">Track administrative actions and system events.</p>
            </div>

            <div class="bg-card rounded-xl border border-border shadow-xs p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-muted-foreground/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="mt-4 text-lg font-medium text-foreground">Coming Soon</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    The audit log feature is under development. It will provide a detailed log of all
                    administrative actions, configuration changes, and system events.
                </p>
            </div>
        </div>
    </StationLayout>
</template>
