import { CalendarClock, GraduationCap, Hotel, Plane, Truck, Utensils, Wrench } from '@lucide/vue';

// Shared normalized shape returned by GET /api/calendar/events — see
// CalendarController's own docblock for why every module (a real time slot,
// a multi-night stay, a projected weekly recurrence, a date-only range, or a
// resourceless all-day marker) is flattened into this one shape before it
// ever reaches the frontend.
export type CalendarResource = { id: number | string; name: string };
export type CalendarEvent = {
    id: string;
    entity_id: number;
    module: string;
    resource_id: number | string | null;
    starts_at: string;
    ends_at: string;
    status: string;
    title: string;
    subtitle: string;
};

export const MODULE_ICONS: Record<string, unknown> = {
    booking_calendar: CalendarClock,
    table_reservations: Utensils,
    room_booking: Hotel,
    course_scheduling: GraduationCap,
    tour_bookings: Plane,
    vehicle_service: Wrench,
    shipment_tracking: Truck,
};

export const MODULE_ACCENTS: Record<string, string> = {
    booking_calendar: 'bg-sky-500',
    table_reservations: 'bg-amber-500',
    room_booking: 'bg-violet-500',
    course_scheduling: 'bg-emerald-500',
    tour_bookings: 'bg-rose-500',
    vehicle_service: 'bg-orange-500',
    shipment_tracking: 'bg-indigo-500',
};

// Cancelled/no-show/completed-style statuses read the same across every
// module's own status enum (see each model's own STATUSES const) — muted
// treatment everywhere a status implies "no longer live", full accent
// otherwise. Deliberately coarse (2 buckets, not a per-status palette like
// BookingCalendarGrid's own STATUS_COLORS) since this shared view spans 7
// different status vocabularies with no single shared meaning per status
// name beyond "still active" vs "not".
const MUTED_STATUSES = new Set(['cancelled', 'no_show', 'rejected', 'returned', 'closed']);

export function isMutedStatus(status: string): boolean {
    return MUTED_STATUSES.has(status);
}

// Modules whose events have a real same-day time-of-day component and
// benefit from an hour-by-hour resource-column grid on Day view. The rest
// (multi-night stays, date-only ranges, resourceless all-day markers) fall
// back to a simple agenda list — see CalendarController's own docblock for
// why forcing those into an hour grid would invent precision the data
// doesn't have.
export const HOUR_GRID_MODULES = new Set(['booking_calendar', 'table_reservations', 'course_scheduling']);

// Local-calendar date extraction, not UTC string-slicing — see
// BookingCalendarPage.vue's own toLocalDateString() docblock: local midnight
// in a timezone ahead of UTC (e.g. Asia/Dushanbe, UTC+5) is still the
// previous day in UTC, so toISOString() silently shifts every date back.
export function toLocalDateString(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** True when the event overlaps the given local calendar date at all — not just when it STARTS that date, so a multi-night stay or a multi-day tour shows on every day it spans. */
export function eventOnDate(event: CalendarEvent, date: string): boolean {
    const dayStart = new Date(date + 'T00:00:00');
    const dayEnd = new Date(date + 'T23:59:59.999');

    return new Date(event.starts_at) <= dayEnd && new Date(event.ends_at) >= dayStart;
}
