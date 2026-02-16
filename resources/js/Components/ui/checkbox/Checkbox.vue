<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    disabled: Boolean,
    id: String,
})

const emit = defineEmits(['update:modelValue'])

const toggle = () => {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue)
    }
}
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="modelValue"
        :disabled="disabled"
        :id="id"
        :class="[
            'peer h-4 w-4 shrink-0 rounded-sm border border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
            modelValue ? 'bg-primary text-primary-foreground' : 'bg-transparent',
            $attrs.class,
        ]"
        @click="toggle"
    >
        <svg
            v-if="modelValue"
            class="h-4 w-4"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <polyline points="20 6 9 17 4 12" />
        </svg>
    </button>
</template>
