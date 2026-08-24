<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Clock3, LogOut } from '@lucide/vue';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';

defineProps<{ status: string }>();

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <main class="dark relative flex min-h-screen items-center justify-center overflow-hidden bg-zinc-950 px-4 py-10 text-zinc-100">
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-primary/20 blur-3xl" />
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:28px_28px]" />
        </div>

        <Card class="relative w-full max-w-md border-white/10 bg-zinc-900/70 text-center shadow-2xl shadow-black/40 backdrop-blur-xl">
            <div class="flex flex-col items-center gap-4 px-2 py-4">
                <span class="grid size-14 place-items-center rounded-full bg-primary/15 text-primary">
                    <Clock3 class="h-7 w-7" />
                </span>
                <div>
                    <h1 class="text-xl font-bold text-white">Заявка на рассмотрении</h1>
                    <p class="mt-2 text-sm leading-6 text-zinc-400">
                        Мы получили регистрацию вашей компании в WERO. Модератор проверит данные и свяжется с вами
                        по указанному телефону — после подтверждения рабочее пространство станет доступно.
                    </p>
                </div>
                <Button variant="outline" class="mt-2 border-white/10 text-zinc-100 hover:bg-white/5" @click="logout">
                    <LogOut class="h-4 w-4" /> Выйти
                </Button>
            </div>
        </Card>
    </main>
</template>
