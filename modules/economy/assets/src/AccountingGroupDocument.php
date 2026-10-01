<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Účetní skupina majetku (economy_assets_accounting_groups, D5/D24).
 *
 * Každý vyplněný účet musí být existující aktivní analytický účet
 * (account_level = 4) ve správné třídě osnovy — klientský filtr lookupu
 * (`number_prefix`) není bezpečnostní hranice, tady je tvrdé vynucení
 * (vzor CashDeskDocument / 211). Povinný je jen účet majetku — pozemky
 * účet odpisů ani oprávek nemají. Skupinu bez nich ale nejde použít pro
 * odepisovaný majetek: hlídá to potvrzení karty (`AssetDocument`, D57),
 * formulář na to upozorňuje hintem.
 */
class AccountingGroupDocument extends Document
{
    /**
     * Sloupec → povolené prefixy syntetického účtu (`^prefix`).
     *   majetek 01x/02x/03x, pořízení 04x, oprávky 07x/08x, odpisy 55x,
     *   zůstatková cena při vyřazení 54x (prodej 541) nebo 55x (likvidace).
     */
    public const ACCOUNT_PREFIXES = [
        'account_asset'        => ['01', '02', '03'],
        'account_acquisition'  => ['04'],
        'account_accumulated'  => ['07', '08'],
        'account_depreciation' => ['55'],
        'account_disposal'     => ['54', '55'],
    ];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $result->addError('code', 'Kód je povinný', 'required');
        } elseif ($this->db !== null
            && $this->findCodeOwner($code, isset($data['id']) ? (int) $data['id'] : null) !== null
        ) {
            $result->addError('code', "Kód {$code} už má jiná účetní skupina.", 'duplicate');
        }

        if (trim((string) ($data['name'] ?? '')) === '') {
            $result->addError('name', 'Název je povinný', 'required');
        }

        if (empty($data['account_asset'])) {
            $result->addError('account_asset', 'Účet majetku je povinný', 'required');
        }

        foreach (self::ACCOUNT_PREFIXES as $column => $prefixes) {
            if (empty($data[$column]) || $this->db === null) {
                continue;
            }
            $number = $this->findActiveAnalyticalAccountNumber((int) $data[$column]);
            if ($number === null) {
                $result->addError($column, 'Účet musí být existující aktivní analytický účet.', 'invalid');
                continue;
            }
            $ok = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($number, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $result->addError(
                    $column,
                    'Účet musí být ve skupině ' . implode(', ', $prefixes) . '.',
                    'invalid',
                );
            }
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        foreach (['code', 'name'] as $col) {
            if (isset($data[$col])) {
                $data[$col] = trim((string) $data[$col]);
            }
        }
    }

    // ── Helpers (protected kvůli spy subclass v testech — Dibi query() je final) ──

    protected function findCodeOwner(string $code, ?int $excludeId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_assets_accounting_groups] WHERE [code] = %s AND [id] <> %i LIMIT 1',
            $code,
            $excludeId ?? 0,
        );
        return $row === null || $row === false ? null : (int) $row['id'];
    }

    /** Číslo účtu, je-li aktivní (10/40/80) analytický (level 4); jinak null. */
    protected function findActiveAnalyticalAccountNumber(int $accountId): ?string
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [number] FROM [economy_accounting_accounts]'
            . ' WHERE [id] = %i AND [account_level] = 4 AND [docState] IN (10, 40, 80)',
            $accountId,
        );
        return $row === null || $row === false ? null : (string) $row['number'];
    }
}
