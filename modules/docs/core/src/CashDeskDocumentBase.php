<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\ValidationResult;

/**
 * Společná báze dokladů vázaných na pokladnu (`docTypes[].series_binding =
 * "cash_desk"`): pokladní doklad (`cash`, modul docs.cashDocs) a prodejka
 * (`cashreg`, modul docs.cashRegister). Žije v docs.core, protože docs.core
 * už vlastní vazbu řady na pokladnu, `cash_dir` i `CashDirection` — a oba
 * moduly tak zůstávají na sobě nezávislé.
 *
 * Co báze přidává nad DocsHeadsDocument (#59 D5–D9):
 *   - hlavičkový partner nepovinný (anonymní příjem / výdej / prodej;
 *     u úhrad `payment.*` partnera nese řádek),
 *   - způsob úhrady jen Hotovost (0) nebo Kartou (2), default 0 — potomek může
 *     seznam rozšířit přepsáním `PAYMENT_METHODS_ALLOWED` (prodejka přidává
 *     Převodem, viz CashRegisterDocument),
 *   - měna dokladu = měna pokladny (jiná je chyba `currency_mismatch`),
 *   - splatnost = datum vystavení (hotovostní doklad nemá splatnost),
 *   - příjem kartou / bránou / dobírkou vyžaduje plátce (`partner_balance`
 *     po odvození — protistrana terminálu, brány, dopravce, jinak partner;
 *     #72 D2): pohledávka 311 bez dlužníka by se nedala spárovat.
 *
 * Směr (`cash_dir`), denormalizaci pokladny z řady a snapshoty stran řeší
 * DocDocument obecně přes `resolveTradeDir` — tady se nic neduplikuje.
 */
abstract class CashDeskDocumentBase extends DocsHeadsDocument
{
    /**
     * Povolené způsoby úhrady: 0 Hotovost, 2 Kartou (cfgItem docs.core.paymentMethods).
     * Potomek přepíše (late static binding v `validate` i ve formuláři).
     */
    public const PAYMENT_METHODS_ALLOWED = [0, 2];

    /** Způsoby úhrady, u kterých prodejní doklad musí mít plátce (karta, dobírka, brána). */
    public const PAYMENT_METHODS_NEED_PAYER = [
        PartnerBalanceResolver::METHOD_CARD,
        PartnerBalanceResolver::METHOD_COD,
        PartnerBalanceResolver::METHOD_GATEWAY,
    ];

    /** Lidský popis povolených metod pro chybovou hlášku. */
    protected function paymentMethodsAllowedLabel(): string
    {
        return 'hotově nebo kartou';
    }

    protected function headPartnerRequired(): bool
    {
        return false;
    }

    public function validate(array &$data): ValidationResult
    {
        // Rodič denormalizuje cash_desk z řady a hlídá cash_dir / vazbu řady.
        $result = parent::validate($data);

        $paymentMethod = $data['payment_method'] ?? null;
        if ($paymentMethod !== null && $paymentMethod !== ''
            && !in_array((int) $paymentMethod, static::PAYMENT_METHODS_ALLOWED, true)
        ) {
            $result->addError(
                'payment_method',
                'Doklad lze uhradit jen ' . $this->paymentMethodsAllowedLabel(),
                'invalid_value',
            );
        }

        // Rodič už odvodil partner_balance (PartnerBalanceResolver); prodejní
        // doklad placený kartou / bránou / dobírkou bez plátce = pohledávka
        // bez dlužníka. DS bez terminálů projde, jakmile má doklad partnera.
        if (PartnerBalanceResolver::isSalesDirection($data, $this->config)
            && in_array((int) $paymentMethod, self::PAYMENT_METHODS_NEED_PAYER, true)
            && empty($data['partner_balance'])
        ) {
            $result->addError(
                'partner_balance',
                'Doklad nemá plátce — nastav terminál / bránu / dopravce s protistranou, nebo zadej odběratele',
                'partner_balance_required',
            );
        }

        $desk = $this->loadCashDesk($data['cash_desk'] ?? null);
        $docCurrency = strtolower(trim((string) ($data['doc_currency'] ?? '')));
        if ($desk !== null && $docCurrency !== '' && $desk['currency'] !== ''
            && $docCurrency !== $desk['currency']
        ) {
            $result->addError(
                'doc_currency',
                sprintf('Měna dokladu musí odpovídat měně pokladny (%s)', strtoupper($desk['currency'])),
                'currency_mismatch',
            );
        }

        return $result;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->applyPaymentMethodDefault($data);
        parent::beforeSave($data, $originalData);
    }

    /** Schéma má default 1 (Převodem) — u pokladního dokladu je default Hotovost. */
    protected function applyPaymentMethodDefault(array &$data): void
    {
        if (!isset($data['payment_method']) || $data['payment_method'] === '') {
            $data['payment_method'] = 0;
        }
    }

    /**
     * Hotovostní doklad nemá splatnost — due_date = issue_date vždy (rodič by
     * dosadil splatnost partnera / 14 dní). Běží v beforeSave hned po
     * denormalizaci z řady.
     */
    protected function applyDateDefaults(array &$data): void
    {
        if (!empty($data['issue_date'])) {
            $data['due_date'] = $data['issue_date'];
        }
        parent::applyDateDefaults($data);
    }

    /** Prázdná měna dokladu → měna pokladny (validate už odmítl explicitní nesoulad). */
    protected function applyHomeCurrency(array &$data): void
    {
        if (empty($data['doc_currency'])) {
            $desk = $this->loadCashDesk($data['cash_desk'] ?? null);
            if ($desk !== null && $desk['currency'] !== '') {
                $data['doc_currency'] = $desk['currency'];
            }
        }
        parent::applyHomeCurrency($data);
    }

    /**
     * Pokladna dokladu (po denormalizaci z řady). Null bez pokladny / DB.
     *
     * @return array{code: string, name: string, currency: string}|null
     */
    protected function loadCashDesk(mixed $cashDeskId): ?array
    {
        if ($cashDeskId === null || $cashDeskId === '' || (int) $cashDeskId <= 0 || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [code], [name], [currency] FROM [economy_codebooks_cash_desks] WHERE [id] = %i',
            (int) $cashDeskId,
        );
        if ($row === null) {
            return null;
        }
        return [
            'code'     => (string) ($row['code'] ?? ''),
            'name'     => (string) ($row['name'] ?? ''),
            'currency' => strtolower(trim((string) ($row['currency'] ?? ''))),
        ];
    }
}
