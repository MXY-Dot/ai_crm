<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Seeds the 34 business types from ТЗ раздел 2, each with a sensible default_modules subset from App\Support\Business\ModuleRegistry::MODULES. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['other', 'Интернет магазин', ['catalog_products', 'orders', 'returns', 'delivery_tracking', 'crm_erp_integration']],
            ['other', 'Обычный магазин', ['catalog_products', 'orders', 'returns', 'crm_erp_integration']],
            ['other', 'Салон красоты', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Барбершоп', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'SPA и массажный салон', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Медицинская клиника', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Стоматология', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Аптека', ['catalog_products', 'orders', 'crm_erp_integration']],
            ['other', 'Ресторан', ['table_reservations', 'prepayment', 'delivery_tracking', 'crm_erp_integration']],
            ['other', 'Кафе и кофейня', ['table_reservations', 'delivery_tracking', 'crm_erp_integration']],
            ['other', 'Гостиница', ['room_booking', 'prepayment', 'crm_erp_integration']],
            ['other', 'Хостел', ['room_booking', 'prepayment', 'crm_erp_integration']],
            ['other', 'Туристическая компания', ['tour_bookings', 'prepayment', 'crm_erp_integration']],
            ['other', 'Логистическая компания', ['shipment_tracking', 'crm_erp_integration']],
            ['other', 'Курьерская служба', ['shipment_tracking', 'delivery_tracking', 'crm_erp_integration']],
            ['other', 'Учебный центр', ['course_scheduling', 'prepayment', 'crm_erp_integration']],
            ['other', 'Школа', ['course_scheduling', 'crm_erp_integration']],
            ['other', 'Детский центр', ['course_scheduling', 'booking_calendar', 'crm_erp_integration']],
            ['other', 'Фитнес клуб', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Спортивный центр', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Автосервис', ['vehicle_service', 'booking_calendar', 'crm_erp_integration']],
            ['other', 'Автомойка', ['vehicle_service', 'booking_calendar', 'crm_erp_integration']],
            ['other', 'Прокат автомобилей', ['vehicle_service', 'booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Агентство недвижимости', ['crm_erp_integration']],
            ['other', 'Строительная компания', ['crm_erp_integration']],
            ['other', 'Ремонт и бытовые услуги', ['booking_calendar', 'crm_erp_integration']],
            ['other', 'Юридическая компания', ['booking_calendar', 'crm_erp_integration']],
            ['other', 'Бухгалтерская компания', ['crm_erp_integration']],
            ['other', 'Маркетинговое агентство', ['crm_erp_integration']],
            ['other', 'Оптовая торговля', ['catalog_products', 'orders', 'crm_erp_integration']],
            ['other', 'Дистрибьюторская компания', ['catalog_products', 'orders', 'shipment_tracking', 'crm_erp_integration']],
            ['other', 'Производственная компания', ['orders', 'crm_erp_integration']],
            ['other', 'Организация мероприятий', ['booking_calendar', 'prepayment', 'crm_erp_integration']],
            ['other', 'Другая сфера', ['crm_erp_integration']],
        ];

        foreach ($rows as $i => [$prefixUnused, $name, $modules]) {
            DB::table('business_types')->insert([
                'key' => \Illuminate\Support\Str::slug($name, '_'),
                'name' => $name,
                'sort_order' => $i + 1,
                'is_active' => true,
                'default_modules' => json_encode($modules),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('business_types')->truncate();
    }
};
