<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Opakování LLM volání jen při přechodné chybě
 * ({@see LlmApiException::isTransient()}): `$delays` = pauzy mezi pokusy
 * (počet pokusů = počet pauz + 1). Jiná výjimka nebo vyčerpané pokusy
 * propadají dál. `$beforeAttempt` běží před každým pokusem (prodloužení
 * lease claimu) a smí běh ukončit vlastní výjimkou; `$sleep` je
 * injektovatelný kvůli testům. Tasks/mail-analysis-inprocess.md D18.
 */
final class LlmRetry
{
    /**
     * @template T
     * @param callable(int $attempt): T $call
     * @param list<int> $delays
     * @param callable(int $attempt): void|null $beforeAttempt
     * @param callable(int $seconds): void|null $sleep
     * @return T
     * @throws LlmApiException
     */
    public static function run(callable $call, array $delays, ?callable $beforeAttempt = null, ?callable $sleep = null): mixed
    {
        $attempts = count($delays) + 1;
        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($beforeAttempt !== null) {
                $beforeAttempt($attempt);
            }
            try {
                return $call($attempt);
            } catch (LlmApiException $e) {
                if (!$e->isTransient() || $attempt >= $attempts) {
                    throw $e;
                }
                $delay = (int) $delays[$attempt - 1];
                ErrorLogger::warn('LlmRetry: transient LLM error — retrying', [
                    'attempt' => $attempt,
                    'delaySeconds' => $delay,
                    'status' => $e->statusCode,
                    'type' => $e->errorType,
                    'error' => $e->getMessage(),
                ]);
                $sleep($delay);
            }
        }

        throw new \LogicException('unreachable');
    }
}
