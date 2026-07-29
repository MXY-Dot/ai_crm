<script setup lang="ts">
import LoginScreen from '../components/auth/LoginScreen.vue';

defineProps<{ plan: string }>();
</script>

<template>
    <LoginScreen mode="register" :plan="plan" />
</template>