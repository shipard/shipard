<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Pravidla daňových odpisů jednoho státu (docs/assets.md D6, D31).
 *
 * Čísla drží konfigurace země, vzorce implementace. Engine
 * (`economy.assets` → `DepreciationPlanner`) zná jen tohle rozhraní —
 * kalendář, počitadla let a měsíců a pořadí událostí jsou jeho věc.
 */
interface TaxDepreciationRules
{
    /** Roční odpis za zdaňovací období (`annualAmount`). */
    public const KIND_ANNUAL = 'annual';
    /** Měsíční rozpis od měsíce po zařazení (`scheduleAmount`). */
    public const KIND_MONTHLY = 'monthly';
    /** Daňový odpis = účetní odpis téže karty. */
    public const KIND_ACCOUNTING = 'accounting';
    /** Daňově se neodepisuje. */
    public const KIND_NONE = 'none';

    public function country(): string;

    /**
     * Metody, které lze zvolit pro majetek zařazený daného dne. `null` =
     * datum zařazení ještě není známé (karta před zařazením) — vrací
     * všechny metody pro daný druh majetku; platnost k datu se ověří při
     * zařazení.
     *
     * @return list<string>
     */
    public function availableMethods(?string $acquiredDate, bool $intangible): array;

    /** Druh metody (`KIND_*`), `null` = metodu pravidla neznají. */
    public function methodKind(string $method): ?string;

    /** Název metody pro UI (lokalizovaný kompilací konfigurace). */
    public function methodName(string $method): string;

    /**
     * Skupiny / časová / mimořádná pravidla metody platná pro datum
     * zařazení; `null` = všechna pravidla metody bez ohledu na platnost.
     *
     * @return list<array{code: string, name: string}>
     */
    public function rules(string $method, ?string $acquiredDate): array;

    /**
     * Členění daňových odpisů pro přiznání k dani z příjmů (D66): klíč
     * skupiny přiznání, do které patří odpisy majetku s touto metodou
     * a pravidlem. Null = metoda bez daňového odpisu nebo pravidlo bez
     * zařazení.
     */
    public function taxReturnGroup(string $method, ?string $rule): ?string;

    /**
     * Skupiny přiznání v pořadí výkazu.
     *
     * @return array<int|string, string> klíč → název (číselné klíče drží PHP jako int)
     */
    public function taxReturnGroups(): array;

    public function isInterruptible(string $method): bool;

    public function allowsHalfYearOnDisposal(string $method): bool;

    /** Zdaňovací období kratší než 12 měsíců dává u metody polovinu ročního odpisu (D46). */
    public function allowsShortPeriodHalfYear(string $method): bool;

    /** False = technické zhodnocení se u metody odpisuje samostatně. */
    public function allowsImprovement(string $method): bool;

    /** Zaokrouhlení odpisu dle pravidel země. */
    public function round(float $amount): float;

    /** Jeden rok ročních metod. */
    public function annualAmount(TaxYearInput $in): TaxAmount;

    /** Celková délka rozpisu v měsících, ve kterém se vstup nachází. */
    public function scheduleMonths(TaxScheduleInput $in): int;

    /** Úsek měsíců časových a mimořádných metod. */
    public function scheduleAmount(TaxScheduleInput $in): TaxAmount;
}
