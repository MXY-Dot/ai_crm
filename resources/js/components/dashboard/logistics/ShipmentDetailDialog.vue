<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Badge } from '../../ui/badge';
import { Button } from '../../ui/button';
import { CodeBlock } from '../../ui/code-block';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Money } from '../../ui/money';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Skeleton } from '../../ui/skeleton';

type TrackingEvent = { id: number; status: string; location: string | null; description: string | null; changed_by: { name: string } | null; created_at: string };
type ShipmentDetail = {
    id: number; tracking_number: string; status: string; service_type: string;
    sender_name: string; sender_phone: string; recipient_name: string; recipient_phone: string;
    origin_address: string | null; destination_address: string | null;
    weight_kg: number | null; price: number | null; notes: string | null;
    tracking_events: TrackingEvent[];
};

const STATUS_OPTIONS = ['in_transit', 'out_for_delivery', 'delivered', 'returned', 'cancelled'];

const props = defineProps<{ open: boolean; shipmentId: number | null; tenantSlug: string }>();
const emit = defineEmits<{ 'update:open': [boolean]; changed: [] }>();
const locale = useLocaleStore();

const shipment = ref<ShipmentDetail | null>(null);
const loading = ref(true);
const busy = ref(false);
const newStatus = ref('in_transit');
const eventLocation = ref('');
const eventDescription = ref('');

async function load(): Promise<void> {
    if (! props.shipmentId) return;
    loading.value = true;
    try {
        shipment.value = await apiRequest<ShipmentDetail>(`/api/shipments/${props.shipmentId}`, { tenant: props.tenantSlug });
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

watch(() => [props.open, props.shipmentId], () => { if (props.open) load(); });

const isActive = computed(() => shipment.value && ['received', 'in_transit', 'out_for_delivery'].includes(shipment.value.status));

async function addEvent(): Promise<void> {
    if (! shipment.value) return;
    busy.value = true;
    try {
        await apiRequest(`/api/shipments/${shipment.value.id}/status`, {
            method: 'PATCH',
            body: { status: newStatus.value, location: eventLocation.value || null, description: eventDescription.value || null },
            tenant: props.tenantSlug,
        });
        toast.success(locale.t('logistics.saved'));
        eventLocation.value = '';
        eventDescription.value = '';
        emit('changed');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        busy.value = false;
    }
}

const STATUS_TONE: Record<string, 'neutral' | 'green' | 'amber' | 'red' | 'blue'> = {
    received: 'amber', in_transit: 'blue', out_for_delivery: 'blue', delivered: 'green', returned: 'red', cancelled: 'red',
};

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => $emit('update:open', v)">
        <DialogContent class="sm:max-w-[36.8rem]">
            <DialogHeader>
                <DialogTitle>{{ locale.t('logistics.shipmentDetails') }}</DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="space-y-2 py-4">
                <Skeleton v-for="i in 5" :key="i" class="h-10 rounded-lg" />
            </div>
            <div v-else-if="shipment" class="grid max-h-[75vh] gap-5 overflow-y-auto py-4 text-sm">
                <section class="grid gap-1">
                    <CodeBlock :code="shipment.tracking_number" :label="locale.t('logistics.trackingNumber')" />
                    <p class="mt-2 ui-text"><span class="ui-subtle">{{ locale.t('logistics.sender') }}:</span> {{ shipment.sender_name }} · {{ shipment.sender_phone }}<span v-if="shipment.origin_address"> · {{ shipment.origin_address }}</span></p>
                    <p class="ui-text"><span class="ui-subtle">{{ locale.t('logistics.recipient') }}:</span> {{ shipment.recipient_name }} · {{ shipment.recipient_phone }}<span v-if="shipment.destination_address"> · {{ shipment.destination_address }}</span></p>
                    <p class="ui-subtle">{{ locale.t('logistics.serviceTypes.' + shipment.service_type) }}<span v-if="shipment.weight_kg"> · {{ shipment.weight_kg }} {{ locale.t('logistics.weightUnit') }}</span><span v-if="shipment.price"> · <Money :value="shipment.price" tone="muted" /></span></p>
                    <p v-if="shipment.notes" class="ui-subtle">{{ shipment.notes }}</p>
                    <Badge :tone="STATUS_TONE[shipment.status] ?? 'neutral'">{{ locale.t('logistics.statuses.' + shipment.status) }}</Badge>
                </section>

                <section v-if="isActive" class="grid gap-2 rounded-lg border border-border p-3">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('logistics.addEvent') }}</p>
                    <Select v-model="newStatus">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ locale.t('logistics.statuses.' + s) }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input v-model="eventLocation" :placeholder="locale.t('logistics.eventLocation')" />
                    <Input v-model="eventDescription" :placeholder="locale.t('logistics.eventDescription')" />
                    <Button size="sm" class="w-fit" :disabled="busy" @click="addEvent">{{ locale.t('logistics.save') }}</Button>
                </section>

                <section v-if="shipment.tracking_events?.length" class="grid gap-1">
                    <p class="text-xs font-medium ui-subtle">{{ locale.t('logistics.timeline') }}</p>
                    <div class="grid gap-1 text-xs ui-subtle">
                        <p v-for="e in shipment.tracking_events" :key="e.id">
                            <span class="font-medium tabular-nums ui-text">{{ formatDateTime(e.created_at) }}</span> · {{ locale.t('logistics.statuses.' + e.status) }}<span v-if="e.location"> · {{ e.location }}</span><span v-if="e.description"> · {{ e.description }}</span><span v-if="e.changed_by"> · {{ e.changed_by.name }}</span>
                        </p>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
