// Shared status-dot colors for the booking calendar's Day/Week/Month views --
// <script setup> can't export runtime values (only types), so this lives as a
// plain module rather than an export from BookingCalendarGrid.vue.
export const STATUS_DOTS: Record<string, string> = {
    temp_hold: 'bg-muted-foreground',
    awaiting_payment: 'bg-amber-500',
    payment_review: 'bg-orange-500',
    confirmed: 'bg-blue-500',
    client_arrived: 'bg-indigo-500',
    in_progress: 'bg-purple-500',
    completed: 'bg-emerald-500',
    rescheduled: 'bg-muted-foreground',
    cancelled: 'bg-muted-foreground',
    no_show: 'bg-destructive',
};
