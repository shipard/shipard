<?php

declare(strict_types=1);

namespace Shipard\Core\Ai;

/**
 * Serverové AI připojení pro vývoj (tasks/testing-phase4.md D40, #103 D10)
 * — volitelná sekce `devAi` v `/etc/shipard/server.json`:
 *
 *   "devAi": { "platform": "anthropic", "baseUrl": null }
 *
 * Chybějící sekce = platforma `anthropic` s výchozí adresou platformy
 * (`core.ai.platforms`). Klíč sekce nenese — žije v souboru
 * `secrets/dev-ai-<platforma>.key` vedle `server.json` ({@see DevAiKeyStore}).
 * Platí jen na serveru v režimu `development`; to hlídá
 * {@see ServerAiConnection::detect()}, ne tahle třída.
 */
final readonly class DevAiConfig
{
    public const string DEFAULT_PLATFORM = 'anthropic';

    public function __construct(
        public string $platform = self::DEFAULT_PLATFORM,
        public ?string $baseUrl = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $this->platform) !== 1) {
            throw new \RuntimeException(
                "Server config 'devAi.platform' must be a platform id (a-z, 0-9, -), got '{$this->platform}'",
            );
        }
        if ($this->baseUrl !== null && !preg_match('#^https?://#', $this->baseUrl)) {
            throw new \RuntimeException("Server config 'devAi.baseUrl' must start with http:// or https://");
        }
    }

    /** @param array<string, mixed>|null $data sekce `devAi`; null = chybí */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self();
        }
        $platform = $data['platform'] ?? self::DEFAULT_PLATFORM;
        if (!is_string($platform) || trim($platform) === '') {
            throw new \RuntimeException("Server config 'devAi.platform' must be a non-empty string");
        }
        $baseUrl = $data['baseUrl'] ?? null;
        if ($baseUrl !== null && !is_string($baseUrl)) {
            throw new \RuntimeException("Server config 'devAi.baseUrl' must be a string or null");
        }
        $baseUrl = $baseUrl !== null ? rtrim(trim($baseUrl), '/') : null;
        return new self(trim($platform), $baseUrl === '' ? null : $baseUrl);
    }
}
