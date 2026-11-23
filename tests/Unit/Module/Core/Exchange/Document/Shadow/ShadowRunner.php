<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;

/**
 * Stínové porovnání (#166 D57, U5): pro jeden vstup spustí **dnešní cestu
 * návrhu** (`DocumentApplier` v režimu návrh) a **novou cestu** — vstup
 * návrhu {@see DocumentProposal} (interpretace → založení chybějících
 * záznamů → pokyn nad hotovým dokladem) — a vrátí rozdíly. Prázdný seznam =
 * obě cesty dávají totéž.
 *
 * Porovnává se:
 *
 *  - náhled — **celá odpověď**: vrácený payload a blok `_resolve` (bloky
 *    stran a řádků, DPH, nálezy včetně textů a pořadí, souhrn, `computed`);
 *  - uložení — výsledek (uloženo × kód chyby, HTTP stav, zpráva), **celá
 *    odpověď** a data předaná k uložení (výstup `transform()`: hlavička,
 *    řádky, rekapitulace);
 *  - úplnost hotového dokladu — pokyn nad ním nesmí doplnit žádnou výchozí
 *    hodnotu (`_resolve.defaults` prázdné, D52).
 *
 * Nová cesta běží první a izolovaně (`$isolated` — u databáze savepoint
 * vrácený zpět), aby dnešní cesta potom viděla zdroj dat v původním stavu.
 * Záznamy, které obě cesty zakládají, mají proto různá id; porovnání je
 * převádí podle bloků `matchedBy: created` obou odpovědí.
 *
 * **Výjimky** — rozdíly, které nejsou chybou — jsou jen v {@see NORMALIZED}
 * a v poznámkách tasků `tasks/doc-interpretation-extract.md`
 * a `tasks/doc-interpretation-switch.md`.
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
     */
    public function __construct(
        private readonly DocumentApplier $applier,
        private readonly DocumentInterpreter $interpreter,
        private readonly DocumentProposal $proposal,
        private readonly \Closure $transformed,
        private readonly \Closure $reset,
        private readonly \Closure $isolated,
    ) {
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    public function preview(array $canonical): array
    {
        $old = $this->applier->preview($canonical, ApplyMode::Proposal);
        $new = $this->proposal->preview($canonical);

        $out = self::outcomeDifference('náhled', $old, $new);
        if ($out !== []) {
            return $out;
        }
        if ($old->success) {
            // Úplnost hotového dokladu: pokyn nad ním nic nedoplňuje.
            $instruction = $this->applier->preview($this->interpreter->interpret($canonical)->document, ApplyMode::Instruction);
            $out = self::defaultsDifference($instruction->canonical['_resolve'] ?? []);
        }

        return [...$out, ...ShadowDiff::arrays('náhled', $old->canonical, $new->canonical)];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array{differences: list<string>, result: ApplyResult} `result` = výsledek dnešní cesty
     */
    public function apply(array $canonical): array
    {
        $new = ($this->isolated)(function () use ($canonical): array {
            ($this->reset)();
            $result = $this->proposal->apply($canonical);
            return ['result' => $result, 'data' => ($this->transformed)()];
        });

        ($this->reset)();
        $old = $this->applier->apply($canonical, ApplyMode::Proposal);
        $oldData = ($this->transformed)();

        /** @var ApplyResult $newResult */
        $newResult = $new['result'];
        $out = self::outcomeDifference('uložení', $old, $newResult);
        if ($out !== []) {
            return ['differences' => $out, 'result' => $old];
        }

        $oldIds = self::createdIds($old->canonical['_resolve'] ?? []);
        $newIds = self::createdIds($newResult->canonical['_resolve'] ?? []);
        $out = ShadowDiff::arrays('odpověď', self::response($old->canonical, $oldIds), self::response($newResult->canonical, $oldIds, $newIds));
        if (!$old->success) {
            return ['differences' => $out, 'result' => $old];
        }
        if (count($oldData) !== 1 || count($new['data']) !== 1) {
            $out[] = 'uložení: počet volání saveDocument dnes ' . count($oldData) . ' × nově ' . count($new['data']);
            return ['differences' => $out, 'result' => $old];
        }

        return [
            'differences' => [
                ...$out,
                ...ShadowDiff::arrays('doklad', self::normalized($oldData[0]), self::normalized(self::withIdsOf($new['data'][0], $newIds, $oldIds))),
            ],
            'result' => $old,
        ];
    }

    /**
     * Výsledek obou cest: úspěch, kód chyby, HTTP stav a zpráva.
     *
     * @return list<string>
     */
    private static function outcomeDifference(string $what, ApplyResult $old, ApplyResult $new): array
    {
        $describe = static fn (ApplyResult $r): string => $r->success
            ? "ok ({$r->statusCode})"
            : "{$r->errorCode} ({$r->statusCode}): {$r->errorMessage}";
        if ($describe($old) === $describe($new)) {
            return [];
        }
        return ["{$what}: dnes " . $describe($old) . ' × nově ' . $describe($new)
            . ($new->success ? '' : ' — ' . self::errorIssues($new->canonical))];
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
     * Id záznamů, které cesta založila — z odpovědi (`matchedBy: created`):
     * osoby a účet podle klíče bloku, položky podle indexu řádku.
     *
     * @param array<string, mixed> $resolve
     * @return array{persons: array<string, int>, bank: ?int, items: array<int, int>}
     */
    private static function createdIds(array $resolve): array
    {
        $ids = ['persons' => [], 'bank' => null, 'items' => []];
        foreach (['supplier', 'customer', 'balanceParty'] as $key) {
            if (($resolve[$key]['matchedBy'] ?? null) === 'created') {
                $ids['persons'][$key] = (int) $resolve[$key]['matchedId'];
            }
        }
        if (($resolve['supplierBank']['matchedBy'] ?? null) === 'created') {
            $ids['bank'] = (int) $resolve['supplierBank']['matchedId'];
        }
        foreach ($resolve['rows'] ?? [] as $i => $row) {
            if (($row['item']['matchedBy'] ?? null) === 'created') {
                $ids['items'][(int) $i] = (int) $row['item']['matchedId'];
            }
        }
        return $ids;
    }

    /**
     * Odpověď k porovnání: id uloženého dokladu jen jako „je × není“
     * a id založených záznamů nové cesty převedená na id dnešní cesty.
     *
     * @param array<string, mixed> $canonical
     * @param array{persons: array<string, int>, bank: ?int, items: array<int, int>} $oldIds
     * @param array{persons: array<string, int>, bank: ?int, items: array<int, int>}|null $newIds null = odpověď dnešní cesty
     * @return array<string, mixed>
     */
    private static function response(array $canonical, array $oldIds, ?array $newIds = null): array
    {
        if (array_key_exists('savedDocId', $canonical)) {
            $canonical['savedDocId'] = $canonical['savedDocId'] !== null ? 'uloženo' : null;
        }
        if ($newIds === null) {
            return $canonical;
        }
        foreach ($newIds['persons'] as $key => $id) {
            if (isset($oldIds['persons'][$key])) {
                $canonical['_resolve'][$key]['matchedId'] = $oldIds['persons'][$key];
            }
        }
        if ($newIds['bank'] !== null && $oldIds['bank'] !== null) {
            $canonical['_resolve']['supplierBank']['matchedId'] = $oldIds['bank'];
        }
        foreach ($newIds['items'] as $i => $id) {
            if (isset($oldIds['items'][$i])) {
                $canonical['_resolve']['rows'][$i]['item']['matchedId'] = $oldIds['items'][$i];
            }
        }
        return $canonical;
    }

    /**
     * Data nové cesty s id založených záznamů převedenými na id, která
     * týmž záznamům dala dnešní cesta.
     *
     * @param array<string, mixed> $data
     * @param array{persons: array<string, int>, bank: ?int, items: array<int, int>} $newIds
     * @param array{persons: array<string, int>, bank: ?int, items: array<int, int>} $oldIds
     * @return array<string, mixed>
     */
    private static function withIdsOf(array $data, array $newIds, array $oldIds): array
    {
        $persons = [];
        foreach ($newIds['persons'] as $key => $id) {
            if (isset($oldIds['persons'][$key])) {
                $persons[$id] = $oldIds['persons'][$key];
            }
        }
        $items = [];
        foreach ($newIds['items'] as $i => $id) {
            if (isset($oldIds['items'][$i])) {
                $items[$id] = $oldIds['items'][$i];
            }
        }
        if (isset($data['partner_bank'], $newIds['bank'], $oldIds['bank']) && (int) $data['partner_bank'] === $newIds['bank']) {
            $data['partner_bank'] = $oldIds['bank'];
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
