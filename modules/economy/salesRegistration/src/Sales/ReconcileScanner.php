<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;

/**
 * Záchranná síť evidence tržeb (#171 D7, U9): doklady ve stavu V pořádku
 * a Storno, jejichž stav se změnil v posledních dnech, projde stejným
 * rozdílovým výpočtem jako handler přechodu ({@see SaleSync}). Handler běží
 * po commitu a výjimky polyká — co mu uteklo, tady vznikne, s časem tržby
 * z dokladu. Výpočet je idempotentní, takže doklad, který tržby má, dá „nic“.
 *
 * Stejným průchodem (nanečisto) hledá upozornění doklady, které do evidence
 * spadají, ale tržbu založit nejde.
 *
 * Předvýběr v SQL: typy dokladů z pravidel registrací V pořádku a k tomu
 * doklady, které už tržbu mají (vyrovnání po zániku registrace). Importované
 * doklady vynechává rovnou, stejně jako doklady s tržbou založenou po
 * posledním přechodu — u těch je rozdíl hotový.
 */
class ReconcileScanner
{
    public const int DEFAULT_DAYS = 7;

    private const array STATES = [40, 30];

    public function __construct(
        private readonly Connection $db,
        private readonly DocFactsLoader $loader,
        private readonly SaleSync $sync,
    ) {
    }

    public static function create(Connection $db, ?ConfigRuntime $config, ?DataSourceConfig $dsConfig): self
    {
        $loader = new DocFactsLoader($db, $config, $dsConfig);
        return new self($db, $loader, new SaleSync($db, $loader, $config));
    }

    /**
     * Projde doklady v okně a vrátí výsledky, které nejsou „nic“.
     *
     * @param bool $dryRun nic nezapíše — vrací, co by vzniklo
     * @return list<SyncResult>
     */
    public function run(int $days = self::DEFAULT_DAYS, ?\DateTimeImmutable $now = null, bool $dryRun = false): array
    {
        $now ??= new \DateTimeImmutable();
        $results = [];
        foreach ($this->candidates($now->modify('-' . max(1, $days) . ' days')) as $docId) {
            $result = $this->sync($docId, $now, $dryRun);
            if ($result->status !== SyncResult::NONE) {
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Doklady, které má smysl vyhodnotit (přepsatelné v testech).
     *
     * @return list<int>
     */
    protected function candidates(\DateTimeImmutable $since): array
    {
        $docTypes = [];
        foreach ($this->loader->registrations() as $registration) {
            foreach ($registration->type->ruleDocTypes() as $docType) {
                $docTypes[$docType] = true;
            }
        }
        // Bez registrace V pořádku zbývají jen doklady, které už tržbu mají.
        $rows = $this->db->fetchAll(
            'SELECT h.[id] FROM %n h'
            . ' WHERE h.[docState] IN %in AND h.[doc_state_changed_at] >= %s AND h.[imported] = 0'
            . ' AND (h.[doc_type] IN %in OR EXISTS (SELECT 1 FROM %n s WHERE s.[doc_head] = h.[id]))'
            // Tržba založená po posledním přechodu = rozdíl k tomu přechodu
            // je spočítaný; takový doklad už síť znovu nevyhodnocuje.
            . ' AND NOT EXISTS (SELECT 1 FROM %n d WHERE d.[doc_head] = h.[id] AND d.[created] >= h.[doc_state_changed_at])'
            . ' ORDER BY h.[id]',
            DocFactsLoader::HEADS,
            self::STATES,
            $since->format('Y-m-d H:i:s'),
            $docTypes !== [] ? array_keys($docTypes) : [''],
            SaleSync::TABLE,
            SaleSync::TABLE,
        );
        return array_map(static fn($row): int => (int) $row['id'], $rows);
    }

    protected function sync(int $docId, \DateTimeImmutable $now, bool $dryRun): SyncResult
    {
        return $this->sync->sync($docId, $now, $dryRun);
    }
}
