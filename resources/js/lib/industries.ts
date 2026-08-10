export const INDUSTRY_VALUES = [
    'retail',
    'beauty',
    'medical',
    'restaurant',
    'real_estate',
    'education',
    'fitness',
    'automotive',
    'legal',
    'logistics',
    'it_services',
    'construction',
    'finance',
] as const;

export type IndustryValue = typeof INDUSTRY_VALUES[number];
