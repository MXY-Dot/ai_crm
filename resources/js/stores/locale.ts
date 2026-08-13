import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { usePage } from '@inertiajs/vue3';
import { type Locale, messages } from '../i18n/messages';

function readPath(source: Record<string, unknown>, path: string): unknown {
    return path.split('.').reduce<unknown>((current, segment) => {
        return typeof current === 'object' && current !== null
            ? (current as Record<string, unknown>)[segment]
            : undefined;
    }, source);
}

function initialLocale(): Locale {
    const shared = usePage<{ locale?: string }>().props.locale;
    if (shared && shared in messages) return shared as Locale;

    const saved = localStorage.getItem('gravity_locale');
    return saved && saved in messages ? saved as Locale : 'ru';
}

export const useLocaleStore = defineStore('locale', () => {
    const locale = ref<Locale>(initialLocale());
    const available = computed(() => [
        { code: 'ru' as Locale, label: 'RU' },
        { code: 'en' as Locale, label: 'EN' },
    ]);

    function setLocale(next: Locale): void {
        locale.value = next;
        localStorage.setItem('gravity_locale', next);
        document.documentElement.lang = next;
    }

    function t(key: string): string {
        const value = readPath(messages[locale.value], key) ?? readPath(messages.ru, key);
        return typeof value === 'string' ? value : key;
    }

    document.documentElement.lang = locale.value;

    return { locale, available, setLocale, t };
});