<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\RecordCode;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Numbering\NumberPattern;
use Shipard\Core\Settings\SettingsStore;

class NumberSeriesDocument extends Document
{
    /** Doménové placeholdery vzorce nad obecnými z NumberPattern: `%D` = kód typu dokladu. */
    private const DOMAIN_PLACEHOLDERS = ['D'];
    private const ALLOWED_RESET_SCOPES = ['none', 'fiscal_year'];

    /**
     * Vazby řady na entitu (docTypes[].series_binding → FK sloupec řady).
     * Sdílená mapa s BoundNumberSeriesProvisioner — přidání vazby = jeden
     * záznam tady + sloupec v docs_core_number_series.jsonc.
     *
     * @var array<string, array{table: string, required: string, notAllowed: string, notFound: string, deleted: string}>
     */
    public const BINDINGS = [
        'cash_desk' => [
            'table'      => 'economy_codebooks_cash_desks',
            'required'   => 'Pokladna je pro tento typ dokladu povinná',
            'notAllowed' => 'Pokladna nepatří k tomuto typu dokladu',
            'notFound'   => 'Pokladna neexistuje',
            'deleted'    => 'Pokladna je smazaná',
        ],
        'warehouse' => [
            'table'      => 'economy_codebooks_warehouses',
            'required'   => 'Sklad je pro tento typ dokladu povinný',
            'notAllowed' => 'Sklad nepatří k tomuto typu dokladu',
            'notFound'   => 'Sklad neexistuje',
            'deleted'    => 'Sklad je smazaný',
        ],
    ];

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        // Uložení může být částečné: kód řady jde měnit i u řady ve stavu
        // V pořádku a formulář ho pak posílá samotný
        // (TableForm::getReadOnlyEditableColumns). Co payload nenese, drží
        // uložený řádek — bez merge by částečná aktualizace padala na
        // povinných polích.
        $current   = !empty($data['id']) ? $this->loadCurrent((int) $data['id']) : null;
        $effective = array_merge($current ?? [], $data);

        $this->validateBinding($effective, $result);
        $this->validateCode($data, $current, $result);

        if (empty($effective['name'])) {
            $result->addError('name', 'Název řady je povinný', 'required');
        }
        if (empty($effective['doc_type'])) {
            $result->addError('doc_type', 'Typ dokladu je povinný', 'required');
        }
        // Vzorec a kód v čísle dokladu validuje jádro číslování (#110 D17);
        // chyby s cílem „code“ patří ke kódu v čísle, ostatní ke vzorci.
        $patternErrors = NumberPattern::validate(
            (string) ($effective['doc_number_pattern'] ?? ''),
            (string) ($effective['doc_number_code'] ?? ''),
            self::DOMAIN_PLACEHOLDERS,
        );
        foreach ($patternErrors as $error) {
            $result->addError(
                $error['target'] === NumberPattern::TARGET_CODE ? 'doc_number_code' : 'doc_number_pattern',
                $error['message'],
                $error['code'],
            );
        }

        if (!empty($effective['reset_scope'])
            && !in_array($effective['reset_scope'], self::ALLOWED_RESET_SCOPES, true)
        ) {
            $result->addError('reset_scope', 'Neplatný typ restartu', 'invalid_value');
        }

