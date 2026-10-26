<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

/**
 * Řádek tabulky běhů sady a hlavička detailu běhu (#103 D53): soubor,
 * datum, varianta, model extrakce a štítků, verze promptu, opakování,
 * shoda polí (průměr a rozpětí opakování), doklady bez odchylky, správné
 * částky, selhání, cena a čas na doklad. `currentSet` říká, zda běh vznikl
 * nad aktuálním obsahem sady — jinak se očekávání mohlo změnit a porovnání
 * s novými běhy `EvalRunComparison` odmítne.
 */
final class EvalRunSummary
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $run běh z `EvalCatalog::runs()` (s `_basename`)
     * @return array<string, mixed>
     */
    public static function fromRun(array $run, ?string $currentHash): array
    {
        $metrics = (array) ($run['metrics'] ?? []);
        $range = is_array($run['range'] ?? null) ? $run['range'] : [];
        $effective = (array) ($run['variant']['effective'] ?? []);
        $tags = is_array($effective['contentTags'] ?? null) ? $effective['contentTags'] : null;
        $setHash = $run['set']['hash'] ?? null;

        return [
            'file' => $run['_basename'] ?? basename((string) ($run['_file'] ?? '')),
            'startedAt' => $run['startedAt'] ?? null,
            'finishedAt' => $run['finishedAt'] ?? null,
            'variant' => $run['variant']['name'] ?? null,
            'model' => $effective['model'] ?? null,
            'tagsModel' => $tags['model'] ?? ($effective['model'] ?? null),
            'connection' => $effective['connection'] ?? null,
            'platform' => $effective['platform'] ?? null,
            'promptVersion' => $effective['promptVersion'] ?? null,
            'promptHash' => $effective['promptHash'] ?? null,
            'thinking' => $effective['thinking'] ?? null,
            'effort' => $effective['effort'] ?? null,
            'repeat' => max(1, (int) ($run['repeat'] ?? 1)),
            // Verze metrik (#103 D114): běhy bez klíče (před 2026-10-26) jsou 1 — poznámka započtená do shody polí.
            'metricsVersion' => max(1, (int) ($run['metricsVersion'] ?? 1)),

            'messages' => (int) ($run['set']['messages'] ?? 0),
            'only' => (array) ($run['set']['only'] ?? []),
            'limit' => $run['set']['limit'] ?? null,
            'hit' => self::withRange($metrics, $range, 'fields.all.hit'),
            'normalized' => $metrics['fields.all.normalized'] ?? null,
            'clean' => self::withRange($metrics, $range, 'documents.clean'),
            'amountsCorrect' => self::withRange($metrics, $range, 'documents.amountsCorrect'),
            'rowCountOk' => $metrics['documents.rowCountOk'] ?? null,
            'classificationOk' => $metrics['documents.classificationOk'] ?? null,
            'tagOk' => $metrics['documents.tagOk'] ?? null,
            'callsTotal' => (int) ($metrics['calls.total'] ?? 0),
            'callsOk' => (int) ($metrics['calls.ok'] ?? 0),
            'failed' => (int) ($metrics['calls.failed'] ?? 0),
            'costUsd' => $metrics['cost.usd'] ?? null,
            'costPerDocument' => $metrics['cost.perDocument'] ?? null,
            // Cena štítků a celkem (#103 F6b D56); starší běhy bez nich mají null.
            'tagsUsd' => $metrics['tags.usd'] ?? null,
            'costTotalUsd' => $metrics['cost.totalUsd'] ?? null,
            'costTotalPerDocument' => $metrics['cost.totalPerDocument'] ?? null,
            'timePerDocumentMs' => $metrics['time.perDocumentMs'] ?? null,
            'setHash' => $setHash,
            'currentSet' => $currentHash !== null && $setHash === $currentHash,
            'commit' => $run['git']['commit'] ?? null,
            'dirty' => (bool) ($run['git']['dirty'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $range
     * @return array{avg: int|float|null, min: int|float|null, max: int|float|null}
     */
    private static function withRange(array $metrics, array $range, string $key): array
    {
        $r = is_array($range[$key] ?? null) ? $range[$key] : [];
        return [
            'avg' => $metrics[$key] ?? null,
            'min' => $r['min'] ?? null,
            'max' => $r['max'] ?? null,
        ];
    }
}
