<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Utils\IdGenerator;

/**
 * Přepínač periodických úloh jednoho zdroje dat podle ID, bez přechodu do
 * jeho adresáře (#115 D14). Jediná implementace pro `shpd-server ds-cron`
 * i první instalaci cronu v `cron-install` (D10) — obě ústí jen vypisují
 * výsledek.
 *
 * Nikdy nevyhazuje: každý důvod, proč se `state.json` nezapsal, je hodnota
 * `CronSwitchOutcome`, aby volající mohl rozlišit poškozený soubor (zdroj
 * dat je fail-closed zavřený a žádnou úlohu nedostane) od skutečné chyby
 * zápisu. Vlastníka souboru pod rootem řeší `DataSourceState::save()`.
 */
final class DataSourceCronSwitch
{
    public function __construct(
        private readonly string $dataSourcesDir,
    ) {}

    public function switch(string $id, bool $enable, string $changedBy, ?\DateTimeImmutable $now = null): CronSwitchResult
    {
        $label = $enable ? 'on' : 'off';

        // Tvar ID před složením cesty — `../x` nesmí dojít k is_file().
        if (!preg_match(IdGenerator::ID_PATTERN, $id)) {
            return new CronSwitchResult($id, CronSwitchOutcome::InvalidId,
                "✗ {$id}: invalid data source ID (expected xxxx-xxxx-xxxx-xxxx)");
        }
        $dir = $this->dataSourcesDir . '/' . $id;
        if (!is_file($dir . '/config/main.json')) {
            return new CronSwitchResult($id, CronSwitchOutcome::Unknown,
                "✗ {$id}: unknown data source (no config/main.json)");
        }

        $state = DataSourceState::load($dir);
        if ($state->isCorrupted()) {
            return new CronSwitchResult($id, CronSwitchOutcome::Corrupted,
                "✗ {$id}: state.json unusable — not overwriting; repair first: cd {$dir} && shpd-ds ds-state set <state>");
        }
        if ($state->isCronEnabled() === $enable) {
            return new CronSwitchResult($id, CronSwitchOutcome::Unchanged, "· {$id}: unchanged (cron {$label})");
        }

        try {
            $state->withCron($enable)->save($dir, $changedBy, $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\RuntimeException $e) {
            return new CronSwitchResult($id, CronSwitchOutcome::WriteFailed, "✗ {$id}: {$e->getMessage()}");
        }
        return new CronSwitchResult($id, CronSwitchOutcome::Changed, "✓ {$id}: cron {$label}");
    }

    /** Název z `main.json` shovívavě ({@see DataSourceInventory}) — nečitelný soubor dá `?`, volající kvůli němu neselže. */
    public function displayName(string $id): string
    {
        return (new DataSourceInventory($this->dataSourcesDir))->read($this->dataSourcesDir . '/' . $id)?->name ?? '?';
    }
}
