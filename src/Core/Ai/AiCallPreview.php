<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\AiFunctionException;

/**
 * „Co by se stalo“ pro AI funkci (tasks/ai-settings-section.md D51):
 * buď plán volání + úpravy, které by {@see RequestShaper} pro efektivní
 * model provedl, nebo chyba, kterou by volání skončilo. Vrací
 * {@see AiFunctionResolver::preview()}; čte detail AI funkce v Nastavení
 * a `ai-test` — texty k tomu dává {@see AiTexts}.
 */
final readonly class AiCallPreview
{
    /** @param list<RequestChange> $changes */
    private function __construct(
        public ?AiCallPlan $plan,
        public ?AiFunctionException $error,
        public array $changes,
    ) {}

    /** @param list<RequestChange> $changes */
    public static function ok(AiCallPlan $plan, array $changes): self
    {
        return new self($plan, null, $changes);
    }

    public static function failed(AiFunctionException $error): self
    {
        return new self(null, $error, []);
    }

    public function isOk(): bool
    {
        return $this->error === null;
    }
}
