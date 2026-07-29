<script setup lang="ts">
import LoginScreen from '../components/auth/LoginScreen.vue';

defineProps<{ login: { email: string; password: string }; plan: string }>();
</script>

<template>
    <LoginScreen mode="login" :login="login" :plan="plan" />
</template>