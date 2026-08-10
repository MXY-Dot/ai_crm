export function titleCase(value: string | null | undefined): string {
    return (value ?? 'unknown')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function shortNumber(value: number): string {
    return new Intl.NumberFormat('en', { notation: 'compact' }).format(value);
}

export type ChannelTone = 'neutral' | 'blue' | 'telegram' | 'whatsapp' | 'instagram';

export function channelTone(value: string | null | undefined): ChannelTone {
    const key = (value ?? '').toLowerCase();
    if (key.includes('telegram')) return 'telegram';
    if (key.includes('whatsapp')) return 'whatsapp';
    if (key.includes('instagram')) return 'instagram';
    if (key.includes('website') || key.includes('widget')) return 'blue';

    return 'neutral';
}

const RELATIVE_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

export function timeAgo(value: string | null | undefined, locale: 'ru' | 'en' = 'ru'): string {
    if (! value) return '';

    const seconds = (Date.now() - new Date(value).getTime()) / 1000;
    if (! Number.isFinite(seconds)) return '';

    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    if (Math.abs(seconds) < 60) return formatter.format(0, 'minute');

    for (const [unit, unitSeconds] of RELATIVE_UNITS) {
        if (Math.abs(seconds) >= unitSeconds) {
            return formatter.format(Math.round(-seconds / unitSeconds), unit);
        }
    }

    return formatter.format(0, 'minute');
}