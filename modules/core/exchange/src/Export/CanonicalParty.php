<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * Kanonická strana dokladu (`$defs/Party` výměnného formátu) z osoby
 * adresáře: identifikace, první platná adresa, kontakt. Sdílí ji exportér
 * datových sad (`DocumentExporter`) a generátory dokladů, které skládají
 * kanonický payload z interních dat (periodická fakturace, #110).
 *
 * Strana je objekt s `ref` a daty (#166 D34): exportér píše referenci
 * přirozeným klíčem (`"@<person_id>"`), generátory id. Data vedle `ref`
 * jsou dobový snapshot v importním módu; mimo něj je applier nečte.
 */
final class CanonicalParty
{
    private const ACTIVE_STATES = [10, 40, 80];

    /**
     * Strana dokladu: `ref` + data osoby.
     *
     * @param bool $keyReference reference přirozeným klíčem (`"@<person_id>"`,
     *        přenosná mezi zdroji dat) místo id; osoba bez `person_id`
     *        dostane referenci id
     * @return array<string, mixed>|null null = osoba neexistuje
     */
    public static function withReference(Connection $db, ?int $personId, bool $keyReference = false): ?array
    {
        $party = self::fromPerson($db, $personId);
        if ($party === null) {
            return null;
        }
        $key = $keyReference
            ? V::str($db->fetchSingle('SELECT [person_id] FROM [base_persons_persons] WHERE [id] = %i', $personId))
            : null;
        return ['ref' => $key !== null ? '@' . $key : $personId] + $party;
    }

    /**
     * Data osoby bez reference (sdílí je i exportéry zakázek a majetku).
     *
     * @return array<string, mixed>|null null = osoba neexistuje
     */
    public static function fromPerson(Connection $db, ?int $personId): ?array
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }
        $p = $db->fetch('SELECT * FROM [base_persons_persons] WHERE [id] = %i', $personId);
        if ($p === null) {
            return null;
        }
        $p = is_array($p) ? $p : $p->toArray();
        $a = $db->fetch(
            'SELECT * FROM [base_persons_addresses] WHERE [person] = %i AND [docState] IN %in
             ORDER BY [order_pos], [address_type], [id] LIMIT 1',
            $personId,
            self::ACTIVE_STATES,
        );
        $a = $a === null ? [] : (is_array($a) ? $a : $a->toArray());

        $party = [
            'name'              => V::str($p['full_name'] ?? null),
            'country'           => V::countryLower($a['country'] ?? null),
            'companyId'         => V::str($p['company_id'] ?? null),
            'taxId'             => V::str($p['tax_id'] ?? null),
            'vatId'             => V::str($p['vat_id'] ?? null),
            'courtRegistration' => V::str($p['court_registration'] ?? null),
            'address'           => [
                'street'       => V::str($a['street'] ?? null),
                'houseNumber'  => V::str($a['house_number'] ?? null),
                'city'         => V::str($a['city'] ?? null),
                'cityPart'     => V::str($a['city_part'] ?? null),
                'zip'          => V::str($a['zip'] ?? null),
                'country'      => V::countryLower($a['country'] ?? null),
                'registryCode' => V::str($a['registry_code'] ?? null),
            ],
            'contact'           => [
                'email' => V::str($p['email'] ?? null),
                'phone' => V::str($p['phone'] ?? null),
                'web'   => V::str($p['web'] ?? null),
            ],
        ];

        return V::prune($party);
    }
}
