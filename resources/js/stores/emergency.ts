import { ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../lib/apiClient';
import { useCrmDashboardStore } from './crmDashboard';

/**
 * Tenant-wide "is AI down" flag (ЭТАП 16.3/16.6), polled independently of the
 * chat store so the red banner (see EmergencyBanner.vue) shows on every page,
 * not just /inbox — same lifecycle pattern as useUnreadStore (start() once from
 * AppLayout.vue, stop() on unmount).
 */
export type EmergencyStatus = {
    mode: 'normal' | 'emergency';
    reason: string | null;
    since: string | null;
    manual_override: boolean;
    incident_id: number | null;
};

export const useEmergencyStore = defineStore('emergency', () => {
    const dashboard = useCrmDashboardStore();
    const mode = ref<'normal' | 'emergency'>('normal');
    const reason = ref<string | null>(null);
    const since = ref<string | null>(null);
    const manualOverride = ref(false);
    const incidentId = ref<number | null>(null);

    let pollTimer: number | null = null;

    async function refresh(): Promise<void> {
        const slug = dashboard.tenant?.slug;
        if (! slug) return;

        try {
            const status = await apiRequest<EmergencyStatus>('/api/emergency/status', { tenant: slug });
            mode.value = status.mode;
            reason.value = status.reason;
            since.value = status.since;
            manualOverride.value = status.manual_override;
            incidentId.value = status.incident_id;
        } catch {
            // Silent — next poll retries; a stale banner state for a few seconds isn't worth a toast.
        }
    }

    function start(): void {
        if (pollTimer !== null) return;

        refresh();
        pollTimer = window.setInterval(() => {
            if (! document.hidden) refresh();
        }, 20000);
    }

    function stop(): void {
        if (pollTimer !== null) window.clearInterval(pollTimer);
        pollTimer = null;
    }

    return { mode, reason, since, manualOverride, incidentId, refresh, start, stop };
});
