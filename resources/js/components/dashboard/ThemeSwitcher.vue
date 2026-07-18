<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useThemeStore } from '../../stores/theme';
import { Button } from '../ui/button';

const theme = useThemeStore();
const { isDark } = storeToRefs(theme);
</script>

<template>
    <Button size="icon" variant="secondary" :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'" @click="theme.toggle()">
        <Sun v-if="isDark" class="h-4 w-4" />
        <Moon v-else class="h-4 w-4" />
    </Button>
</template>