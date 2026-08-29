<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { Globe2 } from '@lucide/vue';
import ChannelCard from '../components/dashboard/channels/ChannelCard.vue';
import FacebookChannelSettings from '../components/dashboard/channels/FacebookChannelSettings.vue';
import InstagramChannelSettings from '../components/dashboard/channels/InstagramChannelSettings.vue';
import TelegramChannelSettings from '../components/dashboard/channels/TelegramChannelSettings.vue';
import WhatsappChannelSettings from '../components/dashboard/channels/WhatsappChannelSettings.vue';
import WidgetChannelSettings from '../components/dashboard/channels/WidgetChannelSettings.vue';
import FacebookIcon from '../components/icons/FacebookIcon.vue';
import InstagramIcon from '../components/icons/InstagramIcon.vue';
import TelegramIcon from '../components/icons/TelegramIcon.vue';
import WhatsappIcon from '../components/icons/WhatsappIcon.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { channels, integrationSettings, busy } = storeToRefs(store);

const telegramChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('telegram')) ?? null);
const whatsappChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('whatsapp')) ?? null);
const websiteChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('website')) ?? null);
const instagramChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('instagram')) ?? null);
const facebookChannel = computed(() => channels.value.find((item) => item.provider.toLowerCase().includes('facebook')) ?? null);

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
            <ChannelCard :icon="TelegramIcon" name="Telegram" brand="telegram" :status="telegramChannel?.status" :health-status="telegramChannel?.health_status" :last-synced-at="telegramChannel?.last_synced_at" external-url="https://t.me/BotFather">
                <TelegramChannelSettings :settings="integrationSettings?.telegram ?? null" :busy="busy" />
            </ChannelCard>

            <ChannelCard :icon="WhatsappIcon" name="WhatsApp" brand="whatsapp" :status="whatsappChannel?.status" :last-synced-at="whatsappChannel?.last_synced_at" external-url="https://developers.facebook.com/apps/">
                <WhatsappChannelSettings :settings="integrationSettings?.whatsapp ?? null" :busy="busy" />
            </ChannelCard>

            <ChannelCard :icon="InstagramIcon" name="Instagram" brand="instagram" :status="instagramChannel?.status" :last-synced-at="instagramChannel?.last_synced_at" external-url="https://developers.facebook.com/apps/">
                <InstagramChannelSettings :settings="integrationSettings?.instagram ?? null" :busy="busy" />
            </ChannelCard>

            <ChannelCard :icon="FacebookIcon" name="Facebook" brand="facebook" :status="facebookChannel?.status" :last-synced-at="facebookChannel?.last_synced_at" external-url="https://developers.facebook.com/apps/">
                <FacebookChannelSettings :settings="integrationSettings?.facebook ?? null" :busy="busy" />
            </ChannelCard>

            <ChannelCard :icon="Globe2" name="Виджет на сайт" brand="blue" :status="websiteChannel?.status" :last-synced-at="websiteChannel?.last_synced_at">
                <WidgetChannelSettings />
            </ChannelCard>
        </div>
    </section>
</template>
