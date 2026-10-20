<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Shipard\Core\Settings\KeyValueStore;

/**
 * Stav pokusů stahování v nastavení zdroje dat (#121 task B §4.3), klíč
 * `economy.exchangeRates.downloadState`:
 *
 *   {"lastError": <unix čas>|null, "attempts": {"<typ>|<YYYY-MM>": <unix čas>}}
 *
 * `attempts` = poslední dotaz na měsíc typu (pauza 1 h), `lastError`
 * = poslední chyba služby nebo sítě (pauza celého běhu). Čte a píše ho
 * jen cron mód; `save()` zapisuje jen po změně a přitom zahodí měsíce
 * mimo okno (jen podle měsíce, nikdy podle typu — `--type` nesmí
 * smazat pokusy ostatních typů).
 */
final class DownloadState
{
    public const string KEY = 'economy.exchangeRates.downloadState';

    private ?int $lastError = null;

    /** @var array<string, int> */
    private array $attempts = [];

    private bool $dirty = false;

    public function __construct(private readonly KeyValueStore $settings)
    {
        $raw = $settings->get(self::KEY);
        if (!is_array($raw)) {
            return;
        }
        $lastError = $raw['lastError'] ?? null;
        $this->lastError = is_int($lastError) ? $lastError : null;
        foreach ($raw['attempts'] ?? [] as $key => $at) {
            if (is_string($key) && is_int($at)) {
                $this->attempts[$key] = $at;
            }
        }
    }

    public static function attemptKey(string $listType, string $month): string
    {
        return $listType . '|' . $month;
    }

    public function lastError(): ?int
    {
        return $this->lastError;
    }

    public function attemptAt(string $listType, string $month): ?int
    {
        return $this->attempts[self::attemptKey($listType, $month)] ?? null;
    }

    public function recordAttempt(string $listType, string $month, int $at): void
    {
        $this->attempts[self::attemptKey($listType, $month)] = $at;
        $this->dirty = true;
    }

    public function recordError(int $at): void
    {
        $this->lastError = $at;
        $this->dirty = true;
    }

    /** Zapíše stav, jen když se změnil; měsíce mimo [$fromMonth, $toMonth] (YYYY-MM) zahodí. */
    public function save(string $fromMonth, string $toMonth): void
    {
        if (!$this->dirty) {
            return;
        }
        foreach (array_keys($this->attempts) as $key) {
            $month = substr($key, strrpos($key, '|') + 1);
            if ($month < $fromMonth || $month > $toMonth) {
                unset($this->attempts[$key]);
            }
        }
        $this->settings->set(self::KEY, ['lastError' => $this->lastError, 'attempts' => $this->attempts]);
        $this->dirty = false;
    }

    /** @return array{lastError: ?int, attempts: array<string, int>} */
    public function toArray(): array
    {
        return ['lastError' => $this->lastError, 'attempts' => $this->attempts];
    }
}
