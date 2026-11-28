<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Chyba tisku → kód a HTTP stav REST (docs/prints.md §6). Jedno místo pro
 * všechny cesty, které tisk spouštějí mimo `PrintsController::run()` —
 * odeslání e-mailem a tiskové úlohy (#177 task 4b) — aby tatáž výjimka
 * neměla na každé cestě jiný kód.
 */
final class PrintErrors
{
    /**
     * @return array{code: string, message: string, status: int, details: list<array<string, string>>}|null
     *         null = výjimka není chyba tisku, volající ji nechá projít
     */
    public static function of(\Throwable $e): ?array
    {
        return match (true) {
            $e instanceof PrintNotFoundException       => self::error('PRINT_NOT_FOUND', $e->getMessage(), 404),
            $e instanceof PrintRecordNotFoundException => self::error('RECORD_NOT_FOUND', $e->getMessage(), 404),
            $e instanceof PrintNotAvailableException   => self::error('PRINT_NOT_AVAILABLE', $e->getMessage(), 409),
            $e instanceof PrintBuildException          => self::error('PRINT_DATA_MISSING', $e->getMessage(), 409),
            $e instanceof PrintLanguageNotCompiledException
                => self::error('PRINT_LANGUAGE_NOT_COMPILED', $e->getMessage(), 409),
            $e instanceof PrintFormatNotSupportedException
                => self::error('PRINT_FORMAT_UNSUPPORTED', $e->getMessage(), 409),
            $e instanceof PrintRenderException => $e->isServiceUnavailable()
                ? self::error('RENDER_UNAVAILABLE', 'Print service is not available', 503, self::renderDetails($e))
                : self::error('RENDER_FAILED', 'Print rendering failed', 500, self::renderDetails($e)),
            $e instanceof \InvalidArgumentException => self::error('BAD_REQUEST', $e->getMessage(), 400),
            default => null,
        };
    }

    /**
     * @param list<array<string, string>> $details
     * @return array{code: string, message: string, status: int, details: list<array<string, string>>}
     */
    private static function error(string $code, string $message, int $status, array $details = []): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status, 'details' => $details];
    }

    /** @return list<array<string, string>> */
    private static function renderDetails(PrintRenderException $e): array
    {
        return [['field' => '_render', 'code' => $e->errorKind->value, 'message' => $e->getMessage()]];
    }
}
