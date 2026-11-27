<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Numbering\NumberContext;
use Shipard\Core\Numbering\NumberPattern;

/**
 * Viewer druhů smluv (Nastavení → Budovy, #174 D18). Řádek: název, kód
 * druhu, typ, ukázka čísla podle vzorce a štítek stavu; ikona podle typu.
 * Detail: typ a poznámka, číslování (kód, vzorec, restart, ukázka čísla).
 * Fulltext: název a kód.
 */
class ContractKindsViewer extends BuildingsViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT k.`id`, k.`name`, k.`type`, k.`notice`, k.`number_code`, k.`number_pattern`,'
            . ' k.`docState`, k.`docStateMain`'
            . ' FROM `' . $this->table . '` k';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'k');

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['k.`name`', 'k.`number_code`'], $search);
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY k.`docStateMain` ASC, k.`name` ASC, k.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $type = (string) ($rowData['type'] ?? '');
        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['number_code']) ? (string) $rowData['number_code'] : null,
        ];

        $t2 = [];
        if ($type !== '') {
            $t2[] = ['text' => $this->types()->label($type), 'class' => 'primary'];
        }
        $sample = $this->sampleNumber($rowData);
        if ($sample !== null) {
            $t2[] = ['text' => $sample, 'class' => 'muted'];
        }
        $badge = $this->rowStateTag($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        if (!empty($rowData['notice'])) {
            $row['t3'] = (string) $rowData['notice'];
        }
        $icon = $type !== '' ? $this->types()->icon($type) : null;
        if ($icon !== null) {
            $row['icon'] = $icon;
        }
        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    protected function detailTabs(array $record): array
    {
        $type = (string) ($record['type'] ?? '');

        $identity = [];
        $this->addItem($identity, $this->columnLabel('type', 'Contract type'), $type !== '' ? $this->types()->label($type) : null);
        $this->addItem($identity, $this->columnLabel('notice', 'Note'), $record['notice'] ?? null);

        $numbering = [];
        $this->addItem($numbering, $this->columnLabel('number_code', 'Kind code (%C)'), $record['number_code'] ?? null);
        $this->addItem($numbering, $this->columnLabel('number_pattern', 'Contract number pattern'), $record['number_pattern'] ?? null);
        $this->addItem(
            $numbering,
            $this->columnLabel('reset_scope', 'Counter reset'),
            $this->cfgLabel(ContractKindDocument::RESET_SCOPES_CFG_ITEM, (string) ($record['reset_scope'] ?? '')),
        );
        $this->addItem($numbering, $this->text('label.sampleNumber', 'Number example'), $this->sampleNumber($record));

        return [$this->propertiesTab([
            ['title' => $this->sharedText('groupIdentity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.numbering', 'Numbering'), 'items' => $numbering],
        ])];
    }

    /**
     * Ukázka čísla první smlouvy letošního roku podle vzorce druhu
     * („KA-2027-0001“); null u prázdného nebo neplatného vzorce.
     *
     * @param array<string, mixed> $kind
     */
    protected function sampleNumber(array $kind): ?string
    {
        $pattern = trim((string) ($kind['number_pattern'] ?? ''));
        $code = trim((string) ($kind['number_code'] ?? ''));
        if ($pattern === '' || NumberPattern::validate($pattern, $code !== '' ? $code : null) !== []) {
            return null;
        }
        return NumberPattern::resolve($pattern, new NumberContext(
            sequence:   1,
            seriesCode: $code,
            yearLabel:  $this->currentYear(),
        ));
    }

    /** Rok ukázky čísla (přepsatelné v testech). */
    protected function currentYear(): string
    {
        return date('Y');
    }
}
