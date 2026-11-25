<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Popis akce záznamu pro detail (#172 D12): popisek tlačítka, varianta,
 * volitelně ikona (klíč z `frontend/src/icons.js`), text potvrzení
 * a dialog. Akce s dialogem se potvrzuje odesláním dialogu — `confirm`
 * platí jen pro akci bez dialogu.
 */
final class RecordActionDescriptor
{
    public const string PRIMARY = 'primary';
    public const string SECONDARY = 'secondary';
    public const string DANGER = 'danger';

    public const array VARIANTS = [self::PRIMARY, self::SECONDARY, self::DANGER];

    public function __construct(
        public readonly string $label,
        public readonly string $variant = self::SECONDARY,
        public readonly ?string $icon = null,
        public readonly ?string $confirm = null,
        public readonly ?RecordActionDialog $dialog = null,
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('Record action needs a label');
        }
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(
                "Record action variant '{$variant}' is not one of: " . implode(', ', self::VARIANTS),
            );
        }
        if ($icon !== null && trim($icon) === '') {
            throw new \InvalidArgumentException('Record action: icon must not be empty');
        }
        if ($confirm !== null && trim($confirm) === '') {
            throw new \InvalidArgumentException('Record action: confirm must not be empty');
        }
        if ($confirm !== null && $dialog !== null) {
            throw new \InvalidArgumentException('Record action with a dialog cannot also have a confirm text');
        }
    }

    /**
     * Pole dialogu; akce bez dialogu žádná nemá.
     *
     * @return list<RecordActionField>
     */
    public function fields(): array
    {
        return $this->dialog?->fields ?? [];
    }

    /**
     * Část popisu akce v `detail.actions[]` — `id`, `kind` a `target`
     * doplňuje sběr (`RecordActions`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $action = ['label' => $this->label, 'variant' => $this->variant];
        if ($this->icon !== null) {
            $action['icon'] = $this->icon;
        }
        $action['confirm'] = $this->confirm;
        $action['dialog'] = $this->dialog?->toArray();
        return $action;
    }
}
