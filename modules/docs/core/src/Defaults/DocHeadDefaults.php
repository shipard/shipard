<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Defaults;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Docs\Core\DefaultBankAccountResolver;
use Shipard\Module\Docs\Core\DocTypes;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Výchozí hodnoty hlavičky nového dokladu (#166 D40, D41) — jedno místo pro
 * hook formuláře (`DocsHeadsFormBase::applyNewRecordDefaults()`) i pro pokyn
 * výměnného formátu (`DocumentApplier`). Pracuje nad sloupci
 * `docs_core_heads`, bez znalosti formuláře i formátu.
 *
 * **Kontrakt:** doplňuje se jen sloupec, jehož klíč v datech **chybí**.
 * Přítomný klíč vyhrává — i s hodnotou `null` („záměrně prázdné“, D50),
 * stejně jako u autora dokladu (`DocAuthorResolver`). Co volající považuje
 * za „nezadáno“ (prázdné pole formuláře, výchozí hodnota sloupce ze
 * schématu), si před voláním odstraní sám.
 *
 * Rozdíly mezi typy dokladu řídí atributy typu (`DocTypes` — `vat`,
 * `own_bank_account`, `series_binding`), ne podtřída volajícího.
 *
 * Sem **nepatří** hodnoty, které dopočítává `DocDocument::beforeSave()`
 * (účetní datum, DUZP, DPPD, splatnost, autor) — dokument nepozná „uživatel
 * pole vymazal“ od „nikdo ho neuvedl“, a právě proto výchozí hodnoty
 * z tohoto místa do `beforeSave` nepatří.
 */
final class DocHeadDefaults
{
    /** Stavy, ve kterých je záznam číselníku aktivní (Koncept, V pořádku, V opravě). */
    private const ACTIVE_STATES = [10, 40, 80];

    /** Způsoby úhrady (`docs.core.paymentMethods`): Hotově, Převodem. */
    private const PAYMENT_CASH = 0;
    private const PAYMENT_BANK_TRANSFER = 1;

    private ?SettingsStore $settings = null;

    /** @var array<string, array{id: int, code: string, name: string, currency: string}|null> */
    private array $cashDeskCache = [];

    public function __construct(
        private readonly ?DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
    ) {
    }

    /**
     * Doplní chybějící sloupce hlavičky. Pořadí je podstatné: měna a způsob
     * úhrady nezávisí na ničem, registrace DPH na režimu DPH a na datu.
     *
     * @param array<string, mixed> $head sloupce hlavičky; musí nést `doc_type`
     * @param bool $issueDate doplnit datum vystavení dnešním dnem — import
     *        historie ho nést musí (D48), tam se nedoplňuje
     * @param bool $registrationValidity registrace musí platit k datu dokladu;
     *        import platnost nezkoumá
     * @return list<string> doplněné sloupce
     */
    public function apply(array &$head, bool $issueDate = true, bool $registrationValidity = true): array
    {
        $docType = (string) ($head['doc_type'] ?? '');
        $filled = [];

        if (!array_key_exists('doc_currency', $head)) {
            $head['doc_currency'] = $this->currency($head);
            $filled[] = 'doc_currency';
        }
        if (!array_key_exists('payment_method', $head)) {
            $head['payment_method'] = $this->paymentMethod($docType);
            $filled[] = 'payment_method';
        }
        if (!array_key_exists('vat_mode', $head)) {
            $head['vat_mode'] = $this->vatMode($docType);
            $filled[] = 'vat_mode';
        }
        if ($issueDate && !array_key_exists('issue_date', $head)) {
            $head['issue_date'] = date('Y-m-d');
            $filled[] = 'issue_date';
        }
        // vat_mode = 0 je platná hodnota, proto (int) a ne empty().
        if (!array_key_exists('vat_registration', $head) && (int) ($head['vat_mode'] ?? 1) !== 0) {
            $registration = $this->vatRegistration(
                $registrationValidity ? $this->registrationDate($head) : null,
            );
            if ($registration !== null) {
                $head['vat_registration'] = $registration['id'];
                $filled[] = 'vat_registration';
            }
        }
        if (!array_key_exists('bank_account', $head) && DocTypes::usesOwnBankAccount($this->config, $docType)) {
            $account = $this->bankAccount();
            if ($account !== null) {
                $head['bank_account'] = $account;
                $filled[] = 'bank_account';
            }
        }
        if (!array_key_exists('total_rounding_auto', $head)) {
            $head['total_rounding_auto'] = $this->totalRoundingAuto($docType) ? 1 : 0;
            $filled[] = 'total_rounding_auto';
        }

        return $filled;
    }

    /**
     * Režim DPH nového dokladu: typ bez DPH (účetní doklad) a zdroj dat
     * s vypnutou agendou DPH (`economy.vatAgenda === false`, neplátce) →
     * Bez DPH; jinak ze základu. Nerozhodnutý klíč agendy se chová jako
     * plátce — nerozhodnuto nesmí měnit dnešní chování.
     */
    public function vatMode(string $docType): int
    {
        if (!DocTypes::hasVat($this->config, $docType)) {
            return 0;
        }
        return $this->setting('economy.vatAgenda') === false ? 0 : 1;
    }

    /** Způsob úhrady: typ vázaný na pokladnu Hotově, jinak Převodem. */
    public function paymentMethod(string $docType): int
    {
        return DocTypes::isCashDeskBound($this->config, $docType)
            ? self::PAYMENT_CASH
            : self::PAYMENT_BANK_TRANSFER;
    }

    /**
     * Měna dokladu: u typu vázaného na pokladnu měna pokladny (z `cash_desk`
     * hlavičky, jinak z číselné řady), jinak — a bez známé pokladny — domácí
     * měna zdroje dat.
     *
     * @param array<string, mixed> $head
     */
    public function currency(array $head): string
    {
        return $this->cashDeskCurrency($head) ?? $this->homeCurrency();
    }

    /**
     * Měna pokladny dokladu vázaného na pokladnu; null u nevázaného typu
     * a bez dohledané pokladny.
     *
     * @param array<string, mixed> $head
     */
    public function cashDeskCurrency(array $head): ?string
    {
        if (!DocTypes::isCashDeskBound($this->config, (string) ($head['doc_type'] ?? ''))) {
            return null;
        }
        $currency = $this->cashDesk($head)['currency'] ?? '';
        return $currency !== '' ? $currency : null;
    }

    /**
     * Pokladna dokladu: z `cash_desk` hlavičky (uložený doklad), jinak
     * z číselné řady (`number_series` → `docs_core_number_series.cash_desk`).
     *
     * @param array<string, mixed> $head
     * @return array{id: int, code: string, name: string, currency: string}|null
     */
    public function cashDesk(array $head): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $deskId = (int) ($head['cash_desk'] ?? 0);
        $seriesId = (int) ($head['number_series'] ?? 0);
        if ($deskId <= 0 && $seriesId <= 0) {
            return null;
        }
        $cacheKey = $deskId > 0 ? "desk:{$deskId}" : "series:{$seriesId}";
        if (array_key_exists($cacheKey, $this->cashDeskCache)) {
            return $this->cashDeskCache[$cacheKey];
        }

        $row = $deskId > 0
            ? $this->db->fetchRow(
                'SELECT `id`, `code`, `name`, `currency` FROM `economy_codebooks_cash_desks` WHERE `id` = %i',
                $deskId,
            )
            : $this->db->fetchRow(
                'SELECT cd.`id`, cd.`code`, cd.`name`, cd.`currency`'
                . ' FROM `docs_core_number_series` s'
                . ' JOIN `economy_codebooks_cash_desks` cd ON cd.`id` = s.`cash_desk`'
                . ' WHERE s.`id` = %i',
                $seriesId,
            );

        return $this->cashDeskCache[$cacheKey] = is_array($row) && !empty($row['id']) ? [
            'id'       => (int) $row['id'],
            'code'     => (string) ($row['code'] ?? ''),
            'name'     => (string) ($row['name'] ?? ''),
            'currency' => strtolower(trim((string) ($row['currency'] ?? ''))),
        ] : null;
    }

    /**
     * Domácí měna zdroje dat (`economy.homeCurrency`, docs/ds-setup.md §5.2);
     * nerozhodnutý klíč → `czk`.
     */
    public function homeCurrency(): string
    {
        $value = $this->setting('economy.homeCurrency');
        return is_string($value) && $value !== '' ? strtolower($value) : 'czk';
    }

    /**
     * Výchozí registrace DPH zdroje dat: první aktivní podle země a id.
     *
     * S datem se bere jen registrace k němu platná (`valid_from` /
     * `valid_to`, null = neomezeno) — plátcovství je registrace k datu
     * (docs/ds-setup.md D5, #97 D2); null pak znamená, že zdroj k datu
     * plátcem nebyl. Bez data (import) platnost nerozhoduje. Registrace bez
     * země se nepočítá — bez ní není číselník kódů DPH.
     *
     * @param string|null $date `YYYY-MM-DD`, null = bez ohledu na platnost
     * @return array{id: int, country: string}|null
     */
    public function vatRegistration(?string $date): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $date === null
            ? $this->db->fetchRow(
                'SELECT `id`, `country` FROM `economy_codebooks_vat_registrations`'
                . ' WHERE `docState` IN (%i, %i, %i)'
                . ' ORDER BY `country`, `id` LIMIT 1',
                ...self::ACTIVE_STATES,
            )
            : $this->db->fetchRow(
                'SELECT `id`, `country` FROM `economy_codebooks_vat_registrations`'
                . ' WHERE `docState` IN (%i, %i, %i)'
                . ' AND (`valid_from` IS NULL OR `valid_from` <= %s)'
                . ' AND (`valid_to` IS NULL OR `valid_to` >= %s)'
                . ' ORDER BY `country`, `id` LIMIT 1',
                ...[...self::ACTIVE_STATES, $date, $date],
            );
        if ($row === null || !isset($row['id'], $row['country'])) {
            return null;
        }
        $country = strtolower(trim((string) $row['country']));
        return $country !== '' ? ['id' => (int) $row['id'], 'country' => $country] : null;
    }

    /**
     * Datum, ke kterému se posuzuje platnost registrace: DUZP, bez něj datum
     * vystavení, bez obou dnešek.
     *
     * @param array<string, mixed> $head
     */
    public function registrationDate(array $head): string
    {
        return VatRateDates::dateString($head['vat_duzp'] ?? null)
            ?? VatRateDates::dateString($head['issue_date'] ?? null)
            ?? date('Y-m-d');
    }

    /** Výchozí vlastní bankovní účet zdroje dat ({@see DefaultBankAccountResolver}); null bez něj. */
    public function bankAccount(): ?int
    {
        return $this->db !== null ? DefaultBankAccountResolver::resolve($this->db) : null;
    }

    /**
     * Příznak automatického zaokrouhlení nového dokladu: podle typu
     * (`docTypes[].total_rounding_auto`). Směr pokladního dokladu srovná
     * `DocDocument::applyTotalRoundingAuto()` při uložení — u výdaje příznak
     * vynuluje.
     */
    public function totalRoundingAuto(string $docType): bool
    {
        return DocTypes::hasTotalRoundingAuto($this->config, $docType);
    }

    private function setting(string $key): mixed
    {
        if ($this->db === null) {
            return null;
        }
        return ($this->settings ??= new SettingsStore($this->db))->get($key);
    }
}
