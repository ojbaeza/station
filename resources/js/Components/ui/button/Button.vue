<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'destructive', 'outline', 'secondary', 'ghost', 'link'].includes(v),
    },
    size: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'sm', 'lg', 'icon'].includes(v),
    },
    as: {
        type: String,
        default: 'button',
    },
    disabled: Boolean,
})

const variantClasses = {
    default: 'bg-primary text-primary-foreground hover:bg-primary/90',
    destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
    outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    link: 'text-primary underline-offset-4 hover:underline',
}

const sizeClasses = {
    default: 'h-10 px-4 py-2',
    sm: 'h-9 rounded-md px-3',
    lg: 'h-11 rounded-md px-8',
    icon: 'h-10 w-10',
}
</script>

<template>
    <component
        :is="as"
        :class="[
            'inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
            variantClasses[variant],
            sizeClasses[size],
            $attrs.class,
        ]"
        :disabled="disabled"
    >
        <slot />
    </component>
</template>
