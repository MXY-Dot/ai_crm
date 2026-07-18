import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { type Locale, messages } from '../i18n/messages';

const saved = localStorage.getItem('gravity_locale') as Locale | null;

function readPath(source: Record<string, unknown>, path: string): unknown {
    return path.split('.').reduce<unknown>((current, segment) => {
        return typeof current === 'object' && current !== null
            ? (current as Record<string, unknown>)[segment]
            : undefined;
    }, source);
}

export const useLocaleStore = defineStore('locale', () => {
    const locale = ref<Locale>(saved && saved in messages ? saved : 'ru');
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