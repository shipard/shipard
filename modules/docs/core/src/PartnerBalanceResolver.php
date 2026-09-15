<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Osoba pro saldokonto (`docs_core_heads.partner_balance`, #72 D2) — plátce,
 * za kterým vzniká saldokontní pohledávka / závazek dokladu. Liší se od
 * partnera hlavičky u prodejního dokladu placeného kartou (protistrana
 * terminálu), bránou (protistrana brány) nebo na dobírku (dopravce).
 *
 * Jediná autorita odvození: volá ji `DocDocument::validate()` i
 * `beforeSave()` (validate běží dřív, odvození je idempotentní) a formulář
 * hlavičky pro živý náhled (`DocsHeadsFormBase::recalculate`). Pořadí:
 *
 *   1. prodejní směr + karta / brána → terminál hlavičky (neodpovídá-li nebo
 *      chybí, default: karta = default terminál pokladny hlavičky, bez
 *      pokladny mezi všemi terminály; brána = default brána) → jeho
 *      protistrana; `payment_terminal` se zapíše zpět;
 *   2. prodejní směr + dobírka + doprava s protistranou → dopravce;
 *   3. jinak, není-li plátce ruční (`partner_balance_manual`) → partner.
 *
 * Kroky 1–2 přepisují i ruční hodnotu (terminál / dopravce má přednost,
 * jako starý Shipard). Import (`_importNumber`) s explicitně poslaným ručním
 * plátcem se respektuje — terminály se mapují až v navazujícím importním
 * tasku a prázdný default by hodnotu ze starého systému přepsal.
 */
final class PartnerBalanceResolver
{
    public const METHOD_CARD = 2;
    public const METHOD_COD = 3;
    public const METHOD_GATEWAY = 5;

    public const KIND_TERMINAL = 0;
    public const KIND_GATEWAY = 1;

    /** Zdroje odvození (návratová hodnota `resolve`). */
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_TERMINAL = 'terminal';
    public const SOURCE_TRANSPORT = 'transport';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PARTNER = 'partner';

    public function __construct(
        private readonly ?\Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
    ) {
    }

    /** Je plátce odvozený z prostředníka (terminál / brána / dopravce)? */
    public static function isDerived(string $source): bool
    {
        return $source === self::SOURCE_TERMINAL || $source === self::SOURCE_TRANSPORT;
    }

    /** Prodejní směr dokladu (`invno`, `cashreg`, `cash` s příjmem). */
    public static function isSalesDirection(array $data, ?ConfigRuntime $config): bool
    {
        return DocDocument::resolveTradeDir($data, $config) === 1;
    }

    /** Karta / brána na prodejním dokladu — kdy se vůbec uplatní terminál. */
    public static function usesTerminal(array $data, ?ConfigRuntime $config): bool
    {
        $method = (int) ($data['payment_method'] ?? 1);
        return self::isSalesDirection($data, $config)
            && ($method === self::METHOD_CARD || $method === self::METHOD_GATEWAY);
    }

    /**
     * Odvodí `partner_balance` (a doplní `payment_terminal`) do `$data`.
     * Vrací zdroj hodnoty (SOURCE_*), aby formulář věděl, zda je plátce
     * odvozený (checkbox ručního zadání se pak schová).
     *
     * @param array<string, mixed> $data hlavička po denormalizaci z řady
     */
    public function resolve(array &$data, bool $importMode = false): string
    {
        $manual = !empty($data['partner_balance_manual']);
        if ($importMode && $manual && !empty($data['partner_balance'])) {
            return self::SOURCE_IMPORT;
        }

        $method = (int) ($data['payment_method'] ?? 1);
        $sales = self::isSalesDirection($data, $this->config);

        if ($sales && ($method === self::METHOD_CARD || $method === self::METHOD_GATEWAY)) {
            $terminal = $this->resolveTerminal($data, $method);
            $data['payment_terminal'] = $terminal['id'] ?? null;
            if ($terminal !== null && $terminal['partner'] !== null) {
                $data['partner_balance'] = $terminal['partner'];
                return self::SOURCE_TERMINAL;
            }
        }

        if ($sales && $method === self::METHOD_COD) {
            $transport = $this->loadTransport($data['transport'] ?? null);
            if ($transport !== null && $transport['partner'] !== null) {
                $data['partner_balance'] = $transport['partner'];
                return self::SOURCE_TRANSPORT;
            }
        }

        if ($manual) {
            return self::SOURCE_MANUAL;
        }

        $data['partner_balance'] = !empty($data['partner']) ? (int) $data['partner'] : null;
        return self::SOURCE_PARTNER;
    }

    /**
     * Terminál / brána dokladu: explicitní `payment_terminal`, pokud odpovídá
     * (druh; u karty s pokladnou hlavičky i pokladna), jinak default.
     *
     * @return array{id: int, kind: int, cash_desk: ?int, partner: ?int}|null
     */
    private function resolveTerminal(array $data, int $method): ?array
    {
        $kind = $method === self::METHOD_GATEWAY ? self::KIND_GATEWAY : self::KIND_TERMINAL;
        $cashDesk = !empty($data['cash_desk']) ? (int) $data['cash_desk'] : null;

        $current = $this->loadTerminal($data['payment_terminal'] ?? null);
        if ($current !== null && $current['kind'] === $kind
            && ($kind === self::KIND_GATEWAY || $cashDesk === null || $current['cash_desk'] === $cashDesk)
        ) {
            return $current;
        }

        return $this->defaultTerminal($kind, $kind === self::KIND_TERMINAL ? $cashDesk : null);
    }

    /** @return array{id: int, kind: int, cash_desk: ?int, partner: ?int}|null */
    private function loadTerminal(mixed $id): ?array
    {
        if ($id === null || $id === '' || (int) $id <= 0 || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [kind], [cash_desk], [partner] FROM [economy_codebooks_payment_terminals] WHERE [id] = %i',
            (int) $id,
        );
        return $row === null ? null : $this->terminalRow($row);
    }

    /** @return array{id: int, kind: int, cash_desk: ?int, partner: ?int}|null */
    private function defaultTerminal(int $kind, ?int $cashDesk): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $sql = 'SELECT [id], [kind], [cash_desk], [partner] FROM [economy_codebooks_payment_terminals]'
            . ' WHERE [kind] = %i AND [is_default] = 1 AND [docState] = 40';
        $args = [$kind];
        if ($cashDesk !== null) {
            $sql .= ' AND [cash_desk] = %i';
            $args[] = $cashDesk;
        }
        $sql .= ' ORDER BY [sort_order] ASC, [id] ASC LIMIT 1';
        $row = $this->db->fetch($sql, ...$args);
        return $row === null ? null : $this->terminalRow($row);
    }

    /** @return array{id: int, kind: int, cash_desk: ?int, partner: ?int} */
    private function terminalRow(mixed $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'kind'      => (int) ($row['kind'] ?? self::KIND_TERMINAL),
            'cash_desk' => isset($row['cash_desk']) && $row['cash_desk'] !== null ? (int) $row['cash_desk'] : null,
            'partner'   => isset($row['partner']) && $row['partner'] !== null ? (int) $row['partner'] : null,
        ];
    }

    /** @return array{id: int, partner: ?int}|null */
    private function loadTransport(mixed $id): ?array
    {
        if ($id === null || $id === '' || (int) $id <= 0 || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [partner] FROM [economy_codebooks_transports] WHERE [id] = %i',
            (int) $id,
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'      => (int) $row['id'],
            'partner' => isset($row['partner']) && $row['partner'] !== null ? (int) $row['partner'] : null,
        ];
    }
}
