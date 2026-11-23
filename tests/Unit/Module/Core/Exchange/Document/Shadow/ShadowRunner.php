<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\InterpretedDocument;
use Shipard\Module\Core\Exchange\Document\Interpret\ProposalReport;

/**
 * Stínové porovnání (#166 D57, U5): pro jeden vstup spustí **dnešní cestu
 * návrhu** (`DocumentApplier` v režimu návrh) a **novou cestu** (interpretace
 * → založení chybějících záznamů → pokyn nad hotovým dokladem) a vrátí
 * rozdíly. Prázdný seznam = obě cesty dávají totéž.
 *
 * Porovnává se:
 *
 *  - náhled — celý blok `_resolve` odpovědi návrhu (bloky stran a řádků,
 *    DPH, nálezy včetně textů a pořadí, souhrn, `computed`) proti zprávě
 *    interpretace doplněné o `computed` z náhledu pokynu, a vrácený payload;
 *  - uložení — data předaná k uložení (výstup `transform()`: hlavička,
 *    řádky, rekapitulace) a výsledek (uloženo × kód chyby);
 *  - úplnost hotového dokladu — pokyn nad ním nesmí doplnit žádnou výchozí
 *    hodnotu (`_resolve.defaults` prázdné, D52).
 *
 * Nová cesta běží první a izolovaně (`$isolated` — u databáze savepoint
 * vrácený zpět), aby dnešní cesta potom viděla zdroj dat v původním stavu.
 * Záznamy, které obě cesty zakládají, mají proto různá id; porovnání je
 * převádí přes klíč záznamu v `pending`.
 *
 * **Výjimky** — rozdíly, které nejsou chybou — jsou jen v {@see NORMALIZED}
 * a v poznámkách tasku `tasks/doc-interpretation-extract.md`.
 */
final class ShadowRunner
{
    /**
     * Sloupce, kde se chybějící klíč rovná výchozí hodnotě sloupce:
     * návrh bez odhadnutého módu zaokrouhlení klíč nepošle, pokyn pošle
     * výslovně „na haléře“ (0) — uložený doklad je stejný.
     */
    public const NORMALIZED = ['total_rounding_mode' => 0];

