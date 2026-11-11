<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Corrective;

use Shipard\Module\Core\Exchange\Common\ApplyResult;

/**
 * Odmítnutí služby opravného dokladu (#92 task B U7, U8): kód výjimky =
 * HTTP status, `errorCode` = kód chyby API, `details` = hlášky applieru
 * (`{field, code, message, severity}`) u neúspěšného apply.
 */
final class CorrectiveDocException extends \DomainException
{
    public const NOT_FOUND = 'NOT_FOUND';
    public const SOURCE_INVALID = 'CORRECTIVE_SOURCE_INVALID';
    public const APPLY_FAILED = 'CORRECTIVE_APPLY_FAILED';

    /**
     * @param list<array{field: string, code: string, message: string, severity: string}> $details
     */
    public function __construct(
        string $message,
        int $status,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(int $docId): self
    {
        return new self("Doklad #{$docId} neexistuje.", 404, self::NOT_FOUND);
    }

    /** U7: jen doklad typu s příznakem `corrective` ve stavu V pořádku. */
    public static function sourceInvalid(string $message): self
    {
        return new self($message, 422, self::SOURCE_INVALID);
    }

    public static function applyFailed(ApplyResult $result): self
    {
        $details = [];
        foreach ($result->canonical['_resolve']['issues'] ?? [] as $issue) {
            if (!is_array($issue) || ($issue['severity'] ?? '') !== 'error') {
                continue;
            }
            $details[] = [
                'field'    => (string) ($issue['path'] ?? ''),
                'code'     => (string) ($issue['code'] ?? ''),
                'message'  => (string) ($issue['message'] ?? ($issue['code'] ?? '')),
                'severity' => 'error',
            ];
        }
        $message = $result->errorMessage ?? 'Opravný doklad se nepodařilo založit.';
        return new self($message, 422, self::APPLY_FAILED, $details);
    }
}
