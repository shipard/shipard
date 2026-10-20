<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;

/**
 * Jedno volání evaluace (zpráva × opakování): stav, cena, tokeny, čas,
 * klasifikace a štítek z výstupu modelu a výsledky polí
 * ({@see FieldOutcome}, včetně virtuálního listu klasifikace). Do souboru
 * výsledků jdou jen odchylky, shody jako počty (D36) a shody po normalizaci
 * s výstupní hodnotou (`normalized`, D53); selhané volání nese
 * neprázdné listy očekávání jako `missing` (D44) a do souboru jdou jen
 * jejich počty — seznam by jen opisoval očekávání. `path` = cesta výpočtu
 * (#103 D76): `ai` extrakce modelem, `isdoc` deterministický import ISDOC
 * + štítky; listy `notCarried` (pole, která ISDOC nenese) nejsou shoda ani
 * odchylka — do souboru jde jen jejich počet, prohlížeč je odvodí
 * z `IsdocFieldCoverage`.
 */
final readonly class EvalCallResult
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_FAILED = 'failed';
    public const string PATH_AI = 'ai';
    public const string PATH_ISDOC = 'isdoc';
    /** Selhání, za kterými je infrastruktura nebo nastavení — exit 1 příkazu. */
    public const array INFRASTRUCTURE_ERRORS = ['ai_error', 'config_error', 'internal'];

    /**
     * @param list<FieldOutcome> $outcomes
     * @param array{tokensIn: int, tokensOut: int, model: ?string, costUsd: ?float}|null $tags spotřeba LLM klasifikace štítků (#103 F6b D56); null = LLM se nevolalo
     */
    public function __construct(
        public string $messageId,
        public int $repeat,
        public string $status,
        public ?string $errorType,
        public ?string $note,
        public ?float $costUsd,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $durationMs,
        public ?string $model,
        public ?string $primaryType,
        public ?string $tag,
        public array $outcomes = [],
        public ?array $tags = null,
        public string $path = self::PATH_AI,
    ) {}

    /** Cena volání štítků; 0 bez LLM volání nebo bez ceny (model mimo katalog). */
    public function tagsCostUsd(): float
    {
        return is_numeric($this->tags['costUsd'] ?? null) ? (float) $this->tags['costUsd'] : 0.0;
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isInfrastructureFailure(): bool
    {
        return !$this->isOk() && in_array($this->errorType, self::INFRASTRUCTURE_ERRORS, true);
    }

    /** @return list<FieldOutcome> missing / extra / wrong (`notCarried` není odchylka) */
    public function deviations(): array
    {
        return array_values(array_filter($this->outcomes, static fn (FieldOutcome $o): bool => !$o->state->isHit() && $o->state->isCounted()));
    }

    /** Úspěšné volání bez jediné odchylky. */
    public function isClean(): bool
    {
        return $this->isOk() && $this->deviations() === [];
    }

    /** @return array{match: int, matchNormalized: int, missing: int, extra: int, wrong: int, notCarried: int} */
    public function counts(): array
    {
        $counts = ['match' => 0, 'matchNormalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0];
        foreach ($this->outcomes as $o) {
            $counts[$o->state->value]++;
        }
        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'messageId' => $this->messageId,
            'repeat' => $this->repeat,
            'status' => $this->status,
        ];
        if (!$this->isOk()) {
            $out['errorType'] = $this->errorType;
            $out['note'] = $this->note;
        }
        $out += [
            'costUsd' => $this->costUsd,
            'tokensIn' => $this->tokensIn,
            'tokensOut' => $this->tokensOut,
            'durationMs' => $this->durationMs,
            'model' => $this->model,
            'path' => $this->path,
            'primaryType' => $this->primaryType,
            'tag' => $this->tag,
            'tags' => $this->tags,
        ];
        $out['counts'] = $this->counts();
        if ($this->isOk()) {
            $out['normalized'] = array_values(array_map(
                static function (FieldOutcome $o): array {
                    $entry = ['path' => $o->path, 'actual' => $o->actual];
                    if ($o->similarity !== null) {
                        $entry['similarity'] = $o->similarity;
                    }
                    return $entry;
                },
                array_filter($this->outcomes, static fn (FieldOutcome $o): bool => $o->state === FieldState::MatchNormalized),
            ));
            $out['deviations'] = array_map(static fn (FieldOutcome $o): array => $o->toArray(), $this->deviations());
        }
        return $out;
    }

    /**
     * Shody po normalizaci z volání v souboru výsledků — přijímá oba tvary:
     * `{path, actual, similarity?}` (od 16. 10. 2026, #103 D53 — prohlížeč
     * ukáže skutečný tvar výstupu) i původní samotnou cestu (starší běhy;
     * výstup neznámý, `actualKnown` false → ukáže se očekávání se štítkem).
     *
     * @param array<string, mixed> $call položka `calls[]`
     * @return list<array{path: string, actual: mixed, actualKnown: bool, similarity: ?float}>
     */
    public static function normalizedEntries(array $call): array
    {
        $out = [];
        foreach ((array) ($call['normalized'] ?? []) as $entry) {
            if (is_string($entry)) {
                $out[] = ['path' => $entry, 'actual' => null, 'actualKnown' => false, 'similarity' => null];
            } elseif (is_array($entry) && is_string($entry['path'] ?? null)) {
                $out[] = [
                    'path' => $entry['path'],
                    'actual' => $entry['actual'] ?? null,
                    'actualKnown' => array_key_exists('actual', $entry),
                    'similarity' => isset($entry['similarity']) && is_numeric($entry['similarity']) ? (float) $entry['similarity'] : null,
                ];
            }
        }
        return $out;
    }

    /** Cesta výpočtu z volání v souboru výsledků; běhy před D76 (bez klíče) jsou `ai`. */
    public static function pathOf(array $call): string
    {
        return ($call['path'] ?? null) === self::PATH_ISDOC ? self::PATH_ISDOC : self::PATH_AI;
    }

    /**
     * Počet odchylek volání ze souboru výsledků: u úspěšného volání délka
     * `deviations`, u selhaného součet `missing + extra + wrong` z `counts`
     * (D44); selhané volání bez `counts` (běhy před D44) → null.
     *
     * @param array<string, mixed> $call
     */
    public static function deviationCount(array $call): ?int
    {
        if (($call['status'] ?? null) === self::STATUS_OK) {
            return count((array) ($call['deviations'] ?? []));
        }
        $counts = $call['counts'] ?? null;
        if (!is_array($counts)) {
            return null;
        }
        return (int) ($counts['missing'] ?? 0) + (int) ($counts['extra'] ?? 0) + (int) ($counts['wrong'] ?? 0);
    }
}
