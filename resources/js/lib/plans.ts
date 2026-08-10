export type PlanId = 'starter' | 'pro' | 'business';

export type Plan = {
    id: PlanId;
    name: string;
    price: string;
    conversationsLimit: number | null;
    aiAgentsLimit: number | null;
    features: string[];
};

export const plans: Plan[] = [
    {
        id: 'starter',
        name: 'Starter',
        price: '$29/мес',
        conversationsLimit: 500,
        aiAgentsLimit: 1,
        features: ['1 AI-ассистент', '500 диалогов в месяц', 'Email поддержка'],
    },
    {
        id: 'pro',
        name: 'Pro',
        price: '$99/мес',
        conversationsLimit: null,
        aiAgentsLimit: 5,
        features: ['5 AI-ассистентов', 'Безлимитные диалоги', 'Приоритетная поддержка'],
    },
    {
        id: 'business',
        name: 'Business',
        price: '$249/мес',
        conversationsLimit: null,
        aiAgentsLimit: null,
        features: ['Безлимитные AI-ассистенты', 'Безлимитные диалоги', 'Журнал аудита', 'Выделенная поддержка'],
    },
];

export function planById(id: string | undefined): Plan {
    return plans.find((plan) => plan.id === id) ?? plans[0];
}
