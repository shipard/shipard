<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Base\Persons\Send\SendPurposes;

/**
 * `base_persons_persons` (+ adresy, bankovní účty, kontakty) →
 * `shpd.persons.person.v1`. Zrcadlo `PersonApplier::transformHeaderForCreate`
 * a create payloadů `AddressResolver` / `ContactResolver` /
 * `PersonApplier::buildBankAccountInsertPayload`.
 *
 * Země osoby: canonical ji vyžaduje, DB ji drží jen na adresách — bere se
 * z první adresy, jinak výchozí země DS. `applyOptions` se nastavují pro
 * seed do prázdného DS (`createOnly` + `identifiersOnly`), `dataset-seed
 * --no-reset` je přepíše.
 */
final class PersonExporter implements RecordExporter
{
    private const ACTIVE_STATES = [10, 40, 80];

    /** @var array<int, ?string> */
    private array $divisionCache = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $defaultCountry = 'cz',
    ) {}

    public function section(): string
    {
        return 'persons';
    }

    public function exportAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [base_persons_persons] WHERE [docState] <> 90
             ORDER BY [full_name], [company_id], [person_id], [id]',
        );
        return $this->exportRows($rows);
    }

    public function exportByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM [base_persons_persons] WHERE [id] IN %in AND [docState] <> 90
             ORDER BY [full_name], [company_id], [person_id], [id]',
            $ids,
        );
        return $this->exportRows($rows);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param iterable<\Dibi\Row|array<string, mixed>> $rows
     * @return list<ExportedRecord>
     */
    private function exportRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $person = is_array($row) ? $row : $row->toArray();
            $out[] = $this->exportPerson($person);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $p
     */
    public function exportPerson(array $p): ExportedRecord
    {
        $id = (int) $p['id'];
        $addresses = $this->fetchChildren('base_persons_addresses', $id, '[order_pos], [address_type], [id]');
        $banks = $this->fetchChildren('base_persons_bank_accounts', $id, '[order_pos], [account_number], [iban], [id]');
        $contacts = $this->fetchChildren('base_persons_contacts', $id, '[order_pos], [name], [id]');

        $personType = $this->personType($p);
        $docState = (int) ($p['docState'] ?? 40);

        $country = null;
        foreach ($addresses as $a) {
            $country = V::countryLower($a['country'] ?? null);
            if ($country !== null) {
                break;
            }
        }

        $canonical = [
            'format'        => 'shpd.persons.person',
            'formatVersion' => '1.2',
            'source'        => $this->source($p),
            'personType'    => $personType,
            'country'       => $country ?? strtolower($this->defaultCountry),
            'personId'      => V::str($p['person_id'] ?? null),
            'companyId'     => V::str($p['company_id'] ?? null),
            'taxId'         => V::str($p['tax_id'] ?? null),
            'vatId'         => V::str($p['vat_id'] ?? null),
            'courtRegistration' => V::str($p['court_registration'] ?? null),
            'govEBoxId'     => V::str($p['gov_e_box_id'] ?? null),
            // Firma: části jména (last_name = kopie full_name) odvozuje
            // PersonDocument — exportuje se jen fullName, jinak by round-trip
            // vyráběl přírůstek.
            'name'          => $personType === 'company' ? [
                'fullName'    => V::str($p['full_name'] ?? null),
            ] : [
                'fullName'    => V::str($p['full_name'] ?? null),
                'titleBefore' => V::str($p['title_before'] ?? null),
                'firstName'   => V::str($p['first_name'] ?? null),
                'middleName'  => V::str($p['middle_name'] ?? null),
                'lastName'    => V::str($p['last_name'] ?? null),
                'titleAfter'  => V::str($p['title_after'] ?? null),
            ],
            'personal'      => $personType === 'person' ? [
                'birthDate'    => V::date($p['birth_date'] ?? null),
                'nationalId'   => V::str($p['national_id'] ?? null),
                'idCardNumber' => V::str($p['id_card_number'] ?? null),
            ] : null,
            'contact'       => [
                'email' => V::str($p['email'] ?? null),
                'phone' => V::str($p['phone'] ?? null),
                'web'   => V::str($p['web'] ?? null),
            ],
            'status'        => [
                'isClosed'   => ((int) ($p['is_closed'] ?? 0)) === 1 ? true : null,
                'closedDate' => V::date($p['closed_date'] ?? null),
                'isOwn'      => ((int) ($p['is_own'] ?? 0)) === 1 ? true : null,
                'docState'   => $docState,
            ],
            // Blok vždy (#94): spojování jako výslovné true/false, aby blok
            // přežil prune i u osoby bez jazyka — applier pak chybějící
            // `language` čte jako null. Bez extension docs.core sloupec není.
            'documents'     => [
                'language'              => V::str($p['language'] ?? null),
                'sendAttachmentsMerged' => array_key_exists('send_attachments_merged', $p)
                    ? ((int) $p['send_attachments_merged']) === 1
                    : null,
            ],
            'addresses'     => array_map(fn(array $a) => $this->address($a), $addresses),
            'bankAccounts'  => array_map(fn(array $b) => $this->bankAccount($b), $banks),
            'contacts'      => array_values(array_filter(
                array_map(fn(array $c) => $this->contact($c), $contacts),
                static fn(array $c): bool => ($c['name'] ?? null) !== null,
            )),
            'applyOptions'  => [
                'mergeStrategy'  => 'createOnly',
                'matchStrategy'  => 'identifiersOnly',
                'targetDocState' => in_array($docState, [10, 40], true) ? $docState : 40,
            ],
        ];

        $slug = V::str($p['full_name'] ?? null) ?? V::str($p['person_id'] ?? null) ?? 'person';

        return new ExportedRecord($id, $slug, V::prune($canonical));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>|null
     */
    private function source(array $p): ?array
    {
        $kind = V::str($p['source_kind'] ?? null);
        if ($kind === null) {
            return null;
        }
        // fetchedAt se neexportuje: applier ho při seedu razítkuje NOW()
        // (source_imported_at = okamžik importu), takže by dump → seed → dump
        // nebyl stabilní.
        return [
            'kind'        => $kind,
            'registryRef' => V::str($p['source_ref'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $p
     */
    private function personType(array $p): string
    {
        $type = (int) ($p['person_type'] ?? 0);
        if ($type === 1) {
            return 'person';
        }
        if ($type === 2) {
            return 'company';
        }
        // 0 = Neurčeno: firma, když má IČO, jinak fyzická osoba.
        return V::str($p['company_id'] ?? null) !== null ? 'company' : 'person';
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function address(array $a): array
    {
        $type = (int) ($a['address_type'] ?? 0);
        return [
            // Schema povoluje 1–4; DB default 0 (neurčeno) se exportuje jako 1.
            'addressType'       => $type >= 1 && $type <= 4 ? $type : 1,
            'name'              => V::str($a['name'] ?? null),
            'placeRegType'      => V::str($a['place_reg_type'] ?? null),
            'placeRegId'        => V::str($a['place_reg_id'] ?? null),
            'isStandardized'    => ((int) ($a['is_standardized'] ?? 0)) === 1 ? true : null,
            'street'            => V::str($a['street'] ?? null),
            'houseNumber'       => V::str($a['house_number'] ?? null),
            'orientationNumber' => V::str($a['orientation_number'] ?? null),
            'city'              => V::str($a['city'] ?? null),
            'cityPart'          => V::str($a['city_part'] ?? null),
            'district'          => V::str($a['district'] ?? null),
            'zip'               => V::str($a['zip'] ?? null),
            'country'           => V::countryLower($a['country'] ?? null),
            'registryCode'      => V::str($a['registry_code'] ?? null),
            'divisionCode'      => $this->divisionCode($a['division'] ?? null),
            'latitude'          => V::float($a['latitude'] ?? null),
            'longitude'         => V::float($a['longitude'] ?? null),
            'manualGps'         => ((int) ($a['manual_gps'] ?? 0)) === 1 ? true : null,
            'displayLine'       => V::str($a['display_line'] ?? null),
            'displayBlock'      => V::str($a['display_block'] ?? null),
            'orderPos'          => V::int($a['order_pos'] ?? null),
            'validFrom'         => V::date($a['valid_from'] ?? null),
            'validTo'           => V::date($a['valid_to'] ?? null),
            'note'              => V::str($a['note'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function bankAccount(array $b): array
    {
        return [
            'name'          => V::str($b['name'] ?? null),
            'accountNumber' => V::str($b['account_number'] ?? null),
            'iban'          => V::str($b['iban'] ?? null),
            'bic'           => V::str($b['bic'] ?? null),
            'currency'      => V::currencyUpper($b['currency'] ?? null),
            'source'        => V::int($b['source'] ?? null),
            'orderPos'      => V::int($b['order_pos'] ?? null),
            'validFrom'     => V::date($b['valid_from'] ?? null),
            'validTo'       => V::date($b['valid_to'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function contact(array $c): array
    {
        return [
            'name'      => V::str($c['name'] ?? null),
            'role'      => V::str($c['role'] ?? null),
            'email'     => V::str($c['email'] ?? null),
            'phone'     => V::str($c['phone'] ?? null),
            'note'      => V::str($c['note'] ?? null),
            'orderPos'  => V::int($c['order_pos'] ?? null),
            'validFrom' => V::date($c['valid_from'] ?? null),
            'validTo'   => V::date($c['valid_to'] ?? null),
            // Účely odesílání (1.2, #90 D34); u kontaktu bez účelu klíč
            // prořeže `prune`.
            'sendPurposes' => SendPurposes::decode($c['send_purposes'] ?? null) ?? [],
        ];
    }

    private function divisionCode(mixed $divisionId): ?string
    {
        $id = V::int($divisionId);
        if ($id === null || $id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $this->divisionCache)) {
            $row = $this->db->fetch('SELECT [code] FROM [world_divisions] WHERE [id] = %i', $id);
            $this->divisionCache[$id] = $row !== null ? V::str($row['code']) : null;
        }
        return $this->divisionCache[$id];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchChildren(string $table, int $personId, string $orderBy): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM [{$table}] WHERE [person] = %i AND [docState] IN %in ORDER BY {$orderBy}",
            $personId,
            self::ACTIVE_STATES,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = is_array($r) ? $r : $r->toArray();
        }
        return $out;
    }
}
