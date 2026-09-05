import { CalendarClock, GraduationCap, Hotel, Monitor, Plane, Truck, Utensils, Wrench } from '@lucide/vue';
import TelegramIcon from '../components/icons/TelegramIcon.vue';
import WhatsappIcon from '../components/icons/WhatsappIcon.vue';
import InstagramIcon from '../components/icons/InstagramIcon.vue';
import FacebookIcon from '../components/icons/FacebookIcon.vue';

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
    created_at: string;
    /** Which chat channel this record came from ('telegram'/'whatsapp'/'instagram'/'facebook'), or 'manual' for a pre-existing row or one created directly in the CRM. Only booking-shaped modules (booking_calendar/table_reservations/room_booking/vehicle_service) send a real value; others omit the field. */
    channel?: string;
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

// Which platform a booking-shaped record came from — same icon components and
// idea as RecentConversationsCard's own channelIcons map, extended with
// instagram/facebook and a 'manual' fallback for a pre-existing row or one
// created directly in the CRM rather than through a chat channel.
export const CHANNEL_ICONS: Record<string, unknown> = {
    telegram: TelegramIcon,
    whatsapp: WhatsappIcon,
    instagram: InstagramIcon,
    facebook: FacebookIcon,
    manual: Monitor,
};

export const CHANNEL_LABELS: Record<string, string> = {
    telegram: 'Telegram',
    whatsapp: 'WhatsApp',
    instagram: 'Instagram',
    facebook: 'Facebook',
    manual: 'Создано в CRM',
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

// Real per-status colors, same idea (and same palette) as
// BookingCalendarGrid's own STATUS_COLORS/bookingStatus.ts's STATUS_DOTS,
// extended to cover every status string across all 7 modules' own STATUSES
// consts (a handful of names collide across modules -- 'confirmed',
// 'completed', 'cancelled' -- and mean the same thing everywhere they
// appear, so one flat map is enough; no per-module override needed).
export const STATUS_COLORS: Record<string, string> = {
    // "not yet confirmed / waiting on something"
    temp_hold: 'bg-muted text-muted-foreground',
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    awaiting_payment: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    diagnosing: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    awaiting_approval: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    awaiting_parts: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    payment_review: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    // "confirmed / open / booked"
    confirmed: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    active: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    recruiting: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
    open: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
    received: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
    // "customer/order physically present or on its way"
    client_arrived: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    checked_in: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    seated: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    out_for_delivery: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-400',
    // "actively being worked / underway"
    in_progress: 'bg-purple-500/15 text-purple-700 dark:text-purple-400',
    in_transit: 'bg-purple-500/15 text-purple-700 dark:text-purple-400',
    departed: 'bg-purple-500/15 text-purple-700 dark:text-purple-400',
    ready_for_pickup: 'bg-teal-500/15 text-teal-700 dark:text-teal-400',
    // "done, successfully"
    completed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    checked_out: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    delivered: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    // "no longer live"
    rescheduled: 'bg-muted text-muted-foreground',
    closed: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground line-through opacity-70',
    // "went wrong"
    no_show: 'bg-destructive/10 text-destructive',
    returned: 'bg-destructive/10 text-destructive',
};

export const STATUS_DOTS: Record<string, string> = {
    temp_hold: 'bg-muted-foreground',
    pending: 'bg-amber-500',
    awaiting_payment: 'bg-amber-500',
    diagnosing: 'bg-amber-500',
    awaiting_approval: 'bg-orange-500',
    awaiting_parts: 'bg-orange-500',
    payment_review: 'bg-orange-500',
    confirmed: 'bg-blue-500',
    active: 'bg-blue-500',
    recruiting: 'bg-sky-500',
    open: 'bg-sky-500',
    received: 'bg-sky-500',
    client_arrived: 'bg-indigo-500',
    checked_in: 'bg-indigo-500',
    seated: 'bg-indigo-500',
    out_for_delivery: 'bg-indigo-500',
    in_progress: 'bg-purple-500',
    in_transit: 'bg-purple-500',
    departed: 'bg-purple-500',
    ready_for_pickup: 'bg-teal-500',
    completed: 'bg-emerald-500',
    checked_out: 'bg-emerald-500',
    delivered: 'bg-emerald-500',
    rescheduled: 'bg-muted-foreground',
    closed: 'bg-muted-foreground',
    cancelled: 'bg-muted-foreground',
    no_show: 'bg-destructive',
    returned: 'bg-destructive',
};

const STRIKETHROUGH_STATUSES = new Set(['cancelled', 'no_show', 'returned']);

export function hasStrikethrough(status: string): boolean {
    return STRIKETHROUGH_STATUSES.has(status);
}

// Explicit RU text per status, same flat cross-module map as STATUS_COLORS/
// STATUS_DOTS above and the same reasoning (a handful of names collide
// across modules and mean the same thing everywhere) -- so a colored dot
// alone is never the only way to tell what an event's status is.
export const STATUS_LABELS: Record<string, string> = {
    temp_hold: 'Временная бронь',
    pending: 'Ожидает подтверждения',
    awaiting_payment: 'Ожидает оплаты',
    diagnosing: 'Диагностика',
    awaiting_approval: 'Ожидает согласования',
    awaiting_parts: 'Ожидает запчасти',
    payment_review: 'Оплата на проверке',
    confirmed: 'Подтверждена',
    active: 'Идёт',
    recruiting: 'Набор',
    open: 'Открыт',
    received: 'Принято',
    client_arrived: 'Клиент пришёл',
    checked_in: 'Заселён',
    seated: 'Гости за столиком',
    out_for_delivery: 'На доставке',
    in_progress: 'В работе',
    in_transit: 'В пути',
    departed: 'В пути',
    ready_for_pickup: 'Готов к выдаче',
    completed: 'Завершена',
    checked_out: 'Выселен',
    delivered: 'Доставлено',
    rescheduled: 'Перенесена',
    closed: 'Закрыт',
    cancelled: 'Отменена',
    no_show: 'Не пришли',
    returned: 'Возврат',
};

export function statusLabel(status: string): string {
    return STATUS_LABELS[status] ?? status;
}

// Statuses that mean "resolved, nothing left to do" -- the complement of
// this set (still "open" past its own end time) is what isOverdue() below
// flags. Deliberately includes no_show/cancelled/returned themselves: once
// staff actually SET one of those, the event is handled and stops being
// "overdue" -- the whole point is catching the ones nobody touched yet.
const TERMINAL_STATUSES = new Set([
    'completed', 'checked_out', 'delivered', 'rescheduled', 'closed', 'cancelled', 'no_show', 'returned',
]);

/** Client never showed / date passed and nobody ever updated the status -- exactly the case the calendar should surface instead of letting it silently fall behind. */
export function isOverdue(event: CalendarEvent, now: Date = new Date()): boolean {
    return ! TERMINAL_STATUSES.has(event.status) && new Date(event.ends_at) < now;
}

const NEW_WINDOW_MS = 24 * 60 * 60 * 1000;

/** Created within the last 24h -- a fresh booking staff hasn't necessarily noticed yet. */
export function isNew(event: CalendarEvent, now: Date = new Date()): boolean {
    return now.getTime() - new Date(event.created_at).getTime() < NEW_WINDOW_MS;
}

// Rotating per-COLUMN accent for CalendarDayGrid's resource headers (one
// employee/table/room/group per column) -- same rotating-palette idea as
// BookingCalendarGrid's own EMPLOYEE_ACCENTS, generalized past employees.
export const RESOURCE_ACCENTS = ['bg-sky-500', 'bg-violet-500', 'bg-amber-500', 'bg-emerald-500', 'bg-rose-500', 'bg-indigo-500'];

export function resourceAccent(index: number): string {
    return RESOURCE_ACCENTS[index % RESOURCE_ACCENTS.length];
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
