<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Import;

/**
 * Výsledek importu karty majetku (`shpd.assets.asset.v1`, docs/assets.md
 * §5.7). Úspěch nese stav `created` / `updated` / `skipped`, id karty
 * a varování; chyba kód, zprávu a seznam nálezů s cestou do payloadu
 * (`asset.taxMethod`, `events.3.amount`) a `sourceRef` události.
 */
final class AssetImportResult
{
    /**
     * @param list<array{code: string, message: string, path?: string, sourceRef?: ?string}> $warnings
     * @param list<array{severity: string, path: string, code: string, message: string, sourceRef?: ?string}> $issues
     */
    private function __construct(
        public readonly bool $success,
        public readonly int $statusCode,
        public readonly ?string $status,
        public readonly ?int $assetId,
        public readonly array $warnings,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $issues,
    ) {
    }

    /** @param list<array{code: string, message: string, path?: string, sourceRef?: ?string}> $warnings */
    public static function ok(string $status, ?int $assetId, array $warnings = [], int $statusCode = 200): self
    {
        return new self(true, $statusCode, $status, $assetId, $warnings, null, null, []);
    }

    /** @param list<array{severity: string, path: string, code: string, message: string, sourceRef?: ?string}> $issues */
    public static function error(string $code, string $message, array $issues = [], int $statusCode = 422): self
    {
        return new self(false, $statusCode, null, null, [], $code, $message, $issues);
    }

    /** @return array<string, mixed> tělo odpovědi (úspěch) */
    public function toArray(): array
    {
        return ['status' => $this->status, 'assetId' => $this->assetId, 'warnings' => $this->warnings];
    }
}
