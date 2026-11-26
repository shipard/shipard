<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Queue;

use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Engine\SendStatus;

/**
 * Co výsledek odeslání znamená pro tržbu (#171 U5) — čisté mapování
 * `SendResult` → stav fronty:
 *
 *  - potvrzeno → `confirmed` (povinnost splněna);
 *  - ověřeno (registrace v ověřovacím režimu) → `verified`, konečný;
 *  - zahozeno pojistkou serveru → `dropped`, konečný a bez upozornění:
 *    kopie reálných dat na vývojářském serveru nesmí nic poslat ani zahltit
 *    upozorněními;
 *  - odmítnuto službou nebo neplatná zpráva → `failed`; dál jen akcí
 *    Odeslat znovu — opakování stejných dat by dopadlo stejně;
 *  - výpadek služby, nejasná odpověď a **problém na naší straně** (chybí
 *    certifikát, klíč, testovací identita, registrace není V pořádku,
 *    interní chyba) → zpět na `pending` s backoffem. Zpráva v tom případě
 *    vůbec neodešla a problém odstraní nastavení, ne nová tržba.
 *
 * Odesílač vrací problémy na naší straně stavem „odmítnuto“ s vyplněným
 * `problem` — proto se rozhoduje podle něj, ne jen podle stavu.
 */
final readonly class SaleOutcome
{
    /** Registrace tržby neexistuje nebo není V pořádku — problém na naší straně. */
    public const string PROBLEM_REGISTRATION = 'registration';

    /** Odstupy opakování (s): 1 min → 5 min → 15 min → 1 h, dál po hodině bez konce (D7). */
    public const array BACKOFF = [60, 300, 900, 3600];

    private function __construct(
        public string $state,
        public bool $retry,
    ) {
    }

    public static function of(SendResult $result): self
    {
        return match ($result->status) {
            SendStatus::Confirmed => new self(SaleState::CONFIRMED, false),
            SendStatus::Verified  => new self(SaleState::VERIFIED, false),
            SendStatus::Dropped   => new self(SaleState::DROPPED, false),
            SendStatus::RetryLater, SendStatus::Unknown => new self(SaleState::PENDING, true),
            SendStatus::Rejected  => $result->problem === null || $result->problem === SendResult::PROBLEM_MESSAGE
                ? new self(SaleState::FAILED, false)
                : new self(SaleState::PENDING, true),
        };
    }

    /**
     * Odstup dalšího pokusu po pokusu číslo `$attempt` (od 1).
     */
    public static function backoffSeconds(int $attempt): int
    {
        $index = max(1, $attempt) - 1;
        return self::BACKOFF[min($index, count(self::BACKOFF) - 1)];
    }

    /** Výsledek, po kterém nemá smysl hned zkoušet další tržbu téže registrace (jistič, U6). */
    public static function isOutage(SendResult $result): bool
    {
        return in_array($result->status, [SendStatus::RetryLater, SendStatus::Unknown], true);
    }
}
