<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

/**
 * Strom zakázek (docs/work-orders.md D15): podzakázky, řetězec předků
 * a zákazník interní jednorázové zakázky „z nadřazené“ (D14) — nejbližší
 * externí zakázka v řetězci předků, která zákazníka má. Nic se neukládá,
 * čte se vždy z aktuálních hlaviček. Sčítání podzakázek sem nepatří (D19).
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class WorkOrderTreeService
{
    /** Strop průchodu řetězcem předků — pojistka proti poškozeným datům. */
    public const MAX_DEPTH = 100;

    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        private readonly WorkOrderTypes $types,
    ) {
    }

    /**
     * Přímé podzakázky mimo koš, podle čísla.
     *
     * @return list<array{id: int, number: ?string, title: string, type: string, docState: int, customerName: ?string}>
     */
    public function children(int $id): array
    {
        $out = [];
        foreach ($this->loadChildren($id) as $row) {
            $out[] = self::node($row);
        }
        return $out;
    }

    /**
     * Řetězec předků od nejbližšího; prázdný bez nadřazené.
     *
     * @return list<array{id: int, number: ?string, title: string, type: string, docState: int, customerName: ?string}>
     */
    public function ancestors(int $id): array
    {
        $out = [];
        $row = $this->loadRow($id);
        $depth = 0;
        while ($row !== null && $depth++ < self::MAX_DEPTH) {
            $parent = (int) ($row['parent'] ?? 0);
            if ($parent <= 0) {
                break;
            }
            $row = $this->loadRow($parent);
            if ($row === null) {
                break;
            }
            $out[] = self::node($row);
        }
        return $out;
    }

    /**
     * Zákazník zakázky: vlastní u externího typu, jinak z nejbližší externí
     * zakázky v řetězci předků (`from` = odkud). Null = žádný.
     *
     * @param array<string, mixed> $record hlavička (type, customer, customer_name)
     * @return array{id: int, name: string, from: ?array{id: int, number: ?string, title: string}}|null
     */
    public function effectiveCustomer(array $record): ?array
    {
        $type = (string) ($record['type'] ?? '');
        if ($type !== '' && $this->types->isExternal($type)) {
            $customer = (int) ($record['customer'] ?? 0);
            return $customer > 0
                ? ['id' => $customer, 'name' => (string) ($record['customer_name'] ?? ''), 'from' => null]
                : null;
        }
        foreach ($this->ancestors((int) ($record['id'] ?? 0)) as $ancestor) {
            if (!$this->types->isExternal($ancestor['type'])) {
                continue;
            }
            $row = $this->loadRow($ancestor['id']);
            $customer = (int) ($row['customer'] ?? 0);
            if ($customer > 0) {
                return [
                    'id'   => $customer,
                    'name' => (string) ($row['customer_name'] ?? ''),
                    'from' => ['id' => $ancestor['id'], 'number' => $ancestor['number'], 'title' => $ancestor['title']],
                ];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, number: ?string, title: string, type: string, docState: int, customerName: ?string}
     */
    private static function node(array $row): array
    {
        $number = trim((string) ($row['number'] ?? ''));
        $customer = trim((string) ($row['customer_name'] ?? ''));
        return [
            'id'           => (int) $row['id'],
            'number'       => $number !== '' ? $number : null,
            'title'        => (string) ($row['title'] ?? ''),
            'type'         => (string) ($row['type'] ?? ''),
            'docState'     => (int) ($row['docState'] ?? 10),
            'customerName' => $customer !== '' ? $customer : null,
        ];
    }

    /**
     * Hlavička se jménem zákazníka (id, number, title, type, parent, customer,
     * customer_name, docState), null = neexistuje.
     *
     * @return array<string, mixed>|null
     */
    protected function loadRow(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [w].[id], [w].[number], [w].[title], [w].[type], [w].[parent], [w].[customer], [w].[docState],'
            . ' [p].[full_name] AS [customer_name]'
            . ' FROM [' . WorkOrderDocument::TABLE . '] [w]'
            . ' LEFT JOIN [base_persons_persons] [p] ON [p].[id] = [w].[customer]'
            . ' WHERE [w].[id] = %i',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    /**
     * Podzakázky mimo koš.
     *
     * @return list<array<string, mixed>>
     */
    protected function loadChildren(int $id): array
    {
        if ($this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [w].[id], [w].[number], [w].[title], [w].[type], [w].[docState], [p].[full_name] AS [customer_name]'
            . ' FROM [' . WorkOrderDocument::TABLE . '] [w]'
            . ' LEFT JOIN [base_persons_persons] [p] ON [p].[id] = [w].[customer]'
            . ' WHERE [w].[parent] = %i AND [w].[docState] <> %i'
            . ' ORDER BY [w].[number] ASC, [w].[id] ASC',
            $id,
            WorkOrderDocument::STATE_DELETED,
        );
        return array_map(static fn(iterable $row): array => iterator_to_array($row), $rows);
    }
}
