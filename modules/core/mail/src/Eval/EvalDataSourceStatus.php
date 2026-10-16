<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyPreflight;

/**
 * Stav zdroje dat vůči sadě z pohledu evaluace (tasks/testing-phase6b.md
 * § 2, D59) — totéž, co hlídá preflight `ai-eval` ({@see EvalPreflight}):
 * zprávy zdroje dat jsou přesně zprávy sady, žádný doklad (ani v Koši),
 * žádné pravidlo IČO → štítek. `clean` = čistě naseedovaný zdroj dat,
 * nad kterým evaluace měří AI a ne historii řádků. Jen SELECTy přes
 * {@see EvalDataSource}; sdílí ho preflight i panel Zdroje dat dashboardu.
 */
final class EvalDataSourceStatus
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $setMessageIds
     * @return array{messages: int, documents: int, tagRules: int, missing: list<string>, membership: list<string>, clean: bool}
     */
    public static function inspect(EvalDataSource $dataSource, array $setMessageIds): array
    {
        $messages = $dataSource->messageNdxByCode();
        $docs = $dataSource->documentSources();
        $membership = VerifyPreflight::membershipProblems($setMessageIds, array_flip($messages), $docs);
        $rules = $dataSource->tagRuleCount();
        $missing = array_values(array_filter($setMessageIds, static fn (string $id): bool => !isset($messages[$id])));
        return [
            'messages' => count($messages),
            'documents' => count($docs),
            'tagRules' => $rules,
            'missing' => $missing,
            'membership' => $membership,
            'clean' => $membership === [] && $docs === [] && $rules === 0 && $missing === [],
        ];
    }
}
