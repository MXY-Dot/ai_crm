<script setup lang="ts">
import { computed } from 'vue';
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

const parsed = computed(() => {
    const trimmed = (props.modelValue ?? '').trim();
    const match = trimmed ? CODES_BY_LENGTH.find((country) => trimmed.startsWith(country.code)) : null;

    return match
        ? { iso: match.iso, local: trimmed.slice(match.code.length).trim() }
        : { iso: 'RU', local: trimmed };
});

function codeFor(iso: string): string {
    return COUNTRIES.find((country) => country.iso === iso)?.code ?? '+7';
}

function combine(iso: string, local: string): string {
    return local ? `${codeFor(iso)} ${local}`.trim() : '';
}

const isoModel = computed({
    get: () => parsed.value.iso,
    set: (value: string) => emit('update:modelValue', combine(value, parsed.value.local)),
});

const localModel = computed({
    get: () => parsed.value.local,
    set: (value: string) => emit('update:modelValue', combine(parsed.value.iso, value)),
});
</script>

<template>
    <div class="flex gap-2">
        <Select v-model="isoModel">
            <SelectTrigger class="w-24 shrink-0"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem v-for="country in COUNTRIES" :key="country.iso" :value="country.iso">{{ country.iso }} {{ country.code }}</SelectItem>
            </SelectContent>
        </Select>
        <Input v-model="localModel" type="tel" class="flex-1" :placeholder="placeholder" />
    </div>
</template>
