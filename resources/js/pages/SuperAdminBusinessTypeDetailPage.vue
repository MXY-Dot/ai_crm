<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    BedDouble, CalendarClock, Car, Check, ChevronRight, GraduationCap,
    Package, PackageSearch, Plane, Plug, Save, ShoppingCart, Truck, Undo2, UtensilsCrossed, Wallet,
} from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';

defineOptions({ layout: SuperAdminLayout });

type BusinessType = { id: number; key: string; name: string; is_active: boolean; default_modules: string[] };
type Detail = { business_type: BusinessType; modules: Record<string, string> };

const businessTypeId = usePage<{ businessTypeId: number }>().props.businessTypeId;

const MODULE_ICONS: Record<string, typeof Package> = {
    catalog_products: Package,
    orders: ShoppingCart,
    returns: Undo2,
    delivery_tracking: Truck,
    booking_calendar: CalendarClock,
    prepayment: Wallet,
    table_reservations: UtensilsCrossed,
    room_booking: BedDouble,
    tour_bookings: Plane,
    shipment_tracking: PackageSearch,
    course_scheduling: GraduationCap,
    vehicle_service: Car,
    crm_erp_integration: Plug,
};

const loading = ref(true);
const saving = ref(false);
const businessType = ref<BusinessType | null>(null);
const modules = ref<Record<string, string>>({});
const selectedModules = ref<string[]>([]);
const isActive = ref(true);
const originalModules = ref<string[]>([]);
const originalActive = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        const data = await apiRequest<Detail>(`/api/admin/business-types/${businessTypeId}`);
        businessType.value = data.business_type;
        modules.value = data.modules;
        selectedModules.value = [...data.business_type.default_modules];
        isActive.value = data.business_type.is_active;
        originalModules.value = [...data.business_type.default_modules];
        originalActive.value = data.business_type.is_active;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить сферу бизнеса');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function toggleModule(key: string): void {
    const idx = selectedModules.value.indexOf(key);
    if (idx === -1) selectedModules.value.push(key);
    else selectedModules.value.splice(idx, 1);
}

const dirty = computed(() => {
    if (isActive.value !== originalActive.value) return true;
    if (selectedModules.value.length !== originalModules.value.length) return true;
    return [...selectedModules.value].sort().join(',') !== [...originalModules.value].sort().join(',');
});

async function save(): Promise<void> {
    if (! businessType.value) return;
    saving.value = true;
    try {
        await apiRequest(`/api/admin/business-types/${businessType.value.id}`, {
            method: 'PATCH',
            body: { default_modules: selectedModules.value, is_active: isActive.value },
        });
        originalModules.value = [...selectedModules.value];
        originalActive.value = isActive.value;
        businessType.value.is_active = isActive.value;
        businessType.value.default_modules = [...selectedModules.value];
        toast.success(`«${businessType.value.name}» сохранена`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось сохранить');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="flex items-center gap-2 text-sm ui-subtle">
        <a href="/super-admin/business-modules" class="transition hover:text-primary">Сферы и модули</a>
        <ChevronRight class="h-3.5 w-3.5" />
        <span class="font-medium ui-text">{{ businessType?.name ?? '…' }}</span>
    </div>

    <div v-if="loading" class="mt-4 space-y-6">
        <Skeleton class="h-24 w-full rounded-xl" />
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <Skeleton v-for="i in 8" :key="i" class="h-28 rounded-2xl" />
        </div>
    </div>

    <template v-else-if="businessType">
        <div class="mt-4 flex flex-col gap-4 rounded-xl border border-border bg-card p-5 md:flex-row md:items-center md:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-xl font-bold ui-text">{{ businessType.name }}</h2>
                    <Badge :tone="isActive ? 'green' : 'neutral'">{{ isActive ? 'Активна' : 'Отключена' }}</Badge>
                </div>
                <p class="mt-1 font-mono text-xs ui-subtle">{{ businessType.key }}</p>
            </div>
            <label class="flex items-center gap-2.5">
                <span class="text-sm ui-subtle">Сфера активна</span>
                <Switch :model-value="isActive" @update:model-value="(v) => (isActive = !!v)" />
            </label>
        </div>

        <div class="mt-5">
            <p class="mb-3 text-sm font-semibold ui-text">Модули по умолчанию для этой сферы</p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <button
                    v-for="(label, key) in modules" :key="key" type="button"
                    class="flex flex-col items-center gap-2.5 rounded-2xl border p-4 text-center transition"
                    :class="selectedModules.includes(key)
                        ? 'border-primary bg-primary/10'
                        : 'border-border bg-card hover:border-primary/30 hover:bg-muted'"
                    :aria-pressed="selectedModules.includes(key)"
                    @click="toggleModule(key)"
                >
                    <span
                        class="grid size-11 place-items-center rounded-full border transition"
                        :class="selectedModules.includes(key) ? 'border-primary bg-primary text-primary-foreground' : 'border-border ui-subtle'"
                    >
                        <component :is="MODULE_ICONS[key] ?? Package" class="h-5 w-5" />
                    </span>
                    <span class="text-xs font-medium leading-snug" :class="selectedModules.includes(key) ? 'text-primary' : 'ui-text'">{{ label }}</span>
                </button>
            </div>
        </div>

        <div class="sticky bottom-4 mt-6 flex items-center justify-between gap-3 rounded-xl border border-border bg-card p-4 shadow-lg">
            <span class="text-sm ui-subtle">Выбрано модулей: <strong class="ui-text">{{ selectedModules.length }}</strong></span>
            <Button variant="primary" :disabled="saving || ! dirty" @click="save">
                <Check v-if="! dirty" class="h-4 w-4" />
                <Save v-else class="h-4 w-4" />
                {{ saving ? 'Сохранение…' : dirty ? 'Сохранить изменения' : 'Сохранено' }}
            </Button>
        </div>
    </template>
</template>
