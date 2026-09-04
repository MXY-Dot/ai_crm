<?php

namespace App\Support\Business;

/**
 * Catalog of TOGGLEABLE industry modules (ТЗ раздел 9) -- deliberately does
 * NOT include the always-on core features from ТЗ раздел 7 (единое окно
 * сообщений, клиенты, лиды, AI-ассистент, роли, ...), since those already
 * exist as baseline WERO features for every tenant and aren't meaningfully
 * something a company would "disable". A CompanyModule row toggling one of
 * these on is a real, persisted preference, and as of the module-gating
 * work in v1.175.0 it's also enforced, not just recorded: EnsurePageAccess
 * 404s a module's own settings page when its CompanyModule is off, and
 * AppSidebar hides the corresponding nav item -- every key below now has
 * real feature code behind it (Commerce/Booking/Restaurant/Hotel/Travel/
 * Logistics/Education/Auto Service/ERP integration all shipped), so
 * there's no gap left between "toggled on" and "actually usable" the way
 * there used to be when only booking_calendar existed.
 */
class ModuleRegistry
{
    public const MODULES = [
        'catalog_products' => 'Каталог товаров, цены, остатки',
        'orders' => 'Заказы и статусы заказов',
        'returns' => 'Возвраты',
        'delivery_tracking' => 'Доставка и отслеживание',
        'booking_calendar' => 'Календарь, специалисты, онлайн-запись',
        'prepayment' => 'Предоплата и подтверждение оплаты',
        'table_reservations' => 'Бронирование столиков',
        'room_booking' => 'Бронирование номеров',
        'tour_bookings' => 'Заявки на туры и путёвки',
        'shipment_tracking' => 'Отправления и трек-номера',
        'course_scheduling' => 'Курсы, группы, расписание занятий',
        'vehicle_service' => 'Автомобили клиентов и статусы ремонта',
        'crm_erp_integration' => 'Интеграция с CRM / 1С / складом',
    ];

    public static function labels(): array
    {
        return self::MODULES;
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }
}
