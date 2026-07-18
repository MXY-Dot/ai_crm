import { computed, ref } from 'vue';
import { defineStore } from 'pinia';

export type ThemeMode = 'light' | 'dark';

const saved = localStorage.getItem('gravity_theme') as ThemeMode | null;
const preferred: ThemeMode = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';

function applyTheme(mode: ThemeMode): void {
    document.documentElement.dataset.theme = mode;
    document.documentElement.classList.toggle('dark', mode === 'dark');
    document.documentElement.style.colorScheme = mode;
}

export const useThemeStore = defineStore('theme', () => {
    const mode = ref<ThemeMode>(saved === 'light' || saved === 'dark' ? saved : preferred);
    const isDark = computed(() => mode.value === 'dark');

    function setTheme(next: ThemeMode): void {
        mode.value = next;
        localStorage.setItem('gravity_theme', next);
        applyTheme(next);
    }

    function toggle(): void {
        setTheme(isDark.value ? 'light' : 'dark');
    }

    applyTheme(mode.value);

    return { mode, isDark, setTheme, toggle };
});