<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '../ui/alert';
import { useLocaleStore } from '../../stores/locale';

const locale = useLocaleStore();
</script>

<template>
    <Alert>
        <AlertTriangle />
        <AlertTitle>{{ locale.t('empty.title') }}</AlertTitle>
        <AlertDescription>{{ locale.t('empty.text') }}</AlertDescription>
    </Alert>
</template>
