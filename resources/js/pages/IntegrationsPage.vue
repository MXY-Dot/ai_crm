<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { Globe2, ShieldAlert } from '@lucide/vue';
import ChannelCard from '../components/dashboard/channels/ChannelCard.vue';
import ChatwootRoutedChannelInfo from '../components/dashboard/channels/ChatwootRoutedChannelInfo.vue';
import EmergencyModeCard from '../components/dashboard/channels/EmergencyModeCard.vue';
import TelegramChannelSettings from '../components/dashboard/channels/TelegramChannelSettings.vue';
import WidgetChannelSettings from '../components/dashboard/channels/WidgetChannelSettings.vue';
import InstagramIcon from '../components/icons/InstagramIcon.vue';
import TelegramIcon from '../components/icons/TelegramIcon.vue';
import WhatsappIcon from '../components/icons/WhatsappIcon.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import { useEmergencyStore } from '../stores/emergency';

const store = useCrmDashboardStore();
const emergency = useEmergencyStore();
const { channels, integrationSettings, busy } = storeToRefs(store);

const telegramChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('telegram')) ?? null);
const whatsappChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('whatsapp')) ?? null);
const websiteChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('website')) ?? null);
const instagramChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('instagram')) ?? null);

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Каналы связи</h2>
            <p class="mt-2 text-sm ui-subtle">Управляйте подключениями к мессенджерам и виджету на сайте.</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4" data-tour="channels-grid">
            <ChannelCard :icon="TelegramIcon" name="Telegram" brand="telegram" :status="telegramChannel?.status" :last-synced-at="telegramChannel?.last_synced_at">
                <TelegramChannelSettings :settings="integrationSettings?.telegram ?? null" :busy="busy" />
            </ChannelCard>

            <ChannelCard :icon="WhatsappIcon" name="WhatsApp" brand="whatsapp" :status="whatsappChannel?.status" :last-synced-at="whatsappChannel?.last_synced_at">
                <ChatwootRoutedChannelInfo
                    :chatwoot="integrationSettings?.chatwoot ?? null"
                    description="WhatsApp подключается через единый инбокс. Добавьте этот вебхук в настройках вашего WhatsApp Business канала."
                />
            </ChannelCard>

            <ChannelCard :icon="InstagramIcon" name="Instagram" brand="instagram" :status="instagramChannel?.status" :last-synced-at="instagramChannel?.last_synced_at">
                <ChatwootRoutedChannelInfo
                    :chatwoot="integrationSettings?.chatwoot ?? null"
                    description="Instagram Direct тоже маршрутизируется через единый инбокс. Используйте тот же вебхук при подключении аккаунта."
                />
            </ChannelCard>

            <ChannelCard :icon="Globe2" name="Виджет на сайт" brand="blue" :status="websiteChannel?.status" :last-synced-at="websiteChannel?.last_synced_at">
                <WidgetChannelSettings />
            </ChannelCard>
        </div>

        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Аварийный режим</h2>
            <p class="mt-2 text-sm ui-subtle">Что видит клиент и кто получает уведомление, если AI перестаёт отвечать.</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <ChannelCard
                :icon="ShieldAlert"
                name="Аварийный режим"
                brand="blue"
                :status="emergency.mode === 'emergency' ? 'error' : 'connected'"
            >
                <EmergencyModeCard />
            </ChannelCard>
        </div>
    </section>
</template>
