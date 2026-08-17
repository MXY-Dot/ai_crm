<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
</script>

<template>
    <div class="flex items-center gap-3 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
        <AlertTriangle class="size-4 shrink-0" />
        <span>
            AI временно недоступен. Новые обращения необходимо обрабатывать вручную — команда уже уведомлена.
        </span>
    </div>
</template>
