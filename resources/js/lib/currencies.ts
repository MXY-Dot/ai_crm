// Mirrors App\Support\Business\Currency's own ISO-4217 label/symbol table
// exactly -- one company-level currency governs every price shown
// everywhere (AI chat, admin UI), default TJS (сомони) per the user's
// explicit request. Static list, not a live exchange-rate API: nothing
// converts an amount between currencies here, only the label after a
// number changes per company.
export const CURRENCIES: Record<string, { label: string; symbol: string }> = {
    TJS: { label: 'Сомони', symbol: 'смн' },
    USD: { label: 'Доллар США', symbol: '$' },
    EUR: { label: 'Евро', symbol: '€' },
    RUB: { label: 'Российский рубль', symbol: '₽' },
    UZS: { label: 'Узбекский сум', symbol: "so'm" },
    KGS: { label: 'Киргизский сом', symbol: 'сом' },
    KZT: { label: 'Казахстанский тенге', symbol: '₸' },
    TRY: { label: 'Турецкая лира', symbol: '₺' },
    CNY: { label: 'Китайский юань', symbol: '¥' },
    GBP: { label: 'Фунт стерлингов', symbol: '£' },
    AED: { label: 'Дирхам ОАЭ', symbol: 'AED' },
};

export const CURRENCY_CODES = Object.keys(CURRENCIES);

export function currencyLabel(code: string | null | undefined): string {
    const key = (code ?? 'TJS').toUpperCase();

    return CURRENCIES[key] ? `${CURRENCIES[key].label} (${key})` : key;
}
