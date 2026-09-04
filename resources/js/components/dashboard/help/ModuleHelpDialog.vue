<script setup lang="ts">
import { computed } from 'vue';
import { HelpCircle } from '@lucide/vue';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogHeader, DialogScrollContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { HELP_CONTENT } from '@/lib/help/content';

// One "?" button per module, dropped into that module's own header --
// content lives in lib/help/content.ts, keyed by moduleKey, so adding a new
// module's help is just a registry entry + this one-line component drop-in.
// Renders nothing when a module has no entry yet, so rollout can happen
// module by module without a broken button anywhere.
const props = defineProps<{ moduleKey: string }>();
const content = computed(() => HELP_CONTENT[props.moduleKey] ?? null);
</script>

<template>
    <Dialog v-if="content">
        <DialogTrigger as-child>
            <Button variant="outline" size="icon" :aria-label="`Помощь: ${content.title}`" :title="`Помощь: ${content.title}`">
                <HelpCircle class="h-4 w-4" />
            </Button>
        </DialogTrigger>
        <DialogScrollContent class="max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ content.title }}</DialogTitle>
                <DialogDescription>{{ content.subtitle }}</DialogDescription>
            </DialogHeader>
            <Accordion type="single" collapsible :default-value="content.sections[0]?.question" class="w-full">
                <AccordionItem v-for="section in content.sections" :key="section.question" :value="section.question" class="border-b border-border last:border-b-0">
                    <AccordionTrigger class="py-3 text-left text-sm font-semibold ui-text">{{ section.question }}</AccordionTrigger>
                    <AccordionContent class="space-y-2 pb-3 text-sm leading-relaxed ui-subtle">
                        <p v-for="(paragraph, i) in section.answer" :key="i">{{ paragraph }}</p>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </DialogScrollContent>
    </Dialog>
</template>
