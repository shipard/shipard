<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\SetupReferences;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Economy\Assets\AssetCategories;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;

/**
 * Sekce `assets/` při dumpu (#131 fáze 2c, D38, D40): jeden soubor per
 * karta (`shpd.dataset.asset.v1`) — hlavička s přirozenými klíči místo id
 * (typ názvem, účetní skupina kódem, vlastník identifikátory) a potvrzené
 * hodnotové události s původem, jaký mají v databázi. Pořadí podle
 * inventárního čísla; slug = inventární číslo.
 *
 * Nepřenáší se (D38): karta bez inventárního čísla (koncept bez identity,
 * varování), systémové odpisy (původ `system` — odvozené, po seedu je
 * založí Odpisy za období; jedno varování per karta), nepotvrzené události
 * (varování), vazba na účetní doklad (`doc_head`). Stav V opravě se
 * přenáší jako V pořádku; data pořízení a vyřazení i cena jen u drobného
 * majetku (dlouhodobý je bere z událostí, D13).
 */
class AssetExporter implements RecordExporter
{
    public const SECTION = AssetSeeder::SECTION;
    public const FORMAT = AssetSeeder::FORMAT;

    /** Štíhlá identifikace vlastníka — osoba sama je v `persons/`. */
    public const PARTY_KEYS = ['name', 'country', 'companyId', 'taxId', 'vatId'];

    private const DELETED = 90;

    /** @var list<string> */
    private array $warnings = [];

    private readonly SetupReferences $references;
    private readonly AssetCategories $categories;

