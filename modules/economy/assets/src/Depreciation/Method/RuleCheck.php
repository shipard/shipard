<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/** @internal */
final class RuleCheck
{
    /**
     * Pravidlo (skupina, časové či mimořádné) musí platit pro datum zařazení.
     *
     * @return list<PlanMessage>
     */
    public static function problems(
        TaxDepreciationRules $rules,
        string $method,
        string $rule,
        string $acquiredDate,
    ): array {
        $valid = array_column($rules->rules($method, $acquiredDate), 'code');
        if (in_array($rule, $valid, true)) {
            return [];
        }
        return [PlanMessage::error(PlanMessage::RULE_NOT_VALID, [
            'rule' => $rule,
            'method' => $method,
            'date' => $acquiredDate,
        ])];
    }
}
