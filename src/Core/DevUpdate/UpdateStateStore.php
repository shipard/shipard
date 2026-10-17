<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Server\CronProvisioner;
use Shipard\Core\Server\FileOwnership;

/**
 * Cache poslední kontroly aktualizací (#129 D18, U4) v
 * `/opt/shipard/run/dev-update/`:
 *
 *   - `state.json`       výsledek kontroly ({@see UpdateChecker::check()}), atomicky tmp + rename;
 *   - `check.lock`       `flock` držený běžící kontrolou — dvě naráz nikdy;
 *   - `check.requested`  značka, že API kontrolu právě spustilo: do převzetí
 *                        zámku by jinak dotaz viděl `checking: false` a UI
 *                        by se přestalo ptát; kontrola ji po zápisu smaže,
 *                        po REQUEST_GRACE_SECONDS přestává platit;
 *   - `check.log`        výstup odpojeného `dev-update-check`.
 *
 * Cache drží jen stav vůči GitHubu; pojistky (větev, pracovní strom, úlohy,
 * nástroje) se počítají při každém dotazu ({@see UpdateGuards}). Stav je
 * neplatný (`stale`), když chybí, selhal, je starší než MAX_AGE_SECONDS,
 * nebo se od kontroly změnil HEAD (lokální commit, merge). Automatická
 * kontrola z API ({@see isAutoCheckDue()}) je k tomu po selhání nejdřív
 * za RETRY_AFTER_FAILURE_SECONDS — bez sítě by se jinak spouštěla při
 * každém dotazu dokola; „Zkontrolovat teď“ se backoffem neřídí. Adresář spadá
 * pod `/opt/shipard/run`, který `PermissionSpec` hlídá rekurzivně; zápis
 * pod rootem srovná vlastníka podle run adresáře ({@see FileOwnership}).
 */
final class UpdateStateStore
{
    public const string STATE_FILE = 'state.json';
    public const string CHECK_LOCK = 'check.lock';
    public const string REQUEST_MARK = 'check.requested';
    public const string CHECK_LOG = 'check.log';

    public const int MAX_AGE_SECONDS = 3600;
    public const int REQUEST_GRACE_SECONDS = 60;
    /** Po selhané kontrole (`ok: false`) další automatická nejdřív za 10 minut (U8). */
    public const int RETRY_AFTER_FAILURE_SECONDS = 600;

    /** @param \Closure(): \DateTimeImmutable|null $now */
    public function __construct(
        private readonly string $dir,
        private readonly ?\Closure $now = null,
        private readonly ?FileOwnership $ownership = null,
        private readonly ?string $ownerReference = null,
    ) {}

    public static function defaultDir(): string
    {
        return CronProvisioner::RUN_DIR . '/dev-update';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function path(string $file): string
    {
        return $this->dir . '/' . $file;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new \DateTimeImmutable();
    }

    public static function formatTime(\DateTimeImmutable $dt): string
    {
        return DataSourceState::formatUtc($dt);
    }

    public function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        return @mkdir($this->dir, 0750, true) || is_dir($this->dir);
    }

    /** @return array<string, mixed>|null null = chybí nebo nečitelný JSON */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->path(self::STATE_FILE));
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomický zápis stavu; pod rootem srovná vlastníka podle run adresáře,
     * aby ho PHP-FPM dál četl i přepsal.
     *
     * @param array<string, mixed> $state
     * @throws \RuntimeException adresář nebo soubor nejde zapsat, vlastníka nejde srovnat
     */
    public function write(array $state): void
    {
        if (!$this->ensureDir()) {
            throw new \RuntimeException('Cannot create ' . $this->dir);
        }
        $target = $this->path(self::STATE_FILE);
        $tmp = $target . '.tmp.' . getmypid();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            try {
                ($this->ownership ?? new FileOwnership())->matchTo($tmp, $this->ownerReference ?? CronProvisioner::RUN_DIR);
            } catch (\RuntimeException $e) {
                @unlink($tmp);
                throw $e;
            }
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot rename {$tmp} to {$target}");
        }
    }

    /**
     * Zámek kontroly (`LOCK_EX|LOCK_NB`): handle k podržení po dobu kontroly,
     * null = kontrola už běží nebo zámek nejde otevřít.
     *
     * @return resource|null
     */
    public function tryLock()
    {
        if (!$this->ensureDir()) {
            return null;
        }
        $handle = @fopen($this->path(self::CHECK_LOCK), 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle z {@see tryLock()} */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** Někdo drží `check.lock` (sonda `LOCK_SH|LOCK_NB` jako u obalu úloh). */
    public function isLocked(): bool
    {
        $handle = @fopen($this->path(self::CHECK_LOCK), 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /** Kontrola běží: zámek držený, nebo čerstvá značka požadavku API. */
    public function isChecking(): bool
    {
        if ($this->isLocked()) {
            return true;
        }
        clearstatcache(false, $this->path(self::REQUEST_MARK));
        $requested = @filemtime($this->path(self::REQUEST_MARK));
        if ($requested === false) {
            return false;
        }
        return $this->now()->getTimestamp() - $requested <= self::REQUEST_GRACE_SECONDS;
    }

    /** API právě spustilo odpojenou kontrolu. */
    public function markRequested(): void
    {
        if ($this->ensureDir()) {
            @touch($this->path(self::REQUEST_MARK), $this->now()->getTimestamp());
        }
    }

    /** Kontrola dopsala stav — značka požadavku už neplatí. */
    public function clearRequested(): void
    {
        @unlink($this->path(self::REQUEST_MARK));
    }

    /**
     * Stav je k ničemu: chybí, selhal, je starší než hodina, nebo platí pro
     * jiný HEAD než ten dnešní.
     *
     * @param array<string, mixed>|null $state
     */
    public function isStale(?array $state, ?string $headHash): bool
    {
        if ($state === null || ($state['ok'] ?? false) !== true) {
            return true;
        }
        $checkedAt = is_string($state['checkedAt'] ?? null) ? strtotime($state['checkedAt']) : false;
        if ($checkedAt === false || $this->now()->getTimestamp() - $checkedAt > self::MAX_AGE_SECONDS) {
            return true;
        }
        $stateHead = $state['head']['hash'] ?? null;
        return $headHash === null || !is_string($stateHead) || $stateHead !== $headHash;
    }

    /**
     * Má API spustit kontrolu samo: stav neplatí ({@see isStale()}), ale
     * po selhané kontrole až po RETRY_AFTER_FAILURE_SECONDS od jejího
     * `checkedAt`. Pojistka `notChecked` tím není dotčená — stav dál neplatí.
     *
     * @param array<string, mixed>|null $state
     */
    public function isAutoCheckDue(?array $state, ?string $headHash): bool
    {
        if (!$this->isStale($state, $headHash)) {
            return false;
        }
        if ($state === null || ($state['ok'] ?? false) === true) {
            return true;
        }
        $checkedAt = is_string($state['checkedAt'] ?? null) ? strtotime($state['checkedAt']) : false;
        return $checkedAt === false || $this->now()->getTimestamp() - $checkedAt >= self::RETRY_AFTER_FAILURE_SECONDS;
    }
}
