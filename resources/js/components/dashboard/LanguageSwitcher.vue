<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { Check, Languages } from '@lucide/vue';
import { useLocaleStore } from '../../stores/locale';
import type { Locale } from '../../i18n/messages';
import { Button } from '../ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '../ui/dropdown-menu';

const localeStore = useLocaleStore();
const { available, locale } = storeToRefs(localeStore);

function switchLocale(code: Locale): void {
    if (code === locale.value) return;

    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `/locale/${code}`;
    form.style.display = 'none';

    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = token;
    form.appendChild(csrf);

    const redirect = document.createElement('input');
    redirect.type = 'hidden';
    redirect.name = 'redirect';
    redirect.value = window.location.pathname + window.location.search;
    form.appendChild(redirect);

    document.body.appendChild(form);
    form.submit();
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button type="button" variant="outline" size="sm" data-tour="language">
                <Languages class="h-4 w-4" />
                {{ available.find((item) => item.code === locale)?.label }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="item in available"
                :key="item.code"
                @select="switchLocale(item.code as Locale)"
            >
                <Check class="h-4 w-4" :class="locale === item.code ? 'opacity-100' : 'opacity-0'" />
                {{ item.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
