<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

/**
 * Čas kontaktní platby (#171 U13, U23). Pole formuláře i dialog akce dávají
 * čas **bez zóny** (`datetime-local`) — uživatel zadává hodiny na zdi v místě
 * tržby. Sloupec `contact_payment_at` proto drží čas v zóně místa tržby
 * (`timeZone` typu evidence), tak jak byl zadán; ostatní časy evidence
 * (`doc_state_changed_at`, `sold_at`) jsou v zóně serveru. Převod mezi nimi
 * dělá jen tahle třída — na serveru ve světovém čase by jinak platba zadaná
 * ve 14:30 šla do zprávy jako 15:30, tedy v budoucnu.
 */
final class PaymentTime
{
    public const string FORMAT = 'Y-m-d H:i:s';

    /** O kolik smí zadaný čas předběhnout hodiny serveru (s) — hodiny prohlížeče nejdou přesně. */
    public const int FUTURE_TOLERANCE = 300;

    /**
     * Zadaný čas v jednotném zápisu `Y-m-d H:i:s` (beze změny hodin).
     * Bere `2027-01-05T14:30`, `2027-01-05 14:30:00` i objekt data;
     * null = prázdné nebo nečitelné.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::FORMAT);
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?/', $text, $m) !== 1) {
            return null;
        }
        $normalized = $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $normalized, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format(self::FORMAT) === $normalized ? $normalized : null;
    }

    /** Okamžik platby: zadaný čas vyložený v zóně místa tržby. */
    public static function instant(?string $stored, \DateTimeZone $saleZone): ?\DateTimeImmutable
    {
        $normalized = self::normalize($stored);
        if ($normalized === null) {
            return null;
        }
        $instant = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $normalized, $saleZone);
        return $instant === false ? null : $instant;
    }

    /** Okamžik platby v zóně serveru — v ní se ukládá čas tržby (`sold_at`). */
    public static function inServerZone(?string $stored, \DateTimeZone $saleZone): ?\DateTimeImmutable
    {
        return self::instant($stored, $saleZone)?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /** Je zadaný čas v budoucnu (přes toleranci)? */
    public static function isFuture(?string $stored, \DateTimeZone $saleZone, ?\DateTimeImmutable $now = null): bool
    {
        $instant = self::instant($stored, $saleZone);
        if ($instant === null) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        return $instant->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE;
    }

    /**
     * Zadaný čas, nejvýš ale „teď“ — pár minut napřed (tolerance hodin
     * prohlížeče) se do sloupce nezapíše, čas tržby v budoucnu služba nebere.
     */
    public static function notAfterNow(string $stored, \DateTimeZone $saleZone, ?\DateTimeImmutable $now = null): string
    {
        $instant = self::instant($stored, $saleZone);
        $now ??= new \DateTimeImmutable();
        if ($instant === null || $instant->getTimestamp() <= $now->getTimestamp()) {
            return self::normalize($stored) ?? $stored;
        }
        return $now->setTimezone($saleZone)->format(self::FORMAT);
    }
}
