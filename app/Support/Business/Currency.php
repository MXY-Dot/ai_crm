<?php

namespace App\Support\Business;

/**
 * ТЗ — "тип валюты для услуг": every company has exactly ONE configured
 * currency (Company.currency, default TJS) that governs how every price is
 * displayed everywhere — AI chat, admin UI, notifications. Deliberately a
 * static ISO-4217 label/symbol table, not a live exchange-rate API: nothing
 * here ever converts an amount between currencies (a company's prices are
 * already stored in whichever currency it picked), so the only thing that
 * changes per company is the LABEL shown after a number — static data, no
 * good reason to depend on a third-party API's uptime for that.
 */
class Currency
{
    public const DEFAULT = 'TJS';

    /** @var array<string, array{label: string, symbol: string}> */
    public const ALL = [
        'TJS' => ['label' => 'сомони', 'symbol' => 'смн'],
        'USD' => ['label' => 'доллар США', 'symbol' => '$'],
        'EUR' => ['label' => 'евро', 'symbol' => '€'],
        'RUB' => ['label' => 'российский рубль', 'symbol' => '₽'],
        'UZS' => ['label' => 'узбекский сум', 'symbol' => "so'm"],
        'KGS' => ['label' => 'киргизский сом', 'symbol' => 'сом'],
        'KZT' => ['label' => 'казахстанский тенге', 'symbol' => '₸'],
        'TRY' => ['label' => 'турецкая лира', 'symbol' => '₺'],
        'CNY' => ['label' => 'китайский юань', 'symbol' => '¥'],
        'GBP' => ['label' => 'фунт стерлингов', 'symbol' => '£'],
        'AED' => ['label' => 'дирхам ОАЭ', 'symbol' => 'AED'],
    ];

    public static function symbol(?string $code): string
    {
        $code = self::normalize($code);

        return self::ALL[$code]['symbol'] ?? $code;
    }

    public static function label(?string $code): string
    {
        $code = self::normalize($code);

        return self::ALL[$code]['label'] ?? $code;
    }

    /** "140 смн" — the shape every *ChatContext/*ChatAssistant/*OfferButtons price line already used with a hardcoded "смн", now parameterized per company. */
    public static function format(float|int $amount, ?string $code): string
    {
        return number_format((float) $amount, 0, ',', ' ').' '.self::symbol($code);
    }

    private static function normalize(?string $code): string
    {
        $code = strtoupper((string) $code);

        return isset(self::ALL[$code]) ? $code : self::DEFAULT;
    }
}
