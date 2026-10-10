<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Ai;

use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationResult;

/**
 * Společná validace polí `model` / `model_like` nastavení AI funkce a AI
 * profilu pošty (tasks/ai-functions.md D43): model z katalogu `model_like`
 * nepotřebuje, vlastní ID ho vyžaduje — a to z katalogu. Bez compiled
 * configu (prázdný katalog) se nic neověřuje: formulář před `ds-upgrade`
 * nesmí blokovat uložení, chybu pak vrátí resolver (`UNKNOWN_MODEL`).
 */
final class ModelFieldValidator
{
    /** @param array<string, mixed> $data */
    public static function validate(?ConfigRuntime $config, array $data, ValidationResult $result): void
    {
        $catalog = ModelCatalog::fromConfig($config);
        if ($catalog->isEmpty()) {
            return;
        }
        $model = AiFunctionSettingsDocument::stringOrNull($data['model'] ?? null);
        $modelLike = AiFunctionSettingsDocument::stringOrNull($data['model_like'] ?? null);

        if ($modelLike !== null && $catalog->find($modelLike) === null) {
            $result->addError('model_like', "Model '{$modelLike}' není v katalogu modelů", 'unknown_model');
        }
        if ($model !== null && $catalog->find($model) === null && $modelLike === null) {
            $result->addError(
                'model_like',
                "Model '{$model}' není v katalogu — zvolte model z katalogu, podle kterého se má požadavek tvarovat",
                'model_like_required',
            );
        }
    }
}
