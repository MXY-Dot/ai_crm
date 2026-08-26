<?php

namespace App\Support\Business;

/**
 * Catalog of TOGGLEABLE industry modules (ТЗ раздел 9) -- deliberately does
 * NOT include the always-on core features from ТЗ раздел 7 (единое окно
 * сообщений, клиенты, лиды, AI-ассистент, роли, ...), since those already
 * exist as baseline WERO features for every tenant and aren't meaningfully
 * something a company would "disable". A CompanyModule row toggling one of
 * these on is a real, persisted preference -- but most of these modules
 * don't have real feature code behind them yet (only the booking/calendar
 * pieces for beauty salons are planned as the very next build per ТЗ
 * раздел 25 "Первый этап"); toggling e.g. table_reservations today just
 * records intent, it doesn't turn on a table-reservation UI that doesn't
 * exist. That gap is intentional and should stay visible, not hidden.
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
