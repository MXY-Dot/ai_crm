export const conversationStatusLabels: Record<string, string> = {
    open: 'Открыт',
    pending: 'Ожидает',
    pending_operator: 'Ждёт оператора',
    closed: 'Закрыт',
};

export const conversationStatusTone: Record<string, 'green' | 'blue' | 'amber' | 'neutral'> = {
    open: 'green',
    pending: 'blue',
    pending_operator: 'amber',
    closed: 'neutral',
};

export const priorityLabels: Record<string, string> = {
    low: 'Низкий',
    normal: 'Обычный',
    medium: 'Средний',
    high: 'Высокий',
    urgent: 'Срочный',
};

export const knowledgeStatusLabels: Record<string, string> = {
    indexed: 'Проиндексирован',
    queued: 'В очереди',
    failed: 'Ошибка',
};

export const channelHealthLabels: Record<string, string> = {
    connected: 'Подключён',
    active: 'Активен',
    pending: 'Ожидает настройки',
    error: 'Ошибка',
    draft: 'Черновик',
};

export const sourceLabels: Record<string, string> = {
    telegram: 'Telegram',
    whatsapp: 'WhatsApp',
    website: 'Сайт',
    web: 'Сайт',
    chatwoot: 'Единый инбокс',
    instagram: 'Instagram',
    facebook: 'Facebook',
    manual: 'Вручную',
};

export const aiIntentLabels: Record<string, string> = {
    booking_request: 'Запрос на запись',
    pricing_request: 'Вопрос о цене',
    payment_policy: 'Вопрос по оплате',
    complaint: 'Жалоба',
    human_request: 'Запрос оператора',
    general_question: 'Общий вопрос',
};

export const aiNextActionLabels: Record<string, string> = {
    handoff_operator: 'передать оператору',
    suggest_slots: 'предложить время записи',
    send_offer: 'отправить предложение',
    draft_reply: 'подготовить черновик ответа',
};

export function label(map: Record<string, string>, value: string | null | undefined, fallback?: string): string {
    if (! value) return fallback ?? '—';
    return map[value] ?? fallback ?? value;
}
