<script setup lang="ts">
import { useTeamChatStore } from '../../stores/teamChat';
import TeamChatSidebar from './teamchat/TeamChatSidebar.vue';
import TeamChatThread from './teamchat/TeamChatThread.vue';

const team = useTeamChatStore();
</script>

<template>
    <section class="flex h-[calc(100vh-200px)] min-h-[620px] overflow-hidden rounded-xl border border-border bg-card">
        <div class="w-full shrink-0 lg:w-80" :class="team.activeUserId ? 'hidden lg:flex' : 'flex'">
            <TeamChatSidebar class="w-full" />
        </div>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col" :class="team.activeUserId ? 'flex' : 'hidden lg:flex'">
            <TeamChatThread @back="team.unselectThread" />
        </div>
    </section>
</template>
