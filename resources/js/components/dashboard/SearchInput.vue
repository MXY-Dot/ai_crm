<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string; class?: string }>(), {
    placeholder: '',
    class: 'w-64',
});
defineEmits<{ 'update:modelValue': [string] }>();
</script>

<template>
    <div class="relative">
        <Search class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 ui-subtle" />
        <Input
            :model-value="modelValue"
            class="h-9 pl-9 lg:pl-9"
            :class="props.class"
            :placeholder="placeholder"
            @update:model-value="$emit('update:modelValue', String($event))"
        />
    </div>
</template>
