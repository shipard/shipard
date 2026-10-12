<?php

declare(strict_types=1);

namespace Shipard\Core\Config;

use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\FileOwnership;

/**
 * Stav zdroje dat — `config/state.json` (issue shipard/shipard#56, docs/ds-state.md).
 *
 * Dvě osy: lifecycle stav (active / read_only / suspended / pending_deletion)
 * a maintenance overlay (reason + since). Aktivní maintenance má přednost —
 * DS se navenek chová jako `suspended`.
 *
 * Zdroj pravdy je soubor, ne DB: čte se před připojením k databázi a funguje
 * i když DB neexistuje nebo je uprostřed restore. Chybějící soubor = active
 * (zpětná kompatibilita). Nečitelný / nevalidní soubor = fail-closed
 * `suspended` + error log — poškozený stavový soubor nesmí tiše otevřít DS,
 * který měl být zavřený.
 *
 * Třetí, nezávislá osa: přepínač periodických úloh `cron` (#115 D1, D3).
 * Chybí nebo `true` = dispatcher pro DS úlohy spouští (D4), `false` = DS
 * přeskočí celý. Stav ani maintenance ho nemění; jiný typ než boolean je
 * fail-closed jako ostatní nevalidní hodnoty.
 */
final class DataSourceState
{
    public const int VERSION = 1;

    public const string ACTIVE = 'active';
    public const string READ_ONLY = 'read_only';
    public const string SUSPENDED = 'suspended';
    public const string PENDING_DELETION = 'pending_deletion';

    public const array STATES = [self::ACTIVE, self::READ_ONLY, self::SUSPENDED, self::PENDING_DELETION];

    public const array MAINTENANCE_REASONS = ['import', 'restore', 'migration', 'manual'];

    public const string FILE_NAME = 'state.json';

    private function __construct(
        private readonly string $state,
        private readonly ?string $maintenanceReason,
        private readonly ?string $maintenanceSince,
        private readonly ?string $deleteAfter,
        /** Přepínač periodických úloh (#115) — mutátory ho musí přenést, proto bez výchozí hodnoty. */
        private readonly bool $cronEnabled,
        private readonly ?string $changedBy,
        private readonly ?string $changed,
        /** true = soubor chyběl nebo byl poškozený (stav odvozený, ne přečtený) */
        private readonly bool $fromFile,
        private readonly bool $corrupted,
    ) {
    }

    public static function filePath(string $dataSourceDir): string
    {
        return rtrim($dataSourceDir, '/') . '/config/' . self::FILE_NAME;
    }

    public static function active(): self
    {
        return new self(self::ACTIVE, null, null, null, true, null, null, false, false);
    }

    /**
     * Načte stav z `config/state.json`. Chybějící soubor → active. Cokoli
     * jiného, co nelze bezpečně interpretovat → suspended (fail-closed).
     */
    public static function load(string $dataSourceDir): self
    {
        $path = self::filePath($dataSourceDir);
        if (!file_exists($path)) {
            return self::active();
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return self::failClosed($path, 'file not readable');
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return self::failClosed($path, 'invalid JSON');
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            return self::failClosed($path, 'unknown version: ' . json_encode($data['version'] ?? null));
        }

        $state = $data['state'] ?? null;
        if (!is_string($state) || !in_array($state, self::STATES, true)) {
            return self::failClosed($path, 'unknown state: ' . json_encode($state));
        }

        $reason = null;
        $since = null;
        if (array_key_exists('maintenance', $data) && $data['maintenance'] !== null) {
            $m = $data['maintenance'];
            if (!is_array($m)) {
                return self::failClosed($path, 'maintenance must be an object');
            }
            $reason = $m['reason'] ?? null;
            if (!is_string($reason) || !in_array($reason, self::MAINTENANCE_REASONS, true)) {
                return self::failClosed($path, 'unknown maintenance reason: ' . json_encode($reason));
            }
            $since = is_string($m['since'] ?? null) ? $m['since'] : null;
        }

        $deleteAfter = is_string($data['deleteAfter'] ?? null) ? $data['deleteAfter'] : null;

        // Chybějící klíč = zapnuto (D4); starší soubory ho nemají.
        $cron = $data['cron'] ?? true;
        if (!is_bool($cron)) {
            return self::failClosed($path, 'cron must be a boolean');
        }

        $changedBy = is_string($data['changedBy'] ?? null) ? $data['changedBy'] : null;
        $changed = is_string($data['changed'] ?? null) ? $data['changed'] : null;

        return new self($state, $reason, $since, $deleteAfter, $cron, $changedBy, $changed, true, false);
    }