        $validFrom = self::isoDate($effective['valid_from'] ?? null);
        $validTo   = self::isoDate($effective['valid_to'] ?? null);
        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            $result->addError(
                'valid_to',
                'Konec platnosti musí být později než začátek',
                'invalid_range',
            );
        }

        $this->validateSender($data, $result);

        return $result;
    }

    /**
     * Řada bez kódu ho dostane z generátoru (#166 D17): nová vždy,
     * existující jen když ho ještě nemá (založená mimo Document, před
     * doplněním při `ds-upgrade`). Vyplněný kód se nikdy nepřepisuje.
     */
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $effective = array_merge($originalData ?? [], $data);
        if (trim((string) ($effective['code'] ?? '')) !== '') {
            return;
        }
        $generator = $this->codeGenerator();
        if ($generator !== null) {
            $data['code'] = $generator->generate($effective);
        }
    }

    /**
     * Kód řady (#166 D16, D17) — přirozený klíč záznamu. Prázdný kód není
     * chyba: doplní ho `beforeSave`. Chyba je tvar mimo {@see RecordCode},
     * kód, který už má jiná řada (i v koši — unikátní index platí pro
     * všechny řádky), a vymazání kódu, který řada už má.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $current uložený řádek, null = nová řada
     */
    private function validateCode(array &$data, ?array $current, ValidationResult $result): void
    {
        if (!array_key_exists('code', $data)) {
            return;
        }
        $code = trim((string) ($data['code'] ?? ''));
        // Prázdný řetězec do sloupce nesmí — druhý by porušil unikátní index.
        $data['code'] = $code === '' ? null : $code;

        if ($code === '') {
            if ($current !== null && trim((string) ($current['code'] ?? '')) !== '') {
                $result->addError('code', 'Kód řady nejde vymazat', 'required');
            }
            return;
        }
        if (!RecordCode::isValid($code)) {
            $result->addError('code', 'Kód řady ' . RecordCode::SHAPE_HINT, 'invalid_value');
            return;
        }
        if (strlen($code) > NumberSeriesCodeGenerator::MAX_LENGTH) {
            $result->addError(
                'code',
                'Kód řady smí mít nejvýš ' . NumberSeriesCodeGenerator::MAX_LENGTH . ' znaků',
                'invalid_value',
            );
            return;
        }

        $holder = $this->codeGenerator()?->holder($code, (int) ($data['id'] ?? 0));
        if ($holder !== null) {
            $result->addError(
                'code',
                "Kód „{$code}“ už má řada „{$holder['name']}“" . ($holder['deleted'] ? ' (v koši)' : ''),
                'duplicate',
            );
        }
    }

    /** Datum v ISO tvaru — DB vrací DateTime, formulář řetězec; prázdné = null. */
    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return ?array<string, mixed> */
    protected function loadCurrent(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', NumberSeriesCodeGenerator::TABLE, $id);
        return $row !== null ? $row->toArray() : null;
    }

    /** Bez DB (unit test bez seamu) se kód negeneruje ani nehlídá unikátnost. */
    protected function codeGenerator(): ?NumberSeriesCodeGenerator
    {
        return $this->db !== null ? new NumberSeriesCodeGenerator($this->db) : null;
    }

    /**
     * Odesílatel dokladů řady (#90 D39): prázdná volba = automaticky (NULL),
     * jinak jen adresa, ze které zdroj dat smí odesílat. Jméno odesílatele
     * jde do hlavičky From — bez konců řádků.
     *
     * @param array<string, mixed> $data
     */
    private function validateSender(array &$data, ValidationResult $result): void
    {
        if (array_key_exists('email_from_name', $data)) {
            $name = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($data['email_from_name'] ?? '')));
            $data['email_from_name'] = $name === '' ? null : $name;
        }

        if (!array_key_exists('email_from', $data)) {
            return;
        }
        $email = trim((string) ($data['email_from'] ?? ''));
        $data['email_from'] = $email === '' ? null : $email;
        if ($email === '') {
            return;
        }

        if (!AddressList::isValid($email)) {
            $result->addError('email_from', 'Adresa odesílatele není platná e-mailová adresa', 'invalid_email');
            return;
        }
        if ($this->db !== null && !$this->allowedSenders()->isAllowed($email)) {
            $result->addError(
                'email_from',
                'Z této adresy zdroj dat odesílat nesmí — vyberte výchozí adresu nebo aktivního odesílatele pošty',
                'sender_not_allowed',
            );
        }
    }

    /** Testovací šev — adresy, ze kterých zdroj dat smí odesílat. */
    protected function allowedSenders(): AllowedSenders
    {
        $connection = new DataSourceConnection($this->db);
        return new AllowedSenders($connection, new SettingsStore($connection));
    }

    /**
     * Vazba řady na entitu podle `series_binding` typu dokladu: vázaný typ
     * má svůj FK povinný a ostatní vazby prázdné; nevázaný typ má všechny
     * vazby prázdné. Odkazovaná entita musí existovat a nebýt smazaná.
     * Bez configu (typ nelze určit) se kontrola přeskočí.
     *
     * @param array<string, mixed> $data
     */
    private function validateBinding(array $data, ValidationResult $result): void
    {
        $docTypes = $this->config?->cfgItem('docs.core.docTypes');
        $docType  = (string) ($data['doc_type'] ?? '');
        if (!is_array($docTypes) || $docType === '' || !is_array($docTypes[$docType] ?? null)) {
            return;
        }

        $binding = $docTypes[$docType]['series_binding'] ?? null;
        if ($binding !== null && !isset(self::BINDINGS[$binding])) {
            $result->addError('doc_type', "Neznámá vazba řady „{$binding}“", 'unknown_binding');
            return;
        }

        foreach (self::BINDINGS as $column => $meta) {
            $value = (int) ($data[$column] ?? 0);

            if ($column !== $binding) {
                if ($value > 0) {
                    $result->addError($column, $meta['notAllowed'], 'binding_not_allowed');
                }
                continue;
            }

            if ($value <= 0) {
                $result->addError($column, $meta['required'], 'required');
                continue;
            }
            if ($this->db === null) {
                continue;
            }
            $row = $this->db->fetch(
                'SELECT [docState] FROM [' . $meta['table'] . '] WHERE [id] = %i',
                $value,
            );
            if ($row === null) {
                $result->addError($column, $meta['notFound'], 'not_found');
            } elseif ((int) $row['docState'] === 90) {
                $result->addError($column, $meta['deleted'], 'invalid_state');
            }
        }
    }
}