    /**
     * @param array<string, TableDefinition> $tables aktivní tabulky DS (SetupReferences)
     */
    public function __construct(
        private readonly Connection $db,
        ?ConfigRuntime $config = null,
        array $tables = [],
    ) {
        $this->references = new SetupReferences($db, $tables);
        $this->categories = new AssetCategories($config);
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function exportAll(): array
    {
        return $this->export(null);
    }

    /** @param list<int> $ids */
    public function exportByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids === [] ? [] : $this->export($ids);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<int>|null $ids
     * @return list<ExportedRecord>
     */
    private function export(?array $ids): array
    {
        $sql = 'SELECT * FROM [' . AssetDocument::TABLE . '] WHERE [docState] <> %i';
        $args = [self::DELETED];
        if ($ids !== null) {
            $sql .= ' AND [id] IN %in';
            $args[] = $ids;
        }
        $sql .= ' ORDER BY [asset_number], [id]';

        $out = [];
        foreach ($this->db->fetchAll($sql, ...$args) as $row) {
            $record = $this->record(is_array($row) ? $row : $row->toArray());
            if ($record !== null) {
                $out[] = $record;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $card */
    private function record(array $card): ?ExportedRecord
    {
        $id = (int) $card['id'];
        $number = V::str($card['asset_number'] ?? null);
        if ($number === null) {
            $this->warnings[] = "assets: karta #{$id} bez inventárního čísla (koncept) nemá v sadě identitu — nepřenáší se";
            return null;
        }
        $state = self::stateName((int) ($card['docState'] ?? 0));
        if ($state === null) {
            $this->warnings[] = "assets: karta {$number}: stav {$card['docState']} formát nezná — nepřenáší se";
            return null;
        }
        $category = (string) ($card['category'] ?? '');
        $longTerm = $this->categories->isLongTerm($category);

        $typeId = V::int($card['asset_type'] ?? null);
        $type = $this->references->export('economy_assets_types', $typeId);
        if ($typeId !== null && $typeId > 0 && $type === null) {
            $this->warnings[] = "assets: karta {$number}: typ majetku #{$typeId} neexistuje — nepřenáší se";
        }
        $groupId = V::int($card['accounting_group'] ?? null);
        $group = $this->references->export('economy_assets_accounting_groups', $groupId);
        if ($groupId !== null && $groupId > 0 && $group === null) {
            $this->warnings[] = "assets: karta {$number}: účetní skupina #{$groupId} neexistuje — nepřenáší se";
        }

        $asset = [
            'assetNumber'     => $number,
            'name'            => V::str($card['name'] ?? null),
            'shortName'       => V::str($card['short_name'] ?? null),
            'note'            => V::str($card['note'] ?? null),
            'type'            => $type,
            'category'        => $category,
            'tracking'        => V::str($card['tracking'] ?? null),
            'accountingGroup' => $group,
            'foreign'         => !empty($card['is_foreign']) ? true : null,
            'owner'           => $this->party(V::int($card['owner'] ?? null), $number),
            // Dlouhodobý majetek data pořízení a vyřazení i cenu nenese (D13, D38).
            'acquiredDate'    => $longTerm ? null : V::date($card['acquired_date'] ?? null),
            'disposedDate'    => $longTerm ? null : V::date($card['disposed_date'] ?? null),
            'price'           => $longTerm ? null : V::float($card['price'] ?? null),
            'taxMethod'       => V::str($card['tax_method'] ?? null),
            'taxRule'         => V::str($card['tax_rule'] ?? null),
            'accMethod'       => V::str($card['acc_method'] ?? null),
            'accMonths'       => V::int($card['acc_months'] ?? null),
            'state'           => $state,
        ];

        $data = V::prune([
            'format' => self::FORMAT,
            'asset'  => $asset,
            'events' => $this->events($id, $number),
        ]);
        return new ExportedRecord($id, $number, $data);
    }

    /** Stav obálky; V opravě se přenáší jako V pořádku (data karty platí). */
    public static function stateName(int $docState): ?string
    {
        if ($docState === AssetDocument::STATE_EDIT) {
            $docState = AssetDocument::STATE_CONFIRMED;
        }
        $name = array_search($docState, AssetSeeder::STATES, true);
        return $name === false ? null : $name;
    }

    /**
     * Potvrzené události karty chronologicky (datum, pořadí druhu v rámci
     * dne, id); systémové odpisy a nepotvrzené události se vynechají.
     *
     * @return list<array<string, mixed>>
     */
    private function events(int $assetId, string $number): array
    {
        $rows = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM [' . AssetEventDocument::TABLE . '] WHERE [asset] = %i AND [docState] <> %i ORDER BY [event_date], [id]',
            $assetId,
            self::DELETED,
        ) as $row) {
            $rows[] = is_array($row) ? $row : $row->toArray();
        }
        usort($rows, static fn(array $a, array $b): int => AssetEventDocument::orderKey($a) <=> AssetEventDocument::orderKey($b));

        $out = [];
        $system = 0;
        $unconfirmed = 0;
        foreach ($rows as $r) {
            if ((int) ($r['docState'] ?? 0) !== AssetEventDocument::STATE_CONFIRMED) {
                $unconfirmed++;
                continue;
            }
            $origin = (string) ($r['origin'] ?? AssetEvent::ORIGIN_MANUAL);
            if ($origin === AssetEvent::ORIGIN_SYSTEM) {
                $system++;
                continue;
            }
            $out[] = V::prune([
                'kind'            => V::str($r['event_kind'] ?? null),
                'scope'           => V::str($r['scope'] ?? null),
                'date'            => V::date($r['event_date'] ?? null),
                'periodBegin'     => V::date($r['period_begin'] ?? null),
                'periodEnd'       => V::date($r['period_end'] ?? null),
                'amount'          => V::float($r['amount'] ?? null) ?? 0.0,
                'accumulated'     => V::float($r['accumulated'] ?? null),
                'unitsDone'       => V::int($r['units_done'] ?? null),
                'priceIncreased'  => !empty($r['price_increased']) ? true : null,
                'originalDate'    => V::date($r['original_date'] ?? null),
                'claimUnrecorded' => !empty($r['claim_unrecorded']) ? true : null,
                'halfYear'        => !empty($r['half_year']) ? true : null,
                'note'            => V::str($r['note'] ?? null),
                'origin'          => $origin,
            ]);
        }
        if ($system > 0) {
            $this->warnings[] = "assets: karta {$number}: {$system} systémových odpisů se nepřenáší — po seedu je založí znovu Odpisy za období";
        }
        if ($unconfirmed > 0) {
            $this->warnings[] = "assets: karta {$number}: {$unconfirmed} nepotvrzených událostí se nepřenáší";
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function party(?int $personId, string $number): ?array
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }
        $party = CanonicalParty::fromPerson($this->db, $personId);
        if ($party === null) {
            $this->warnings[] = "assets: karta {$number}: vlastník #{$personId} neexistuje — nepřenáší se";
            return null;
        }
        $out = [];
        foreach (self::PARTY_KEYS as $key) {
            $out[$key] = $party[$key] ?? null;
        }
        return V::prune($out);
    }
}
