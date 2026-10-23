<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

/**
 * Loaded state machine from a docStates cfgItem.
 *
 * Wraps the raw config array (keyed by string docState values) and provides
 * typed accessors used by the CrudController to enforce state transitions,
 * readOnly protection, and viewer tab filtering.
 */
class DocStateConfig
{
    /** @param array<string, array> $states Keyed by docState value as string ("10", "40", …) */
    public function __construct(private readonly array $states) {}

    public static function fromCfgItem(?array $data): self
    {
        return new self($data ?? []);
    }

    /** Returns the raw state config array for a given docState value, or null if unknown. */
    public function getState(int $docState): ?array
    {
        return $this->states[(string) $docState] ?? null;
    }

    /** Returns the mainState value (used for docStateMain column / ORDER BY). */
    public function getMainState(int $docState): int
    {
        return (int) ($this->states[(string) $docState]['mainState'] ?? 1);
    }

    /** Returns true if the state forbids direct field edits (user must switch to edit state first). */
    public function isReadOnly(int $docState): bool
    {
        return (bool) ($this->states[(string) $docState]['readOnly'] ?? false);
    }

    /**
     * Ukazuje se štítek stavu v hlavičce detailu záznamu (#136 D20)?
     * `detailBadge: false` u stavu ho skryje — „V pořádku“ je běžný stav
     * bez proužku v seznamu a štítek by jen šuměl; chybějící klíč = true.
     * Formulář (`FormStateBadge`) štítek ukazuje vždy.
     */
    public function showsDetailBadge(int $docState): bool
    {
        return (bool) ($this->states[(string) $docState]['detailBadge'] ?? true);
    }

    /** Returns true if the transition from $from to $to is listed in goto. */
    public function isTransitionAllowed(int $from, int $to): bool
    {
        $goto = $this->states[(string) $from]['goto'] ?? [];
        return in_array($to, $goto, true);
    }

    /**
     * Returns docState integer values belonging to the given viewGroup.
     * Used by viewer tab filtering: active / archive / trash.
     *
     * @return int[]
     */
    public function getViewGroupStates(string $viewGroup): array
    {
        $result = [];
        foreach ($this->states as $val => $state) {
            if (($state['viewGroup'] ?? '') === $viewGroup) {
                $result[] = (int) $val;
            }
        }
        return $result;
    }

    /**
     * Returns available transitions from the given state, ready for API response.
     *
     * `mobileKebab` is an optional per-state UI hint: when true, the form footer
     * (FormStateBar) tucks this transition into the mobile kebab menu instead of
     * showing it as a button, even though its stateStyle would otherwise be
     * visible. Used to demote side actions whose stateStyle is reused with a
     * different meaning across doc types (e.g. `edit` = "Opravit" on invoices,
     * kept visible, vs. "Pozastavit" on tasks, demoted to the kebab).
     *
     * @return array<array{state: int, stateName: string, actionName: string, stateStyle: string, close_form: bool, mobileKebab: bool}>
     */
    public function getAvailableTransitions(int $currentState): array
    {
        $goto   = $this->states[(string) $currentState]['goto'] ?? [];
        $result = [];
        foreach ($goto as $target) {
            $s = $this->states[(string) $target] ?? null;
            if ($s !== null) {
                $result[] = [
                    'state'      => $target,
                    'stateName'  => $s['stateName']  ?? (string) $target,
                    'actionName' => $s['actionName'] ?? (string) $target,
                    'stateStyle' => $s['stateStyle'] ?? '',
                    'close_form'  => (bool) ($s['closeForm'] ?? false),
                    'mobileKebab' => (bool) ($s['mobileKebab'] ?? false),
                ];
            }
        }
        return $result;
    }
}