    /**
     * @param \Closure(): list<array<string, mixed>> $transformed data předaná k uložení od posledního `$reset`
     * @param \Closure(): void $reset
     * @param \Closure(\Closure(): mixed): mixed $isolated spustí práci a její zápisy vrátí zpět
     * @param \Closure(list<array<string, mixed>>): array<string, mixed> $createPending založí záznamy z `pending`
     */
    public function __construct(
        private readonly DocumentApplier $applier,
        private readonly DocumentInterpreter $interpreter,
        private readonly \Closure $transformed,
        private readonly \Closure $reset,
        private readonly \Closure $isolated,
        private readonly \Closure $createPending,
    ) {
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    public function preview(array $canonical): array
    {
        $interpreted = $this->interpreter->interpret($canonical);
        $old = $this->applier->preview($canonical, ApplyMode::Proposal);
        $new = $this->applier->preview($interpreted->document, ApplyMode::Instruction);

        $out = [];
        if (!$old->success || !$new->success) {
            $out[] = 'náhled: dnes ' . ($old->errorCode ?? 'ok') . ' × nově ' . ($new->errorCode ?? 'ok');
            return $out;
        }
        $instruction = $new->canonical['_resolve'] ?? [];
        $out = [...$out, ...self::defaultsDifference($instruction)];

        $paper = $old->canonical;
        $expected = $paper['_resolve'] ?? [];
        unset($paper['_resolve']);
        $newPaper = $interpreted->paper;
        unset($newPaper['_resolve']);

        return [
            ...$out,
            ...ShadowDiff::arrays('náhled._resolve', $expected, ProposalReport::withComputed($interpreted, $instruction)),
            ...ShadowDiff::arrays('náhled.payload', $paper, $newPaper),
        ];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array{differences: list<string>, result: ApplyResult} `result` = výsledek dnešní cesty
     */
    public function apply(array $canonical): array
    {
        $interpreted = $this->interpreter->interpret($canonical);

        // Nová cesta: jen když interpretace nenašla chybu — jinak by vstup
        // návrhu skončil stejně jako dnes před uložením.
        $new = null;
        if (!$interpreted->hasErrors()) {
            $new = ($this->isolated)(function () use ($interpreted): array {
                ($this->reset)();
                $created = $interpreted->pending !== [] ? ($this->createPending)($interpreted->pending) : [];
                $result = $this->applier->apply($interpreted->withCreated($created), ApplyMode::Instruction);
                return ['result' => $result, 'data' => ($this->transformed)(), 'created' => $created];
            });
        }

        ($this->reset)();
        $old = $this->applier->apply($canonical, ApplyMode::Proposal);
        $oldData = ($this->transformed)();

        $out = [];
        if ($new === null) {
            if ($old->success) {
                $out[] = 'uložení: dnes uloženo × interpretace hlásí chybu ('
                    . ($interpreted->decisionError['code'] ?? 'nález se závažností chyba') . ')';
            } elseif ($interpreted->decisionError !== null && $old->errorCode !== $interpreted->decisionError['code']) {
                $out[] = "uložení: dnes {$old->errorCode} × interpretace {$interpreted->decisionError['code']}";
            }
            return ['differences' => $out, 'result' => $old];
        }

        /** @var ApplyResult $newResult */
        $newResult = $new['result'];
        if ($old->success !== $newResult->success || $old->errorCode !== $newResult->errorCode) {
            $out[] = 'uložení: dnes ' . ($old->errorCode ?? 'uloženo') . ' × nově ' . ($newResult->errorCode ?? 'uloženo')
                . ($newResult->success ? '' : ' — ' . self::errorIssues($newResult->canonical));
            return ['differences' => $out, 'result' => $old];
        }
        $out = [...$out, ...self::defaultsDifference($newResult->canonical['_resolve'] ?? [])];
        if (!$old->success) {
            return ['differences' => $out, 'result' => $old];
        }
        if (count($oldData) !== 1 || count($new['data']) !== 1) {
            $out[] = 'uložení: počet volání saveDocument dnes ' . count($oldData) . ' × nově ' . count($new['data']);
            return ['differences' => $out, 'result' => $old];
        }

        $newData = self::withIdsOf(
            $new['data'][0],
            $interpreted,
            $new['created'],
            self::createdIds($old->canonical['_resolve'] ?? [], $interpreted),
        );
        return [
            'differences' => [
                ...$out,
                ...ShadowDiff::arrays('doklad', self::normalized($oldData[0]), self::normalized($newData)),
            ],
            'result' => $old,
        ];
    }

    /**
     * @param array<string, mixed> $resolve `_resolve` odpovědi pokynu
     * @return list<string>
     */
    private static function defaultsDifference(array $resolve): array
    {
        $defaults = $resolve['defaults'] ?? [];
        return $defaults === []
            ? []
            : ['hotový doklad není úplný — pokyn doplnil: ' . implode(', ', array_column($defaults, 'path'))];
    }

    /** @param array<string, mixed> $canonical */
    private static function errorIssues(array $canonical): string
    {
        $errors = array_filter(
            $canonical['_resolve']['issues'] ?? [],
            static fn (array $issue): bool => ($issue['severity'] ?? null) === 'error',
        );
        return implode('; ', array_map(
            static fn (array $issue): string => "{$issue['code']} @ {$issue['path']}",
            $errors,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function normalized(array $data): array
    {
        foreach (self::NORMALIZED as $column => $default) {
            $data[$column] ??= $default;
        }
        return $data;
    }

    /**
     * Id záznamů, které dnešní cesta založila — z odpovědi (`matchedBy:
     * created`), ve tvaru `PendingRecords::create()`.
     *
     * @param array<string, mixed> $resolve
     * @return array<string, mixed>
     */
    private static function createdIds(array $resolve, InterpretedDocument $interpreted): array
    {
        $ids = ['rowItems' => []];
        foreach ($interpreted->pending as $entry) {
            if ($entry['kind'] === InterpretedDocument::PENDING_ITEM) {
                $id = $resolve['rows'][$entry['row']]['item']['matchedId'] ?? null;
                if ($id !== null) {
                    $ids['rowItems'][$entry['row']] = (int) $id;
                }
                continue;
            }
            $id = $resolve[$entry['key']]['matchedId'] ?? null;
            $ids[$entry['key']] = $id !== null ? (int) $id : null;
        }
        return $ids;
    }

    /**
     * Data nové cesty s id založených záznamů převedenými na id, která
     * týmž záznamům dala dnešní cesta.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $newIds
     * @param array<string, mixed> $oldIds
     * @return array<string, mixed>
     */
    private static function withIdsOf(array $data, InterpretedDocument $interpreted, array $newIds, array $oldIds): array
    {
        $persons = [];
        $items = [];
        foreach ($interpreted->pending as $entry) {
            if ($entry['kind'] === InterpretedDocument::PENDING_ITEM) {
                $from = $newIds['rowItems'][$entry['row']] ?? null;
                $to = $oldIds['rowItems'][$entry['row']] ?? null;
                if ($from !== null && $to !== null) {
                    $items[(int) $from] = (int) $to;
                }
            } elseif ($entry['kind'] === InterpretedDocument::PENDING_PERSON) {
                $from = $newIds[$entry['key']] ?? null;
                $to = $oldIds[$entry['key']] ?? null;
                if ($from !== null && $to !== null) {
                    $persons[(int) $from] = (int) $to;
                }
            } elseif (isset($data['partner_bank'], $newIds['supplierBank'], $oldIds['supplierBank'])
                && (int) $data['partner_bank'] === (int) $newIds['supplierBank']) {
                $data['partner_bank'] = (int) $oldIds['supplierBank'];
            }
        }
        foreach (['partner', 'partner_balance'] as $column) {
            if (isset($data[$column], $persons[(int) $data[$column]])) {
                $data[$column] = $persons[(int) $data[$column]];
            }
        }
        foreach ($data['rows'] ?? [] as $position => $row) {
            if (isset($row['item'], $items[(int) $row['item']])) {
                $data['rows'][$position]['item'] = $items[(int) $row['item']];
            }
            if (isset($row['partner'], $persons[(int) $row['partner']])) {
                $data['rows'][$position]['partner'] = $persons[(int) $row['partner']];
            }
        }
        return $data;
    }
}
