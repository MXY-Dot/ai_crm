<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Download, FileText, Loader2, Pencil, Save, X } from '@lucide/vue';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import type { KnowledgeDocumentDetail } from '../../../stores/crmDashboard';
import { Button } from '../../ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '../../ui/dialog';
import { Input } from '../../ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../../ui/tabs';
import { Textarea } from '../../ui/textarea';

// pdfjs-dist and mammoth are large (~1MB combined) — loaded on demand only when a
// document with a real PDF/DOCX file is actually opened, so every other page in the
// app doesn't pay for them in its initial bundle.
const PdfViewer = defineAsyncComponent(() => import('./PdfViewer.vue'));
const DocxViewer = defineAsyncComponent(() => import('./DocxViewer.vue'));

const props = defineProps<{ documentId: number | null }>();
const emit = defineEmits<{ (event: 'update:documentId', value: number | null): void }>();

const store = useCrmDashboardStore();
const open = computed({
    get: () => props.documentId !== null,
    set: (value) => { if (! value) emit('update:documentId', null); },
});

const loading = ref(false);
const editing = ref(false);
const document = ref<KnowledgeDocumentDetail | null>(null);
const titleDraft = ref('');
const contentDraft = ref('');
const activeTab = ref<'original' | 'text'>('original');

const joinedContent = computed(() => (document.value?.chunks ?? []).map((chunk) => chunk.content).join('\n\n'));

const hasStoredFile = computed(() => Boolean(document.value?.meta?.storage_path));
const fileKind = computed<'pdf' | 'docx' | null>(() => {
    const name = (document.value?.file_name ?? '').toLowerCase();
    const mime = (document.value?.mime_type ?? '').toLowerCase();

    if (mime.includes('pdf') || name.endsWith('.pdf')) return 'pdf';
    if (mime.includes('wordprocessingml') || name.endsWith('.docx')) return 'docx';

    return null;
});
const showOriginalTab = computed(() => hasStoredFile.value && fileKind.value !== null);
const fileUrl = computed(() => (document.value ? `/api/knowledge-documents/${document.value.id}/file` : ''));

watch(() => props.documentId, async (id) => {
    editing.value = false;
    document.value = null;
    activeTab.value = 'original';
    if (id === null) return;

    loading.value = true;
    try {
        document.value = await store.fetchKnowledgeDocument(id);
        activeTab.value = showOriginalTab.value ? 'original' : 'text';
    } finally {
        loading.value = false;
    }
});

function onViewerError(message: string): void {
    toast.error(message);
}

function startEdit(): void {
    if (! document.value) return;
    titleDraft.value = document.value.title;
    contentDraft.value = joinedContent.value;
    editing.value = true;
    activeTab.value = 'text';
}

function cancelEdit(): void {
    editing.value = false;
}

async function save(): Promise<void> {
    if (! document.value || ! contentDraft.value.trim()) return;

    await store.updateKnowledgeDocumentContent(document.value.id, {
        title: titleDraft.value.trim() || undefined,
        content: contentDraft.value.trim(),
    });
    document.value = await store.fetchKnowledgeDocument(document.value.id);
    editing.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[88vh] flex-col sm:max-w-[55.2rem]">
            <DialogHeader>
                <DialogTitle class="flex items-center justify-between gap-3 pr-6">
                    <span class="flex min-w-0 items-center gap-2">
                        <FileText class="h-4 w-4 shrink-0 text-primary" />
                        <span class="truncate">{{ document?.title ?? 'Документ' }}</span>
                    </span>
                    <a v-if="hasStoredFile" :href="fileUrl" target="_blank" rel="noreferrer" download class="shrink-0">
                        <Button type="button" variant="outline" size="sm"><Download class="h-3.5 w-3.5" />Скачать оригинал</Button>
                    </a>
                </DialogTitle>
            </DialogHeader>

            <div v-if="loading" class="flex flex-1 items-center justify-center py-10 ui-subtle">
                <Loader2 class="h-5 w-5 animate-spin" />
            </div>

            <template v-else-if="document">
                <Tabs v-if="showOriginalTab && ! editing" v-model="activeTab" class="flex min-h-0 flex-1 flex-col gap-3">
                    <TabsList>
                        <TabsTrigger value="original">Оригинал</TabsTrigger>
                        <TabsTrigger value="text">Текст для AI</TabsTrigger>
                    </TabsList>

                    <TabsContent value="original" class="mt-0 flex min-h-0 flex-1 flex-col gap-3">
                        <div class="flex shrink-0 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-xs border-border bg-muted/50 ui-subtle">
                            <span>Это только просмотр — здесь ничего нельзя изменить напрямую.</span>
                            <Button type="button" variant="outline" size="sm" class="shrink-0" @click="startEdit"><Pencil class="h-3.5 w-3.5" />Редактировать текст</Button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto rounded-lg border border-border bg-muted/30 p-4">
                            <PdfViewer v-if="fileKind === 'pdf'" :src="fileUrl" @error="onViewerError" />
                            <DocxViewer v-else-if="fileKind === 'docx'" :src="fileUrl" @error="onViewerError" />
                        </div>
                    </TabsContent>

                    <TabsContent value="text" class="mt-0 min-h-0 flex-1 overflow-x-hidden overflow-y-auto rounded-lg border p-4 text-sm leading-6 ui-text border-border bg-background/40">
                        <p v-if="joinedContent" class="whitespace-pre-line">{{ joinedContent }}</p>
                        <p v-else class="ui-subtle">Текст для AI пока пуст.</p>
                    </TabsContent>
                </Tabs>

                <form v-else-if="editing" class="flex min-h-0 flex-1 flex-col gap-3" @submit.prevent="save">
                    <label>
                        <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">Название</span>
                        <Input v-model="titleDraft" maxlength="180" required />
                    </label>
                    <label class="flex min-h-0 flex-1 flex-col">
                        <span class="mb-1 flex items-center justify-between text-xs font-semibold uppercase ui-subtle">
                            <span>Текст для AI</span>
                            <span v-if="hasStoredFile" class="normal-case font-normal text-[11px] ui-subtle">Меняет только то, что видит AI — исходный файл не изменится</span>
                        </span>
                        <Textarea v-model="contentDraft" class="min-h-64 flex-1 font-mono text-sm" required />
                    </label>
                </form>

                <div v-else class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto rounded-lg border p-4 text-sm leading-6 ui-text border-border bg-background/40">
                    <p v-if="joinedContent" class="whitespace-pre-line">{{ joinedContent }}</p>
                    <p v-else class="ui-subtle">
                        Содержимое пока пусто — этот файл ещё не распознан автоматически. Нажмите «Редактировать» и вставьте текст вручную, чтобы AI мог его использовать.
                    </p>
                </div>

                <DialogFooter class="shrink-0">
                    <template v-if="editing">
                        <Button type="button" variant="outline" @click="cancelEdit"><X class="h-4 w-4" />Отмена</Button>
                        <Button type="button" variant="primary" :disabled="store.busy || !contentDraft.trim()" @click="save">
                            <Save class="h-4 w-4" />{{ store.busy ? 'Сохранение…' : 'Сохранить' }}
                        </Button>
                    </template>
                    <Button v-else type="button" variant="primary" @click="startEdit"><Pencil class="h-4 w-4" />Редактировать текст для AI</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
