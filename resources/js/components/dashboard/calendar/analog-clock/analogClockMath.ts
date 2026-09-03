// Shared geometry for the analog time picker -- every sub-component reads
// its coordinates from here so the face, hand and slot dots always agree on
// the same center/radius, and there's exactly one place that knows how a
// clock-time turns into an angle.

export const CLOCK_SIZE = 220;
export const CLOCK_CENTER = CLOCK_SIZE / 2;
export const CLOCK_RADIUS = CLOCK_CENTER - 18;

/**
 * A single 24-hour rotation (00:00 at the top, clockwise) rather than a
 * classic 12-hour face with an AM/PM split. Business-hours bookings never
 * cross midnight, so every time in a day still gets one unique angle here --
 * a 12-hour face would need a second ring (or AM/PM toggle) to avoid two
 * different times landing on the same point, for no benefit in this context.
 */
export function angleForTime(hour: number, minute: number): number {
    const fraction = (hour + minute / 60) / 24;
    return fraction * 360 - 90;
}

export function pointOnCircle(angleDeg: number, radius: number = CLOCK_RADIUS): { x: number; y: number } {
    const rad = (angleDeg * Math.PI) / 180;
    return { x: CLOCK_CENTER + radius * Math.cos(rad), y: CLOCK_CENTER + radius * Math.sin(rad) };
}

export function timeKey(iso: string): string {
    const d = new Date(iso);
    return `${d.getHours()}:${d.getMinutes()}`;
}

export function formatHourMinute(iso: string): string {
    return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