    private static function failClosed(string $path, string $why): self
    {
        ErrorLogger::error('data source state file unusable — treating as suspended (fail-closed)', [
            'path' => $path,
            'reason' => $why,
        ]);
        return new self(self::SUSPENDED, null, null, null, true, null, null, true, true);
    }

    // ── Gettery ──────────────────────────────────────────────────────────────

    /** Lifecycle stav bez ohledu na maintenance. */
    public function getState(): string
    {
        return $this->state;
    }

    public function isMaintenanceActive(): bool
    {
        return $this->maintenanceReason !== null;
    }

    /** Stav, jak se DS chová navenek: maintenance → suspended, jinak lifecycle. */
    public function getEffectiveState(): string
    {
        return $this->isMaintenanceActive() ? self::SUSPENDED : $this->state;
    }

    /** HTTP má vracet 503 (D3). `read_only` ve fázi 1 neblokuje. */
    public function blocksHttp(): bool
    {
        return in_array($this->getEffectiveState(), [self::SUSPENDED, self::PENDING_DELETION], true);
    }

    public function getMaintenanceReason(): ?string
    {
        return $this->maintenanceReason;
    }

    public function getMaintenanceSince(): ?string
    {
        return $this->maintenanceSince;
    }

    public function getDeleteAfter(): ?string
    {
        return $this->deleteAfter;
    }

    /** Přepínač periodických úloh (#115): false = dispatcher DS přeskočí celý. */
    public function isCronEnabled(): bool
    {
        return $this->cronEnabled;
    }

    public function getChangedBy(): ?string
    {
        return $this->changedBy;
    }

    public function getChanged(): ?string
    {
        return $this->changed;
    }

    /** false = `state.json` chybí (stav odvozený jako active). */
    public function isFromFile(): bool
    {
        return $this->fromFile;
    }

    /** true = soubor existuje, ale nešel přečíst/interpretovat (fail-closed). */
    public function isCorrupted(): bool
    {
        return $this->corrupted;
    }

    // ── Mutátory (vrací novou instanci) ──────────────────────────────────────

