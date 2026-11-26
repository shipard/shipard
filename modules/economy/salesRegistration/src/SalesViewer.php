<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Economy\SalesRegistration\Engine\SendResult;
use Shipard\Module\Economy\SalesRegistration\Evaluation\Money;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleState;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleStore;

/**
 * Agenda Evidence tržeb (Prodej → Evidence tržeb, #171 D25, U12): tržby
 * z dokladů a stav jejich odeslání správci daně.
 *
 * Seznam: pořadové číslo, částka, čas tržby, jednotka, stav a kód potvrzení;
 * filtry stav, registrace, jednotka a období. Detail: data tržby, odeslání
 * a záložka Pokusy (jedna datová zpráva = jeden řádek). Celá XML zpráv
 * v detailu nejsou — zůstávají v tabulce pokusů pro dohledání.
 *
 * Tržby nemají sadu stavů dokladů (`docState`); stav fronty je vlastní
 * sloupec `state`, popisky a styl z `economy.salesRegistration.saleStates`.
 * Záznamy zakládá jen doplněk — bez akce Přidat.
 */
class SalesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = null;

    public const string LABELS_CFG_ITEM = 'economy.salesRegistration.viewerDetailLabels';
    public const string STATES_CFG = 'economy.salesRegistration.saleStates';
    public const string ATTEMPT_STATUSES_CFG = 'economy.salesRegistration.attemptStatuses';

    /** Souhrnný viewer dokladů — cíl odkazu na doklad (`open_detail`). */
    public const string DOCUMENTS_VIEWER = 'docs.core.heads';

    /** Hodnota filtru stavu: všechno, co ještě není vyřízené. */
    public const string FILTER_OPEN = 'open';

    /** Kolik znaků kódu potvrzení ukázat v řádku seznamu. */
    private const int CODE_TAIL = 8;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT s.*, u.`name` AS `unit_name`, r.`name` AS `registration_name`'
            . ' FROM `' . $this->table . '` s'
            . ' LEFT JOIN `' . UnitDocument::TABLE . '` u ON u.`id` = s.`unit`'
            . ' LEFT JOIN `' . RegistrationDocument::TABLE . '` r ON r.`id` = s.`registration`';

        $conditions = [];
        $params = [];
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'state') {
                if ($value === self::FILTER_OPEN) {
                    $conditions[] = 's.`state` IN %in';
                    $params[] = SaleState::OPEN;
                } else {
                    $conditions[] = 's.`state` = %s';
                    $params[] = (string) $value;
                }
            } elseif ($id === 'registration') {
                $conditions[] = 's.`registration` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'unit') {
                $conditions[] = 's.`unit` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'period') {
                $period = $this->periodRange((int) $value);
                if ($period !== null) {
                    $conditions[] = 's.`sold_at` >= %s AND s.`sold_at` < %s';
                    $params[] = $period[0];
                    $params[] = $period[1];
                }
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(['s.`serial_number`', 's.`confirmation_code`'], $search);
            if ($searchSql !== '') {
                $conditions[] = $searchSql;
                $params = array_merge($params, $searchParams);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY s.`sold_at` DESC, s.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $state = (string) ($rowData['state'] ?? '');
        $stateStyle = $this->stateStyle($state);

        $t2 = [];
        $unit = $this->unitLabel($rowData);
        if ($unit !== '') {
            $t2[] = ['text' => $unit, 'class' => 'muted'];
        }
        $t2[] = ['text' => $this->stateLabel($state), 'class' => $this->rowStateClass($stateStyle)];
        if (!empty($rowData['test'])) {
            $t2[] = ['text' => $this->text('saleTest', 'test'), 'class' => 'warning'];
        }

        $t3 = [];
        $soldAt = $this->formatDateTime($rowData['sold_at'] ?? null);
        if ($soldAt !== null) {
            $t3[] = $soldAt;
        }
        $code = trim((string) ($rowData['confirmation_code'] ?? ''));
        if ($code !== '') {
            $t3[] = $this->text('saleRowCode', 'code …{code}', ['code' => substr($code, -self::CODE_TAIL)]);
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['serial_number'] ?? ''),
            'i1'         => $this->amount($rowData),
            't2'         => $t2,
            't3'         => $t3 !== [] ? implode(' • ', $t3) : null,
            'stateStyle' => $stateStyle,
        ];
    }

    public function getFilters(): array
    {
        $stateOptions = [['value' => self::FILTER_OPEN, 'label' => $this->text('saleFilterOpen', 'Not settled')]];
        foreach ($this->states() as $id => $def) {
            $stateOptions[] = ['value' => (string) $id, 'label' => (string) ($def['name'] ?? $id)];
        }

        $filters = [
            [
                'id'      => 'state',
                'label'   => $this->columnLabel('state', 'State'),
                'type'    => 'select',
                'options' => $stateOptions,
            ],
        ];

        $registrations = $this->optionRows(
            'SELECT `id`, `name` FROM `' . RegistrationDocument::TABLE . '` WHERE `docState` <> 90 ORDER BY `name` ASC, `id` ASC',
        );
        if (count($registrations) > 1) {
            $filters[] = [
                'id'      => 'registration',
                'label'   => $this->columnLabel('registration', 'Registration'),
                'type'    => 'select',
                'options' => array_map(
                    static fn(array $row): array => ['value' => (int) $row['id'], 'label' => (string) $row['name']],
                    $registrations,
                ),
            ];
        }

        $units = $this->optionRows(
            'SELECT `id`, `unit_id`, `name` FROM `' . UnitDocument::TABLE . '` ORDER BY `unit_id` ASC, `id` ASC',
        );
        if (count($units) > 1) {
            $filters[] = [
                'id'      => 'unit',
                'label'   => $this->columnLabel('unit', 'Registration unit'),
                'type'    => 'select',
                'options' => array_map(
                    static fn(array $row): array => [
                        'value' => (int) $row['id'],
                        'label' => trim($row['unit_id'] . ' ' . ($row['name'] ?? '')),
                    ],
                    $units,
                ),
            ];
        }

        $months = $this->optionRows(
            'SELECT `id`, `calendar_year`, `calendar_month` FROM `economy_codebooks_fiscal_months` ORDER BY `date_begin` DESC',
        );
        if ($months !== []) {
            $filters[] = [
                'id'      => 'period',
                'label'   => $this->text('saleFilterPeriod', 'Period'),
                'type'    => 'select',
                'options' => array_map(
                    static fn(array $row): array => [
                        'value' => (int) $row['id'],
                        'label' => $row['calendar_month'] . '/' . $row['calendar_year'],
                    ],
                    $months,
                ),
            ];
        }

        return $filters;
    }

    /** Tržby vznikají z dokladů — bez akce Přidat i Otevřít (formulář nemají). */
    public function getToolbarActions(?array $selectedRow): array
    {
        return [];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT s.*, u.`name` AS `unit_name`, r.`name` AS `registration_name`'
            . ' FROM `' . $this->table . '` s'
            . ' LEFT JOIN `' . UnitDocument::TABLE . '` u ON u.`id` = s.`unit`'
            . ' LEFT JOIN `' . RegistrationDocument::TABLE . '` r ON r.`id` = s.`registration`'
            . ' WHERE s.`id` = %i',
            $recordId,
        );
    }

    protected function detailHeader(array $record): DetailHeader
    {
        return new DetailHeader((string) ($record['serial_number'] ?? ''), $this->amount($record));
    }

    protected function detailBadges(array $record): array
    {
        $state = (string) ($record['state'] ?? '');
        $badges = [['label' => $this->stateLabel($state), 'style' => $this->stateStyle($state)]];
        if (!empty($record['test'])) {
            $badges[] = ['label' => $this->text('saleTestBadge', 'Test confirmation'), 'style' => 'edit'];
        }
        return $badges;
    }

    protected function detailTabs(array $record): array
    {
        $sale = [];
        $this->addItem($sale, $this->columnLabel('sold_at', 'Sale time'), $this->formatDateTime($record['sold_at'] ?? null));
        $this->addItem($sale, $this->columnLabel('unit', 'Registration unit'), $this->unitLabel($record));
        $this->addItem($sale, $this->columnLabel('cash_register_id', 'Cash register'), trim((string) ($record['cash_register_id'] ?? '')));
        $this->addItem($sale, $this->columnLabel('registration', 'Registration'), trim((string) ($record['registration_name'] ?? '')));
        $this->addItem($sale, $this->columnLabel('doc_sequence', 'Sale sequence'), (string) (int) ($record['doc_sequence'] ?? 0));
        $this->addItem($sale, $this->columnLabel('created', 'Created'), $this->formatDateTime($record['created'] ?? null));

        $sending = [];
        $this->addItem($sending, $this->columnLabel('attempt_count', 'Attempts'), (string) (int) ($record['attempt_count'] ?? 0));
        $this->addItem($sending, $this->columnLabel('next_attempt', 'Next attempt'), $this->formatDateTime($record['next_attempt'] ?? null));
        $this->addItem($sending, $this->columnLabel('confirmed_at', 'Confirmed at'), $this->formatDateTime($record['confirmed_at'] ?? null));
        $this->addItem($sending, $this->columnLabel('confirmation_code', 'Confirmation code'), trim((string) ($record['confirmation_code'] ?? '')));
        $this->addItem($sending, $this->columnLabel('environment', 'Environment'), $this->environmentLabel((string) ($record['environment'] ?? '')));
        $this->addItem($sending, $this->columnLabel('last_problem', 'Last problem'), $this->problemLabel((string) ($record['last_problem'] ?? '')));
        // U zprávy zastavené pojistkou je „chyba“ jen interní kód důvodu.
        if (($record['last_problem'] ?? null) !== SendResult::PROBLEM_SAFETY) {
            $this->addItem($sending, $this->columnLabel('last_error', 'Last error'), trim((string) ($record['last_error'] ?? '')));
        }

        return [
            $this->propertiesTab([
                ['title' => $this->text('saleGroupSale', 'Sale'), 'items' => $sale],
                ['title' => $this->text('saleGroupSending', 'Sending'), 'items' => $sending],
            ]),
            $this->attemptsTab((int) $record['id']),
        ];
    }

    protected function detailActions(array $record): array
    {
        $docId = (int) ($record['doc_head'] ?? 0);
        if ($docId <= 0) {
            return [];
        }
        return [[
            'id'      => 'openSaleDocument',
            'label'   => $this->text('saleOpenDocument', 'Open document'),
            'kind'    => 'open_detail',
            'variant' => 'secondary',
            'target'  => ['viewerId' => self::DOCUMENTS_VIEWER, 'recordId' => $docId],
        ]];
    }

    /**
     * Záložka Pokusy — jedna datová zpráva = jeden řádek, nejnovější první.
     *
     * @return array{id: string, label: string, content: array<string, mixed>}
     */
    private function attemptsTab(int $saleId): array
    {
        $label = $this->detailTabLabel(self::LABELS_CFG_ITEM, 'attempts', 'Attempts');
        $attempts = $this->loadAttempts($saleId);
        if ($attempts === []) {
            return [
                'id'      => 'attempts',
                'label'   => $label,
                'content' => ['type' => 'empty', 'text' => $this->text('saleNoAttempts', 'The sale has not been sent yet.')],
            ];
        }

        $rows = [];
        foreach ($attempts as $attempt) {
            $status = (string) ($attempt['status'] ?? '');
            $result = [$this->attemptStatusLabel($status)];
            if (!empty($attempt['verification'])) {
                $result[] = $this->text('attemptVerification', 'verification mode');
            }
            if (empty($attempt['first_sending'])) {
                $result[] = $this->text('attemptRepeated', 'repeated sending');
            }
            $problem = $this->problemLabel((string) ($attempt['problem'] ?? ''));
            $errorCode = $attempt['error_code'] ?? null;
            $detail = array_filter([
                $problem,
                $errorCode !== null ? $this->text('attemptErrorCode', 'code {code}', ['code' => (int) $errorCode]) : null,
                ($attempt['problem'] ?? null) !== SendResult::PROBLEM_SAFETY ? trim((string) ($attempt['error_text'] ?? '')) : null,
                $this->warningsText($attempt['warnings'] ?? null),
            ], static fn(?string $part): bool => $part !== null && $part !== '');

            $row = [
                'started_at'     => $this->formatDateTime($attempt['started_at'] ?? null) ?? '',
                'environment'    => $this->environmentLabel((string) ($attempt['environment'] ?? ''))
                    . (!empty($attempt['test_identity']) ? ' · ' . $this->text('attemptTestIdentity', 'test identity') : ''),
                'status'         => implode(' · ', $result),
                'detail'         => implode(' · ', $detail),
                'transaction_id' => (string) ($attempt['transaction_id'] ?? ''),
                'duration_ms'    => $attempt['duration_ms'] !== null ? (string) (int) $attempt['duration_ms'] : '',
            ];
            if (in_array($status, ['rejected', 'retryLater', 'unknown', SaleQueue::ATTEMPT_INTERRUPTED], true)) {
                $row['_class'] = 'error';
            } elseif ($status === 'dropped') {
                $row['_class'] = 'muted';
            }
            $rows[] = $row;
        }

        return [
            'id'      => 'attempts',
            'label'   => $label,
            'content' => [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'started_at',     'label' => $this->columnLabel('started_at', 'Started at', SaleStore::ATTEMPTS)],
                    ['id' => 'environment',    'label' => $this->columnLabel('environment', 'Environment', SaleStore::ATTEMPTS)],
                    ['id' => 'status',         'label' => $this->columnLabel('status', 'Result', SaleStore::ATTEMPTS)],
                    ['id' => 'detail',         'label' => $this->text('attemptDetail', 'Codes and warnings')],
                    ['id' => 'transaction_id', 'label' => $this->columnLabel('transaction_id', 'Transaction ID', SaleStore::ATTEMPTS)],
                    ['id' => 'duration_ms',    'label' => $this->columnLabel('duration_ms', 'Duration (ms)', SaleStore::ATTEMPTS), 'align' => 'right'],
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Pokusy tržby bez těl zpráv (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadAttempts(int $saleId): array
    {
        return $this->db->fetchAll(
            'SELECT `id`, `started_at`, `first_sending`, `verification`, `environment`, `test_identity`, `status`, `sent`,'
            . ' `problem`, `error_code`, `error_text`, `warnings`, `transaction_id`, `duration_ms`'
            . ' FROM `' . SaleStore::ATTEMPTS . '` WHERE `sale` = %i ORDER BY `started_at` DESC, `id` DESC',
            $saleId,
        );
    }

    /**
     * Řádky pro nabídku filtru (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function optionRows(string $sql): array
    {
        return $this->db->fetchAll($sql);
    }

    /**
     * Rozsah kalendářního měsíce fiskálního období pro filtr času tržby
     * (přepsatelné v testech).
     *
     * @return array{0: string, 1: string}|null [od včetně, do vyjma]
     */
    protected function periodRange(int $fiscalMonthId): ?array
    {
        $month = $this->db->fetchRow(
            'SELECT `date_begin`, `date_end` FROM `economy_codebooks_fiscal_months` WHERE `id` = %i',
            $fiscalMonthId,
        );
        if ($month === null) {
            return null;
        }
        $begin = substr((string) $month['date_begin'], 0, 10);
        $end = (new \DateTimeImmutable(substr((string) $month['date_end'], 0, 10)))->modify('+1 day')->format('Y-m-d');
        return [$begin . ' 00:00:00', $end . ' 00:00:00'];
    }

    /** @return array<string, array<string, mixed>> */
    private function states(): array
    {
        $cfg = $this->config?->cfgItem(self::STATES_CFG);
        return is_array($cfg) ? $cfg : [];
    }

    private function stateLabel(string $state): string
    {
        return (string) ($this->states()[$state]['name'] ?? $state);
    }

    private function stateStyle(string $state): string
    {
        return (string) ($this->states()[$state]['stateStyle'] ?? 'concept');
    }

    private function attemptStatusLabel(string $status): string
    {
        $cfg = $this->config?->cfgItem(self::ATTEMPT_STATUSES_CFG);
        return is_array($cfg) && isset($cfg[$status]['name']) ? (string) $cfg[$status]['name'] : $status;
    }

    private function environmentLabel(string $environment): string
    {
        return match ($environment) {
            SalesRegistrationType::ENV_PRODUCTION => $this->text('envProduction', 'production'),
            SalesRegistrationType::ENV_PLAYGROUND => $this->text('envPlayground', 'playground'),
            default => $environment,
        };
    }

    /** Problém na naší straně slovy; neznámý kód beze změny. */
    private function problemLabel(string $problem): string
    {
        return match ($problem) {
            ''               => '',
            'safety'         => $this->text('problemSafety', 'stopped by the server safety switch'),
            'noCertificate'  => $this->text('problemNoCertificate', 'the registration has no certificate'),
            'key'            => $this->text('problemKey', 'the private key cannot be used'),
            'noTestIdentity' => $this->text('problemNoTestIdentity', 'the test identity is missing'),
            'type'           => $this->text('problemType', 'unknown registration type'),
            'environment'    => $this->text('problemEnvironment', 'the registration type has no such environment'),
            'message'        => $this->text('problemMessage', 'the message is not valid'),
            'registration'   => $this->text('problemRegistration', 'the registration is not confirmed'),
            'internal'       => $this->text('problemInternal', 'internal error'),
            default          => $problem,
        };
    }

    private function warningsText(mixed $warnings): ?string
    {
        if (is_string($warnings)) {
            $warnings = json_decode($warnings, true);
        }
        if (!is_array($warnings) || $warnings === []) {
            return null;
        }
        $parts = [];
        foreach ($warnings as $warning) {
            $parts[] = is_array($warning)
                ? trim(($warning['code'] ?? '') . ' ' . ($warning['text'] ?? $warning['message'] ?? ''))
                : (string) $warning;
        }
        return $this->text('attemptWarnings', 'warnings: {list}', ['list' => implode('; ', array_filter($parts))]);
    }

    /** @param array<string, mixed> $row */
    private function unitLabel(array $row): string
    {
        return trim(((string) ($row['unit_id'] ?? '')) . ' ' . trim((string) ($row['unit_name'] ?? '')));
    }

    /** Částka se znaménkem a měnou — oddělovače podle jazyka požadavku. */
    private function amount(array $row): string
    {
        $czech = ($this->language ?? 'en') === 'cs';
        $amount = number_format(Money::cents($row['amount'] ?? 0) / 100, 2, $czech ? ',' : '.', $czech ? ' ' : ',');
        return trim($amount . ' ' . strtoupper((string) ($row['currency'] ?? '')));
    }

    /** @param array<string, scalar> $params */
    private function text(string $key, string $englishFallback, array $params = []): string
    {
        return $this->detailText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
