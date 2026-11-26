<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Docs;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Tržby dokladů pro záložku detailu, štítek hlavičky a štítek v seznamu
 * dokladů (#171 D13, U18, U19): čtení tržeb, **stav evidence dokladu**
 * (nejhorší stav jeho tržeb — {@see SaleState::worst()}) a jeho popisek
 * a styl ze sady stavů tržby. Jedno místo, aby detail i seznam říkaly totéž.
 */
final class DocSales
{
    public const string STATES_CFG = 'economy.salesRegistration.saleStates';

    /** Třída spanu v řádku seznamu podle stylu stavu tržby (viz `TableViewer::rowStateClass()`). */
    private const array SPAN_CLASS = [
        'edit'      => 'warning',
        'done'      => 'success',
        'cancelled' => 'danger',
        'archive'   => 'muted',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ConfigRuntime $config,
    ) {
    }

    /**
     * Tržby jednoho dokladu v pořadí vzniku.
     *
     * @return list<array<string, mixed>>
     */
    public function of(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_sequence], [serial_number], [sold_at], [amount], [currency], [state],'
            . ' [confirmation_code], [attempt_count], [test]'
            . ' FROM %n WHERE [doc_head] = %i ORDER BY [doc_sequence]',
            SaleSync::TABLE,
            $docId,
        );
        return array_map(static fn($row): array => iterator_to_array($row), $rows);
    }

    /**
     * Stavy tržeb dokladů jedné stránky seznamu — **jeden dotaz** (U19).
     *
     * @param list<int> $docIds
     * @return array<int, array{states: list<string>, test: bool}> jen doklady s tržbou
     */
    public function statesOf(array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [doc_head], [state], [test] FROM %n WHERE [doc_head] IN %in ORDER BY [doc_head], [doc_sequence]',
            SaleSync::TABLE,
            $docIds,
        );
        $byDoc = [];
        foreach ($rows as $row) {
            $docId = (int) $row['doc_head'];
            $byDoc[$docId] ??= ['states' => [], 'test' => false];
            $byDoc[$docId]['states'][] = (string) $row['state'];
            $byDoc[$docId]['test'] = $byDoc[$docId]['test'] || !empty($row['test']);
        }
        return $byDoc;
    }

    /** Popisek stavu tržby ze sady stavů (lokalizovaný kompilací), jinak kód. */
    public function stateLabel(string $state): string
    {
        return (string) ($this->states()[$state]['name'] ?? $state);
    }

    /** Styl stavu tržby (`docState_*`) — štítek detailu. */
    public function stateStyle(string $state): string
    {
        return (string) ($this->states()[$state]['stateStyle'] ?? 'concept');
    }

    /** Třída spanu v řádku seznamu pro stav tržby. */
    public function spanClass(string $state): string
    {
        return self::SPAN_CLASS[$this->stateStyle($state)] ?? 'muted';
    }

    /**
     * Štítek evidence dokladu — „EET“ (u potvrzení z playgroundu „EET test“)
     * a stav; null = doklad nemá tržbu.
     *
     * @param list<string> $states stavy tržeb dokladu v pořadí vzniku
     * @return array{state: string, short: string, label: string}|null
     */
    public function tag(array $states, bool $test): ?array
    {
        $worst = SaleState::worst($states);
        if ($worst === null) {
            return null;
        }
        $t = Texts::of($this->config);
        $short = $test ? $t->t('doc.tagTest', 'EET test') : $t->t('doc.tag', 'EET');
        return [
            'state' => $worst,
            'short' => $short,
            'label' => $t->t('doc.tagWithState', '{tag}: {state}', ['tag' => $short, 'state' => $this->stateLabel($worst)]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function states(): array
    {
        $cfg = $this->config?->cfgItem(self::STATES_CFG);
        return is_array($cfg) ? $cfg : [];
    }
}
