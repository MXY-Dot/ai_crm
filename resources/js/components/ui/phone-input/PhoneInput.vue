<script setup lang="ts">
import { ref, watch } from 'vue';
import { Input } from '../input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../select';

const COUNTRIES = [
    { iso: 'RU', code: '+7' },
    { iso: 'KZ', code: '+7' },
    { iso: 'UA', code: '+380' },
    { iso: 'BY', code: '+375' },
    { iso: 'UZ', code: '+998' },
    { iso: 'AM', code: '+374' },
    { iso: 'AZ', code: '+994' },
    { iso: 'GE', code: '+995' },
    { iso: 'KG', code: '+996' },
    { iso: 'TJ', code: '+992' },
    { iso: 'TM', code: '+993' },
    { iso: 'US', code: '+1' },
    { iso: 'GB', code: '+44' },
    { iso: 'DE', code: '+49' },
    { iso: 'AE', code: '+971' },
    { iso: 'TR', code: '+90' },
] as const;
const CODES_BY_LENGTH = [...COUNTRIES].sort((a, b) => b.code.length - a.code.length);

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), {
    placeholder: '',
});
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

function codeFor(iso: string): string {
    return COUNTRIES.find((country) => country.iso === iso)?.code ?? '+7';
}

function parseValue(value: string): { iso: string; local: string } {
    const trimmed = (value ?? '').trim();
    const match = trimmed ? CODES_BY_LENGTH.find((country) => trimmed.startsWith(country.code)) : null;

    return match
        ? { iso: match.iso, local: trimmed.slice(match.code.length).trim() }
        : { iso: 'RU', local: trimmed };
}

const initial = parseValue(props.modelValue);
const selectedIso = ref(initial.iso);
const localValue = ref(initial.local);
let lastEmitted = props.modelValue;

watch(() => props.modelValue, (value) => {
    if (value === lastEmitted) return;

    const parsed = parseValue(value);
    selectedIso.value = parsed.iso;
    localValue.value = parsed.local;
});

function emitCombined(): void {
    const combined = localValue.value ? `${codeFor(selectedIso.value)} ${localValue.value}`.trim() : codeFor(selectedIso.value);
    lastEmitted = combined;
    emit('update:modelValue', combined);
}

function onIsoChange(value: string): void {
    selectedIso.value = value;
    emitCombined();
}

function onLocalInput(value: string | number): void {
    localValue.value = String(value);
    emitCombined();
}
</script>

<template>
    <div class="flex gap-2">
        <Select :model-value="selectedIso" @update:model-value="(value) => onIsoChange(value as string)">
            <SelectTrigger class="w-24 shrink-0"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem v-for="country in COUNTRIES" :key="country.iso" :value="country.iso">{{ country.iso }} {{ country.code }}</SelectItem>
            </SelectContent>
        </Select>
        <Input :model-value="localValue" type="tel" class="flex-1" :placeholder="placeholder" @update:model-value="onLocalInput" />
    </div>
</template>
