<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * ТЗ раздел 21 (роли сотрудников) — оператор должен уметь общаться с
 * клиентами и создавать записи, поэтому booking-и не следуют
 * owner/manager-only правилу из TenantResourcePolicy::create/update.
 */
class BookingPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    /**
     * Специалист only ever sees their OWN bookings -- this is a real data
     * boundary, not just a UI filter (see BookingController::index(), which
     * force-scopes the list query the same way for the same role). A specialist
     * with no linked Employee profile (employee_id null) sees nothing, rather
     * than either everything or a 500 -- that's a mis-provisioned account, not
     * a reason to leak the calendar.
     */
    public function view(User $user, Model $record): bool
    {
        if (! $this->canUseTenant($user) || $user->tenant_id !== $record->tenant_id) {
            return false;
        }

        if ($user->role === User::ROLE_SPECIALIST) {
            return $record instanceof Booking && $user->employee_id !== null && $user->employee_id === $record->employee_id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    /** General booking management (reschedule/cancel/status/upload proof) -- see managePayments() below for the narrower financial-only ability Бухгалтер gets instead. */
    public function update(User $user, Booking $record): bool
    {
        if (! $this->canUseTenant($user) || $user->tenant_id !== $record->tenant_id) {
            return false;
        }

        if (in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true)) {
            return true;
        }

        if ($user->role === User::ROLE_SPECIALIST) {
            return $user->employee_id !== null && $user->employee_id === $record->employee_id;
        }

        return false;
    }

    /**
     * ТЗ раздел 21 -- "Бухгалтер: Платежи, возвраты и финансовые отчёты", deliberately
     * NOT the same ability as update() -- an accountant reviews payment proofs,
     * marks cash payments, and processes refunds, but does not reschedule/cancel/
     * change a booking's operational status. Owner/manager/operator keep the
     * payments access they already had through update() before this role existed.
     */
    public function managePayments(User $user, Booking $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR, User::ROLE_ACCOUNTANT], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
