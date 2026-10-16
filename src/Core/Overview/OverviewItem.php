<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

/**
 * Jedna položka sekce Přehledu firmy — číslo s datem, ke kterému platí (D3).
 *
 * Částka je v plné přesnosti a v měně položky; položka v jiné měně je
 * samostatná položka, nic se nesčítá přes měny ani nepřepočítává (D4).
 * `link` má tvar akcí reportů (docs/reports.md §16.5): `open_detail`,
 * `open_viewer`, `open_report`. `dueDate` nese termín (podání DPH, D16).
 */
final readonly class OverviewItem
{
    public const STATE_OK      = 'ok';
    /** Zdroj je starší, než se čeká (výpis nad 35 dní, D14). */
    public const STATE_STALE   = 'stale';
    /** Zdroj chybí (účet bez výpisu). */
    public const STATE_MISSING = 'missing';

    /**
     * @param ?array{value: float, currency: string} $amount
     * @param ?array{kind: string, target: array<string, mixed>} $link
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public ?array $amount = null,
        public ?string $asOf = null,
        public string $state = self::STATE_OK,
        public ?string $note = null,
        public ?string $dueDate = null,
        public ?array $link = null,
    ) {}

    /** @return array{value: float, currency: string} */
    public static function amount(float $value, string $currency): array
    {
        return ['value' => $value, 'currency' => strtoupper($currency)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'      => $this->id,
            'label'   => $this->label,
            'kind'    => $this->kind,
            'amount'  => $this->amount,
            'asOf'    => $this->asOf,
            'state'   => $this->state,
            'note'    => $this->note,
            'dueDate' => $this->dueDate,
            'link'    => $this->link,
        ];
    }
}
