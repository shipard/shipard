<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

/**
 * Builds a person snapshot (name, IDs, address, contact, bank account,
 * VAT registration) for a document party.
 *
 * Shared by DocDocument (frozen snapshots written at Confirm) and
 * DocsHeadsViewer (live party assembly for Koncept documents in detail).
 * The output shape is persisted in the supplier_snapshot / customer_snapshot
 * JSON columns — existing stored snapshots must stay compatible with it:
 * keys are only ever added, never renamed or removed.
 *
 * Partner bez vybrané adresy (`partner_address` prázdné — doklad z applieru,
 * z akce Vystavit opravný doklad, z periodické fakturace, nebo formulář,
 * kde ji uživatel nevybral) dostane **sídlo**, bez sídla první adresu
 * osoby: {@see buildPartner()}. Bez toho by daňový doklad neměl adresu
 * odběratele. Vlastní strana předává sídlo sama (`OwnCompanyResolver`).
 *
 * Takes a raw \Dibi\Connection because Document subclasses hold one.
 */
final class PersonSnapshotBuilder
{
    private const ADDRESS_TYPE_HEADQUARTERS = 1;

    public function __construct(
        private readonly \Dibi\Connection $db,
    ) {}

    /**
     * Snapshot partnera dokladu: vybraná adresa, bez ní výchozí adresa
     * osoby ({@see defaultAddressId()}).
     *
     * @return array<string, mixed>  empty array when the person doesn't exist
     */
    public function buildPartner(int $personId, mixed $addressId): array
    {
        if ($personId === 0) {
            return [];
        }
        return $this->build(
            $personId,
            !empty($addressId) ? $addressId : $this->defaultAddressId($personId),
            null,
            null,
        );
    }

    /**
     * Výchozí adresa osoby pro doklad: sídlo, bez sídla první adresa
     * v pořadí na osobě (docs/docs-mvp.md, výchozí hodnoty hlavičky). Jen
     * adresy mimo koš a platné k dnešku — stejně jako nabídka adres ve
     * formuláři.
     */
    public function defaultAddressId(int $personId): ?int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM [base_persons_addresses]
             WHERE [person] = %i AND [docState] <> 90
               AND ([valid_from] IS NULL OR [valid_from] <= CURDATE())
               AND ([valid_to] IS NULL OR [valid_to] >= CURDATE())
             ORDER BY ([address_type] = %i) DESC, [order_pos], [id]
             LIMIT 1',
            $personId,
            self::ADDRESS_TYPE_HEADQUARTERS,
        );
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Tvar snapshotu osoby s adresou z hodnot sloupců — **jediné místo
     * klíčů a jediné pravidlo prázdné hodnoty**. Staví jím {@see build()}
     * z adresáře i importní mód výměnného formátu z kanonické strany dokladu
     * (`DocumentApplier`, #166 D23), aby dobový snapshot z importu měl stejné
     * klíče i hodnoty jako snapshot z formuláře.
     *
     * Prázdná hodnota je vždy `null` (#166 D29): chybějící sloupec, `null`,
     * prázdný řetězec i řetězec ze samých mezer — u identifikátorů, zápisu
     * v rejstříku, kontaktu i adresy ({@see emptyToNull()}). Adresář drží
     * prázdné hodnoty u jedné osoby jako `''`, u druhé jako `NULL`; snapshot
     * ten rozdíl nepřenáší. Vyplněnou hodnotu nemění. `name` zůstává
     * řetězec. Uložené snapshoty se nepřepisují — čtenář bere `null`, `''`
     * i chybějící klíč stejně.
     *
     * Web a ID datové schránky přibyly s kontakty v zápatí tisku (#162
     * D133); snapshoty dokladů vystavených dřív je nemají a nedopočítávají
     * se — čtenář bere chybějící klíč jako prázdný.
     *
     * @param array<string, mixed> $person sloupce `base_persons_persons`
     * @param array<string, mixed>|null $address sloupce `base_persons_addresses`; null = bez adresy
     * @return array<string, mixed>
     */
    public static function shape(array $person, ?array $address): array
    {
        $snap = [
            'name'               => (string) ($person['full_name'] ?? ''),
            'company_id'         => self::emptyToNull($person['company_id']         ?? null),
            'tax_id'             => self::emptyToNull($person['tax_id']             ?? null),
            'vat_id'             => self::emptyToNull($person['vat_id']             ?? null),
            'court_registration' => self::emptyToNull($person['court_registration'] ?? null),
            'contact'            => [
                'email'        => self::emptyToNull($person['email']        ?? null),
                'phone'        => self::emptyToNull($person['phone']        ?? null),
                'web'          => self::emptyToNull($person['web']          ?? null),
                'gov_e_box_id' => self::emptyToNull($person['gov_e_box_id'] ?? null),
            ],
        ];
        if ($address !== null) {
            $snap['address'] = [
                'street'        => self::emptyToNull($address['street']        ?? null),
                'house_number'  => self::emptyToNull($address['house_number']  ?? null),
                'city'          => self::emptyToNull($address['city']          ?? null),
                'city_part'     => self::emptyToNull($address['city_part']     ?? null),
                'zip'           => self::emptyToNull($address['zip']           ?? null),
                'country'       => self::emptyToNull($address['country']       ?? null),
                'display_block' => self::emptyToNull($address['display_block'] ?? null),
                'display_line'  => self::emptyToNull($address['display_line']  ?? null),
            ];
        }
        return $snap;
    }

    /**
     * Pravidlo prázdné hodnoty snapshotu (#166 D29): prázdný řetězec a řetězec
     * ze samých mezer je `null`; cokoli jiného se vrací beze změny.
     */
    public static function emptyToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>  empty array when the person doesn't exist
     */
    public function build(
        int $personId,
        mixed $addressId,
        mixed $bankAccountId,
        mixed $vatRegistrationId,
    ): array {
        if ($personId === 0) {
            return [];
        }

        $personRow = $this->db->fetch(
            'SELECT * FROM [base_persons_persons] WHERE [id] = %i',
            $personId,
        );
        if ($personRow === null) {
            return [];
        }
        $person = $personRow->toArray();

        $addr = $addressId !== null
            ? $this->db->fetch('SELECT * FROM [base_persons_addresses] WHERE [id] = %i', (int) $addressId)
            : null;
        $snap = self::shape($person, $addr?->toArray());

        if ($bankAccountId !== null) {
            $bank = $this->db->fetch(
                'SELECT * FROM [economy_codebooks_bank_accounts] WHERE [id] = %i',
                (int) $bankAccountId,
            );
            if ($bank !== null) {
                $snap['bank_account'] = [
                    'name'           => self::emptyToNull($bank['name']           ?? null),
                    'account_number' => self::emptyToNull($bank['account_number'] ?? null),
                    'iban'           => self::emptyToNull($bank['iban']           ?? null),
                    'bic'            => self::emptyToNull($bank['bic']            ?? null),
                    'currency'       => self::emptyToNull($bank['currency']       ?? null),
                ];
            }
        }

        if ($vatRegistrationId !== null) {
            $reg = $this->db->fetch(
                'SELECT * FROM [economy_codebooks_vat_registrations] WHERE [id] = %i',
                (int) $vatRegistrationId,
            );
            if ($reg !== null) {
                $snap['vat_registration'] = [
                    'country' => self::emptyToNull($reg['country'] ?? null),
                    'vat_id'  => self::emptyToNull($reg['vat_id']  ?? null),
                ];
            }
        }

        return $snap;
    }
}
