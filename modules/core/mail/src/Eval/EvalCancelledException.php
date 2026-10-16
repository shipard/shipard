<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Zrušení běhu `ai-eval` (SIGTERM, D61) uprostřed čekání na další pokus
 * volání modelu — vyhazuje hook `beforeAttempt` ({@see EvalRunner}),
 * aby `LlmRetry` po přerušeném `sleep()` nezačal další volání.
 */
final class EvalCancelledException extends EvalException
{
}
