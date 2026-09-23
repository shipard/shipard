<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Dispatch událostí účetního deníku na registrované handlery
 * (`journalEventHandlers` z module.jsonc, sbírá JournalEventHandlerLoader).
 *
 * Mirror DocumentEventDispatcher, jen registrace nejsou per-tabulka (journal
 * události nejsou vázané na konkrétní tabulku) a fire-point je účtovací engine,
 * ne TableGateway. Instanciace je lazy, služby se injektují stejně jako
 * u dokumentových handlerů.
 *
 * Chybová sémantika (mirror stateChanged): výjimku handleru zaloguj a spolkni —
 * commit deníku už proběhl, účtování nesmí spadnout kvůli saldo handleru;
 * další handlery běží dál.
 *
 * Pořadí = pořadí registrace (topologické pořadí modulů × pořadí pole
 * v module.jsonc) — handler smí spoléhat na to, že předchozí doběhl.
 * Handler dostane i dispatcher sám (AbstractJournalEventHandler::setJournalEvents),
 * aby účtování spuštěné z handleru vyslalo journalWritten dál; re-entrantní
 * dispatch je bezpečný (žádný stav mimo memoizované instance). Stejně tak
 * dostane sadu contributorů deníku (#79 D3b), aby engine postavený
 * z handleru doplnil příspěvky jako engine z běžné cesty.
 */
final class JournalEventDispatcher
{
    /** @var list<array{class: string, events: list<string>}> */
    private array $registrations = [];

    /** @var array<string, JournalEventHandler> class → instance */
    private array $instances = [];

    /**
     * @param list<array{class: string, events: list<string>}> $registrations
     */
    public function __construct(
        array $registrations = [],
        private readonly ?\Dibi\Connection $db = null,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?JournalContributorSet $journalContributors = null,
    ) {
        foreach ($registrations as $reg) {
            $this->registrations[] = [
                'class'  => $reg['class'],
                'events' => $reg['events'],
            ];
        }
    }

    public function dispatchJournalWritten(string $sourceKind, int $sourceId): void
    {
        foreach ($this->handlersFor('journalWritten') as $handler) {
            try {
                $handler->onJournalWritten($sourceKind, $sourceId);
            } catch (\Throwable $e) {
                ErrorLogger::logException(
                    $e,
                    sprintf(
                        'JournalEventHandler %s::onJournalWritten failed for %s #%d',
                        $handler::class,
                        $sourceKind,
                        $sourceId,
                    ),
                );
            }
        }
    }

    /**
     * @return list<JournalEventHandler>
     */
    private function handlersFor(string $event): array
    {
        $handlers = [];
        foreach ($this->registrations as $reg) {
            if (!in_array($event, $reg['events'], true)) {
                continue;
            }
            $handlers[] = $this->instantiate($reg['class']);
        }
        return $handlers;
    }

    private function instantiate(string $className): JournalEventHandler
    {
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        $handler = new $className();
        if (!$handler instanceof JournalEventHandler) {
            throw new \LogicException(
                "Class {$className} does not implement JournalEventHandler",
            );
        }

        if ($handler instanceof AbstractJournalEventHandler) {
            if ($this->db !== null) {
                $handler->setDb($this->db);
            }
            if ($this->config !== null) {
                $handler->setConfig($this->config);
            }
            if ($this->dsConfig !== null) {
                $handler->setDsConfig($this->dsConfig);
            }
            $handler->setJournalEvents($this);
            $handler->setJournalContributors($this->journalContributors);
        }

        return $this->instances[$className] = $handler;
    }
}
