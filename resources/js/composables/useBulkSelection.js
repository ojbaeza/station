import { computed, ref } from 'vue'

export function useBulkSelection() {
    const selectedIds = ref(new Set())

    const toggleId = (id) => {
        if (selectedIds.value.has(id)) {
            selectedIds.value.delete(id)
        } else {
            selectedIds.value.add(id)
        }
        // Trigger reactivity
        selectedIds.value = new Set(selectedIds.value)
    }

    const toggleAll = (ids) => {
        if (ids.length === selectedIds.value.size && ids.every((id) => selectedIds.value.has(id))) {
            selectedIds.value = new Set()
        } else {
            selectedIds.value = new Set(ids)
        }
    }

    const clearSelection = () => {
        selectedIds.value = new Set()
    }

    const isSelected = (id) => selectedIds.value.has(id)

    const hasSelection = computed(() => selectedIds.value.size > 0)

    const selectedArray = computed(() => Array.from(selectedIds.value))

    return {
        selectedIds,
        toggleId,
        toggleAll,
        clearSelection,
        isSelected,
        hasSelection,
        selectedArray,
    }
}