    public function withState(string $state): self
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(
                "Unknown data source state '{$state}' (valid: " . implode(', ', self::STATES) . ')'
            );
        }
        return new self(
            $state,
            $this->maintenanceReason,
            $this->maintenanceSince,
            $state === self::PENDING_DELETION ? $this->deleteAfter : null,
            $this->cronEnabled,
            $this->changedBy,
            $this->changed,
            $this->fromFile,
            false,
        );
    }

    public function withMaintenance(string $reason, ?\DateTimeImmutable $since = null): self
    {
        if (!in_array($reason, self::MAINTENANCE_REASONS, true)) {
            throw new \InvalidArgumentException(
                "Unknown maintenance reason '{$reason}' (valid: " . implode(', ', self::MAINTENANCE_REASONS) . ')'
            );
        }
        // Přepnutí důvodu při běžícím maintenance zachová původní `since`.
        $sinceValue = $this->maintenanceSince ?? self::formatUtc($since ?? new \DateTimeImmutable());
        return new self(
            $this->state,
            $reason,
            $sinceValue,
            $this->deleteAfter,
            $this->cronEnabled,
            $this->changedBy,
            $this->changed,
            $this->fromFile,
            false,
        );
    }

    public function withoutMaintenance(): self
    {
        return new self(
            $this->state,
            null,
            null,
            $this->deleteAfter,
            $this->cronEnabled,
            $this->changedBy,
            $this->changed,
            $this->fromFile,
            false,
        );
    }

    public function withDeleteAfter(?\DateTimeImmutable $deleteAfter): self
    {
        return new self(
            $this->state,
            $this->maintenanceReason,
            $this->maintenanceSince,
            $deleteAfter === null ? null : self::formatUtc($deleteAfter),
            $this->cronEnabled,
            $this->changedBy,
            $this->changed,
            $this->fromFile,
            false,
        );
    }

    /** Přepínač periodických úloh (#115) — stav i maintenance zůstávají. */
    public function withCron(bool $enabled): self
    {
        return new self(
            $this->state,
            $this->maintenanceReason,
            $this->maintenanceSince,
            $this->deleteAfter,
            $enabled,
            $this->changedBy,
            $this->changed,
            $this->fromFile,
            false,
        );
    }

    // ── Zápis ────────────────────────────────────────────────────────────────

    /**
     * Atomický zápis (tmp + rename) do `config/state.json` — soubor čte
     * resolver při každém requestu a cron každou minutu, roztržený zápis by
     * DS fail-closed zavřel. Selhání = výjimka, nikdy tichý úspěch.
     * Nastavuje `changed` (UTC) a `changedBy`.
     *
     * Vlastník: zápis běží i pod rootem (`sudo shpd-ds ds-state`, `ds-cron`,
     * `cron-install`) a soubor `root:root 0640` by runtime zdroje dat
     * nepřečetl — DS by skončil fail-closed `suspended`. Dočasný soubor proto
     * před `rename` dostane vlastníka a skupinu podle `config/main.json`
     * (bez něj podle adresáře `config/`); když to nejde, zápis skončí
     * výjimkou a původní soubor zůstane. `$ownership` je šev pro testy.
     */
    public function save(
        string $dataSourceDir,
        string $changedBy,
        ?\DateTimeImmutable $now = null,
        ?FileOwnership $ownership = null,
    ): self {
        if ($this->corrupted) {
            throw new \LogicException('Refusing to save a fail-closed state derived from a corrupted file');
        }

        $saved = new self(
            $this->state,
            $this->maintenanceReason,
            $this->maintenanceSince,
            $this->deleteAfter,
            $this->cronEnabled,
            $changedBy,
            self::formatUtc($now ?? new \DateTimeImmutable()),
            true,
            false,
        );

        $path = self::filePath($dataSourceDir);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot write {$path}: directory {$dir} does not exist");
        }

        $json = (string) json_encode($saved->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json .= "\n";
        $tmp = $path . '.tmp';

        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            throw new \RuntimeException(self::writeError("write {$tmp}", $dir));
        }
        @chmod($tmp, 0640);
        try {
            ($ownership ?? new FileOwnership())->matchTo($tmp, self::ownerReference($dir));
        } catch (\RuntimeException $e) {
            @unlink($tmp);
            throw new \RuntimeException(
                "Refusing to install {$path} with an owner the data source cannot read: " . $e->getMessage(),
                0,
                $e,
            );
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(self::writeError("rename {$tmp} to {$path}", $dir));
        }

        return $saved;
    }

    /**
     * Soubor, podle kterého se řídí vlastník `state.json`: `main.json` zdroji
     * dat prokazatelně patří (`0600`, zakládá ho ds-create a chownuje); když
     * chybí, adresář `config/`.
     */
    private static function ownerReference(string $configDir): string
    {
        $main = $configDir . '/main.json';
        return is_file($main) ? $main : $configDir;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'version' => self::VERSION,
            'state' => $this->state,
        ];
        if ($this->maintenanceReason !== null) {
            $out['maintenance'] = [
                'reason' => $this->maintenanceReason,
                'since' => $this->maintenanceSince,
            ];
        }
        if ($this->deleteAfter !== null) {
            $out['deleteAfter'] = $this->deleteAfter;
        }
        // Jen při vypnutí — soubor bez klíče zůstává čitelný starším kódem.
        if (!$this->cronEnabled) {
            $out['cron'] = false;
        }
        if ($this->changedBy !== null) {
            $out['changedBy'] = $this->changedBy;
        }
        if ($this->changed !== null) {
            $out['changed'] = $this->changed;
        }
        return $out;
    }

    public static function formatUtc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function writeError(string $action, string $dir): string
    {
        $user = 'unknown';
        if (function_exists('posix_geteuid')) {
            $uid = posix_geteuid();
            $user = posix_getpwuid($uid)['name'] ?? (string) $uid;
        }
        return "Failed to {$action} (running as {$user}). Check permissions on {$dir}.";
    }
}
