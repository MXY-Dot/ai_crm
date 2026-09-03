// Shared geometry for the analog time picker -- every sub-component reads
// its coordinates from here so the face, hands and slot dots always agree
// on the same center/radius, and there's exactly one place that knows how a
// clock-time turns into an angle.

export const CLOCK_SIZE = 220;
export const CLOCK_CENTER = CLOCK_SIZE / 2;
export const CLOCK_RADIUS = CLOCK_CENTER - 18;

/** Classic 12-hour face: 12 at the top, 6 at the bottom, going clockwise. */
export function hourAngle(hour12: number, minute: number): number {
    const fraction = ((hour12 % 12) + minute / 60) / 12;
    return fraction * 360 - 90;
}

export function minuteAngle(minute: number): number {
    return (minute / 60) * 360 - 90;
}

export function pointOnCircle(angleDeg: number, radius: number = CLOCK_RADIUS): { x: number; y: number } {
    const rad = (angleDeg * Math.PI) / 180;
    return { x: CLOCK_CENTER + radius * Math.cos(rad), y: CLOCK_CENTER + radius * Math.sin(rad) };
}

/** Shortest angular gap between two angles, always 0-180 -- used to find
 *  which real slot a dragged pointer is closest to. */
export function angularDistance(a: number, b: number): number {
    const diff = Math.abs(a - b) % 360;
    return diff > 180 ? 360 - diff : diff;
}

export function timeKey(iso: string): string {
    const d = new Date(iso);
    return `${d.getHours()}:${d.getMinutes()}`;
}

export function formatHourMinute(iso: string): string {
    return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
