<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai\Alerts;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ModelSpec;

/**
 * „Končí tento model brzy?“ — společný úsudek pro upozornění
 * `core.ai.model_retiring` (řádky AI funkcí) a
 * `core.mail.profile_model_retiring` (profily pošty), tasks/ai-functions.md
 * D42, D47: model podle katalogu (`lifecycle`) do {@see HORIZON_DAYS} dnů
 * končí (`retiresAt`), nebo už skončil (status `retired`, nebo datum
 * v minulosti). Hlídají se jen **výslovně nastavené** modely — modely
 * z mapy úrovní drží repo (resolver je jen loguje).
 */
final class ModelRetirementJudge
{
    public const HORIZON_DAYS = 30;

    public function __construct(
        private readonly ModelCatalog $catalog,
        private readonly \DateTimeImmutable $today,
        private readonly int $horizonDays = self::HORIZON_DAYS,
    ) {}

    /**
     * Null = model v katalogu není, nebo nekončí v horizontu. Jinak
     * `retired` (už skončil), `retiresAt` (datum, může chybět u statusu
     * `retired` bez data), `daysLeft` (záporné po konci), `replacedBy`.
     *
     * @return array{spec: ModelSpec, retired: bool, retiresAt: ?string, daysLeft: ?int, replacedBy: ?string}|null
     */
    public function judge(string $modelId): ?array
    {
        $spec = $this->catalog->find($modelId);
        if ($spec === null) {
            return null;
        }
        $retiresAt = $spec->retiresAt !== null ? new \DateTimeImmutable($spec->retiresAt) : null;
        $daysLeft = $retiresAt !== null
            ? (int) $this->today->setTime(0, 0)->diff($retiresAt->setTime(0, 0))->format('%r%a')
            : null;
        $retired = $spec->status === ModelSpec::STATUS_RETIRED || ($daysLeft !== null && $daysLeft < 0);

        if (!$retired && ($daysLeft === null || $daysLeft > $this->horizonDays)) {
            return null;
        }

        return [
            'spec' => $spec,
            'retired' => $retired,
            'retiresAt' => $spec->retiresAt,
            'daysLeft' => $daysLeft,
            'replacedBy' => $spec->replacedBy,
        ];
    }

    /**
     * Text pro uživatele v jazyce DS: „končí za N dnů (datum), náhrada X“
     * / „už skončil“.
     *
     * @param array{spec: ModelSpec, retired: bool, retiresAt: ?string, daysLeft: ?int, replacedBy: ?string} $verdict
     */
    public static function describe(array $verdict, string $language): string
    {
        $isCs = $language === 'cs';
        $model = $verdict['spec']->id;
        $replacement = $verdict['replacedBy'] !== null
            ? ($isCs ? " Náhrada podle katalogu: {$verdict['replacedBy']}." : " Catalog replacement: {$verdict['replacedBy']}.")
            : '';
        if ($verdict['retired']) {
            return ($isCs
                ? "Model {$model} už na platformě skončil" . ($verdict['retiresAt'] !== null ? " ({$verdict['retiresAt']})" : '') . ' — volání selhávají.'
                : "Model {$model} has already been retired" . ($verdict['retiresAt'] !== null ? " ({$verdict['retiresAt']})" : '') . ' — calls fail.')
                . $replacement;
        }
        $days = (int) $verdict['daysLeft'];
        return ($isCs
            ? "Model {$model} končí {$verdict['retiresAt']} (za {$days} dnů)."
            : "Model {$model} retires on {$verdict['retiresAt']} (in {$days} days).")
            . $replacement;
    }
}
