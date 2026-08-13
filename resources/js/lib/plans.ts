export type PlanId = 'starter' | 'pro' | 'business';

export type AiProvider = 'gemini' | 'deepseek' | 'claude' | 'gpt' | 'groq';

export type Plan = {
    id: PlanId;
    name: string;
    price: string;
    conversationsLimit: number | null;
    aiAgentsLimit: number | null;
    /** Monthly cap on direct-LLM calls (mirrors backend AiWorkflow::PLAN_AI_USAGE_LIMITS). null = unlimited. */
    aiUsageLimit: number | null;
    aiProviders: AiProvider[];
    features: string[];
};

export const plans: Plan[] = [
    {
        id: 'starter',
        name: 'Starter',
        price: '$29/мес',
        conversationsLimit: 500,
        aiAgentsLimit: 1,
        aiUsageLimit: 1000,
        aiProviders: ['gemini', 'deepseek', 'groq'],
        features: ['1 AI-ассистент', '500 диалогов в месяц', 'Модели Gemini, DeepSeek и Groq', 'Email поддержка'],
    },
    {
        id: 'pro',
        name: 'Pro',
        price: '$99/мес',
        conversationsLimit: null,
        aiAgentsLimit: 5,
        aiUsageLimit: 5000,
        aiProviders: ['gemini', 'deepseek', 'groq', 'claude'],
        features: ['5 AI-ассистентов', 'Безлимитные диалоги', 'Модель Claude + всё из Starter', 'Приоритетная поддержка'],
    },
    {
        id: 'business',
        name: 'Business',
        price: '$249/мес',
        conversationsLimit: null,
        aiAgentsLimit: null,
        aiUsageLimit: null,
        aiProviders: ['gemini', 'deepseek', 'groq', 'claude', 'gpt'],
        features: ['Безлимитные AI-ассистенты', 'Безлимитные диалоги', 'Модель GPT + всё из Pro', 'Журнал аудита', 'Выделенная поддержка'],
    },
];

export function planById(id: string | undefined): Plan {
    return plans.find((plan) => plan.id === id) ?? plans[0];
}

export function providerForModel(model: string): AiProvider | null {
    if (model.startsWith('gpt-') || model.startsWith('o1-') || model.startsWith('o3-')) return 'gpt';
    if (model.startsWith('deepseek-')) return 'deepseek';
    if (model.startsWith('claude-')) return 'claude';
    if (model.startsWith('gemini-')) return 'gemini';
    if (model.includes('gpt-oss') || model.startsWith('llama-') || model.startsWith('llama3') || model.startsWith('gemma') || model.startsWith('mixtral')) return 'groq';

    return null;
}
