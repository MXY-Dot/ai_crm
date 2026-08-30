<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { Globe2 } from '@lucide/vue';
import { apiRequest } from '@/lib/apiClient';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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

type FacebookPage = { id: string; name: string };
const pagePickerOpen = ref(false);
const facebookPages = ref<FacebookPage[]>([]);
const selectingPage = ref(false);

const OAUTH_STATUS_MESSAGES: Record<string, { tone: 'success' | 'error'; text: string }> = {
    connected: { tone: 'success', text: 'Подключено' },
    denied: { tone: 'error', text: 'Доступ не предоставлен' },
    state_mismatch: { tone: 'error', text: 'Сессия подключения истекла, попробуйте ещё раз' },
    exchange_failed: { tone: 'error', text: 'Meta не подтвердила подключение — попробуйте ещё раз' },
    no_pages: { tone: 'error', text: 'У этого аккаунта Facebook нет ни одной подключённой страницы' },
    already_connected: { tone: 'error', text: 'Этот аккаунт уже подключён к другой компании на платформе' },
};

/**
 * Handles the redirect back from MetaOAuthController's Facebook/Instagram
 * callbacks (?meta_oauth=facebook&status=connected, etc.) — shows a toast,
 * refreshes settings so the newly-connected channel shows up, and for
 * Facebook's multi-page case opens a picker instead (the callback couldn't
 * know which page to save on its own).
 */
async function handleOauthReturn(): Promise<void> {
    const params = new URLSearchParams(window.location.search);
    const provider = params.get('meta_oauth');
    const status = params.get('status');
    if (! provider || ! status) return;

    window.history.replaceState(null, '', window.location.pathname);

    if (provider === 'facebook' && status === 'select_page') {
        await openFacebookPagePicker();
        return;
    }

    const message = OAUTH_STATUS_MESSAGES[status];
    if (message) {
        const label = provider === 'facebook' ? 'Facebook' : 'Instagram';
        if (message.tone === 'success') toast.success(`${label}: ${message.text}`);
        else toast.error(`${label}: ${message.text}`);
    }

    if (status === 'connected') {
        await Promise.all([store.loadIntegrationSettings(), store.refreshDashboard()]);
    }
}

async function openFacebookPagePicker(): Promise<void> {
    try {
        const result = await apiRequest<{ pages: FacebookPage[] }>('/api/oauth/facebook/pages');
        facebookPages.value = result.pages;
        pagePickerOpen.value = true;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить список страниц');
    }
}

async function selectFacebookPage(pageId: string): Promise<void> {
    selectingPage.value = true;
    try {
        await apiRequest('/api/oauth/facebook/select-page', { method: 'POST', body: { page_id: pageId } });
        pagePickerOpen.value = false;
        toast.success('Facebook: Подключено');
        await Promise.all([store.loadIntegrationSettings(), store.refreshDashboard()]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось подключить страницу');
    } finally {
        selectingPage.value = false;
    }
}

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
    await handleOauthReturn();
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

    <Dialog v-model:open="pagePickerOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Выберите страницу Facebook</DialogTitle>
            </DialogHeader>
            <div class="grid gap-2 py-2">
                <Button
                    v-for="page in facebookPages"
                    :key="page.id"
                    variant="outline"
                    class="justify-start"
                    :disabled="selectingPage"
                    @click="selectFacebookPage(page.id)"
                >
                    {{ page.name }}
                </Button>
                <p v-if="! facebookPages.length" class="text-sm ui-subtle">Страницы не найдены.</p>
            </div>
            <DialogFooter>
                <Button variant="ghost" type="button" @click="pagePickerOpen = false">Отмена</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
