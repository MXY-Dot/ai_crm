export function titleCase(value: string | null | undefined): string {
    return (value ?? 'unknown')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function shortNumber(value: number): string {
    return new Intl.NumberFormat('en', { notation: 'compact' }).format(value);
}