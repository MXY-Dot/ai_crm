/** Feature flag for the @advanced-chat/components-based inbox — see InboxPage.vue. Default off: existing hand-built chat UI stays the shipped experience until this is explicitly turned on. */
export const advancedChatEnabled = import.meta.env.VITE_ADVANCED_CHAT_ENABLED === 'true';
