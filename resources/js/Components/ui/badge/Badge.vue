<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'secondary', 'destructive', 'outline'].includes(v),
    },
})

const variantClasses = {
    default: 'border-transparent bg-primary text-primary-foreground hover:bg-primary/80',
    secondary: 'border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80',
    destructive: 'border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/80',
    outline: 'text-foreground',
}
</script>

<template>
    <span
        :class="[
            'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors',
            variantClasses[variant],
            $attrs.class,
        ]"
    >
        <slot />
    </span>
</template>
