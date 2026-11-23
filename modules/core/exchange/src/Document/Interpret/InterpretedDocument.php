<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

/**
 * Výsledek interpretace papírové podoby dokladu (#166 D51, D52;
 * {@see DocumentInterpreter::interpret()}).
 *
 * **Hotový doklad** (`document`) je payload platný jako pokyn: každý odkaz
 * referencí (id), hodnoty DPH a zaokrouhlení uvedené, vše s výchozí hodnotou
 * výslovně včetně `null` — výchozí hodnoty pokynu se na něj neuplatní.
 * Částky z papíru nenese (pokyn by je bral jako kontrolní součet), signály
 * návrhu ani blok `_resolve` také ne.
 *
 * Co hotový doklad vyjádřit nemůže, nese zbytek: záznamy, které je před
 * uložením potřeba založit (`pending`), posun řádků proti původnímu payloadu
 * (`rowMap`), co se má po uložení naučit (`learn`) a rozhodnutí, která
 * interpretaci brání doklad dokončit (`decisionError`).
 */
final class InterpretedDocument
{
    public const PENDING_PERSON = 'person';
    public const PENDING_BANK_ACCOUNT = 'bankAccount';
    public const PENDING_ITEM = 'item';

    /**
     * @param array<string, mixed> $document hotový doklad — payload pokynu
     * @param array<string, mixed> $report bloky `_resolve` návrhu (strany,
     *        řádky, DPH, oprava, `valueRepairs`, souhrn) a nálezy (`issues`);
     *        cesty nálezů i indexy řádků míří do **původního** payloadu
     * @param list<array<string, mixed>> $pending záznamy k založení před
     *        uložením: `key` (`supplier` | `customer` | `balanceParty` |
     *        `supplierBank` | `rows.<index původního řádku>.item`), `kind`
     *        ({@see PENDING_PERSON} …), `table`, `payload` (data k založení)
     *        a `ref` — cesta v hotovém dokladu, kam přijde reference (null
     *        u bankovního účtu partnera: formát pro něj referenci nemá, pokyn
     *        ho po založení najde přesnou shodou). Položka nese navíc `row`
     *        (index původního řádku) a `unit` (id jednotky), účet `person`
     *        (id dodavatele, null = zakládaný dodavatel)
     * @param list<int> $rowMap index řádku hotového dokladu → index řádku
     *        původního payloadu (vynechané řádky v hotovém dokladu nejsou)
     * @param array{supplier: ?int, codes: list<array{row: int, supplierCode: string, supplierName: ?string, item: ?int, overwrite: bool}>} $learn
     *        dodavatelské kódy položek k naučení po uložení; `supplier` /
     *        `item` null = záznam se teprve založí (`pending`), `row` je
     *        index původního řádku, `overwrite` = výslovná volba položky
     *        přepisuje dřívější mapování
     * @param array{code: string, message: string}|null $decisionError
     *        reference čeká na rozhodnutí (`unresolved_required`) nebo volba
     *        uživatele neplatí (`conflict`) — doklad v této podobě uložit nejde
     * @param list<array<string, mixed>> $decisionIssues nálezy, které vznikají
     *        až při rozhodování nad volbami (uložení), ne v náhledu
     * @param list<array{path: string, code: string, message: string}> $limitations
     *        co hotový doklad nevyjádří přesně (jednotka bez systémového kódu,
     *        zvolený účet partnera) — cesty do původního payloadu
     */
    public function __construct(
        public readonly array $document,
        public readonly array $report,
        public readonly array $pending,
        public readonly array $rowMap,
        public readonly array $learn,
        public readonly ?array $decisionError = null,
        public readonly array $decisionIssues = [],
        public readonly array $limitations = [],
    ) {
    }

    /** Má zpráva nález se závažností chyba, nebo rozhodnutí, které brání uložení? */
    public function hasErrors(): bool
    {
        if ($this->decisionError !== null) {
            return true;
        }
        foreach ([...($this->report['issues'] ?? []), ...$this->decisionIssues] as $issue) {
            if (($issue['severity'] ?? null) === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * Hotový doklad s referencemi právě založených záznamů — tvar, který
     * vrací {@see PendingRecords::create()}: id stran podle klíče a položek
     * podle indexu původního řádku. Záznam bez id zůstává, jak je.
     *
     * @param array{supplier?: ?int, customer?: ?int, balanceParty?: ?int, supplierBank?: ?int, rowItems?: array<int, int>} $created
     * @return array<string, mixed>
     */
    public function withCreated(array $created): array
    {
        $document = $this->document;
        foreach ($this->pending as $entry) {
            $id = $entry['kind'] === self::PENDING_ITEM
                ? ($created['rowItems'][$entry['row']] ?? null)
                : ($created[$entry['key']] ?? null);
            if ($id === null || !is_string($entry['ref'] ?? null)) {
                continue;
            }
            $node = &$document;
            foreach (explode('.', $entry['ref']) as $segment) {
                if (!is_array($node)) {
                    $node = [];
                }
                if (!array_key_exists($segment, $node)) {
                    $node[$segment] = null;
                }
                $node = &$node[$segment];
            }
            $node = (int) $id;
            unset($node);
        }
        return $document;
    }
}
