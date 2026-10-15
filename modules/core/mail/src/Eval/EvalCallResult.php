<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;

/**
 * Jedno volání evaluace (zpráva × opakování): stav, cena, tokeny, čas,
 * klasifikace a štítek z výstupu modelu a výsledky polí
 * ({@see FieldOutcome}, včetně virtuálního listu klasifikace). Do souboru
 * výsledků jdou jen odchylky, shody jako počty (D36).
 */
final readonly class EvalCallResult
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_FAILED = 'failed';
    /** Selhání, za kterými je infrastruktura nebo nastavení — exit 1 příkazu. */
    public const array INFRASTRUCTURE_ERRORS = ['ai_error', 'config_error', 'internal'];

    /** @param list<FieldOutcome> $outcomes */
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
    ) {}

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isInfrastructureFailure(): bool
    {
        return !$this->isOk() && in_array($this->errorType, self::INFRASTRUCTURE_ERRORS, true);
    }

    /** @return list<FieldOutcome> missing / extra / wrong */
    public function deviations(): array
    {
        return array_values(array_filter($this->outcomes, static fn (FieldOutcome $o): bool => !$o->state->isHit()));
    }

    /** Úspěšné volání bez jediné odchylky. */
    public function isClean(): bool
    {
        return $this->isOk() && $this->deviations() === [];
    }

    /** @return array{match: int, matchNormalized: int, missing: int, extra: int, wrong: int} */
    public function counts(): array
    {
        $counts = ['match' => 0, 'matchNormalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0];
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
            'primaryType' => $this->primaryType,
            'tag' => $this->tag,
        ];
        if ($this->isOk()) {
            $out['counts'] = $this->counts();
            $out['normalized'] = array_values(array_map(
                static fn (FieldOutcome $o): string => $o->path,
                array_filter($this->outcomes, static fn (FieldOutcome $o): bool => $o->state === FieldState::MatchNormalized),
            ));
            $out['deviations'] = array_map(static fn (FieldOutcome $o): array => $o->toArray(), $this->deviations());
        }
        return $out;
    }
}
