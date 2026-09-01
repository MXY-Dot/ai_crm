<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * ТЗ раздел 21 (роли сотрудников) -- заказы, как и записи, должны быть
 * доступны оператору (общение с клиентами, оформление заказов), не только
 * владельцу/менеджеру -- см. BookingPolicy для того же рассуждения.
 */
class OrderPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseTenant($user);
    }

    public function view(User $user, Order $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id;
    }

    public function create(User $user): bool
    {
        return $this->canUseTenant($user) && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    public function update(User $user, Order $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR], true);
    }

    /** Возвраты и финансовая сторона заказа -- ТЗ раздел 21, тот же принцип что и BookingPolicy::managePayments(). */
    public function manageReturns(User $user, Order $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR, User::ROLE_ACCOUNTANT], true);
    }

    /** Payment confirmation/refunds -- same role list as manageReturns() and BookingPolicy::managePayments(). */
    public function managePayments(User $user, Order $record): bool
    {
        return $this->canUseTenant($user) && $user->tenant_id === $record->tenant_id
            && in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_OPERATOR, User::ROLE_ACCOUNTANT], true);
    }

    private function canUseTenant(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === app(TenantContext::class)->id();
    }
}
