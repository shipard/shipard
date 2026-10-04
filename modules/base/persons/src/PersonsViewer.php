<?php
declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Base\Persons\Send\SendPurposes;

class PersonsViewer extends TableViewer
{
	protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

	/**
	 * True when the active tab is "Vse" (viewGroup 'all'). Set in selectRows(),
	 * read in renderRow() so archived/deleted records always get a state badge
	 * in the combined list. Outside the 'all' tab each tab is self-evident, so
	 * the badge stays demoted to non-default states only.
	 */
	private bool $showAllStates = false;

	/** Maps stateStyle to a span class for the state badge in t2. */
	private const STATE_SPAN_CLASS = [
		'concept'   => 'warning',
		'confirmed' => 'primary',
		'done'      => 'success',
		'edit'      => 'warning',
		'archive'   => 'muted',
		'trash'     => 'muted',
		'cancelled' => 'danger',
	];

	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		$sql = 'SELECT `id`, `person_id`, `person_type`, `full_name`, `company_id`, `tax_id`,'
			. ' `email`, `phone`, `is_own`, `docState`, `docStateMain`'
			. ' FROM `' . $this->table . '`';

		$conditions = [];
		$params     = [];

		// viewGroup filter drives which doc-state tab is active.
		// Default: 'active' — show Koncept, V opravě and V pořádku.
		$viewGroup = 'active';
		foreach ($filters as $filter) {
			if ($filter['id'] === 'viewGroup') {
				$viewGroup = (string) $filter['value'];
			}
		}

		$this->showAllStates = ($viewGroup === 'all');

		if ($viewGroup !== 'all') {
			[$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
			if ($vgSql !== '') {
				$conditions[] = $vgSql;
				$params       = array_merge($params, $vgParams);
			}
		}

		// Fulltext search across key columns
		if ($search !== null && $search !== '') {
			[$searchSql, $searchParams] = $this->buildSearchCondition(
				['full_name', 'company_id', 'email', 'person_id'],
				$search,
			);
			if ($searchSql !== '') {
				$conditions[] = $searchSql;
				$params       = array_merge($params, $searchParams);
			}
		}

		if ($conditions !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		// Razeni: v zalozce "Vse" cistou abecedou (stav ignorujeme, badge je
		// v renderRow), jinak docStateMain ASC (Koncepty nahore, pak V oprave,
		// pak V poradku) a uvnitr skupiny abecedne.
		if ($this->showAllStates) {
			$sql .= ' ORDER BY `last_name` ASC, `first_name` ASC, `id` ASC';
		} else {
			$sql .= ' ORDER BY `docStateMain` ASC, `last_name` ASC, `first_name` ASC, `id` ASC';
		}

		[$offset, $limit] = $this->buildPaginationLimit($pageNumber);
		$sql .= ' LIMIT ' . $offset . ', ' . $limit;

		return $this->db->fetchAll($sql, ...$params);
	}

	public function renderRow(array $rowData): array
	{
		$row = [
			'id' => (int) $rowData['id'],
			't1' => $rowData['full_name'] ?? '',
			'i1' => $rowData['person_id'] ?? null,
		];

		// Ikona řádku: fyzická osoba (1) / neurčeno (0) → user,
		// právnická osoba (2) → company (building).
		$personType = (int) ($rowData['person_type'] ?? 0);
		$row['icon'] = $personType === 2 ? 'company' : 'user';

		// Line 2: badge "Vlastní" for is_own, then IČO/DIČ, plus state badge for non-default states
		$t2 = [];
		if (!empty($rowData['is_own'])) {
			$t2[] = ['text' => 'Vlastní', 'class' => 'primary'];
		}
		if (!empty($rowData['company_id'])) {
			$t2[] = ['text' => 'IČO: ' . $rowData['company_id']];
		}
		if (!empty($rowData['tax_id'])) {
			$t2[] = ['text' => 'DIČ: ' . $rowData['tax_id']];
		}

		$docState  = (int) ($rowData['docState'] ?? 10);
		$cfg       = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
		$stateData = $cfg->getState($docState);
		$stateStyle = $stateData['stateStyle'] ?? 'concept';

		// Badge non-default states so the user sees them at a glance.
		// V zalozce "Vse" ukaz badge vzdy (i pro Koncept), aby archiv/kos
		// nesplynuly s aktivnimi zaznamy; jinak zustava demoted na ne-vychozi.
		if ($this->showAllStates || $docState !== 10) {
			$t2[] = [
				'text'  => $stateData['stateName'] ?? '',
				'class' => self::STATE_SPAN_CLASS[$stateStyle] ?? 'muted',
			];
		}

		$row['t2'] = $t2 !== [] ? $t2 : null;

		// Line 3: e-mail or phone
		$row['t3'] = !empty($rowData['email'])
			? $rowData['email']
			: (!empty($rowData['phone']) ? $rowData['phone'] : null);

		// Row-level state style for CSS coloring in the viewer list
		$row['stateStyle'] = $stateStyle;

		return $row;
	}

	/**
	 * Detail osoby — hlavička nad taby (jméno, identifikátory, badges, ikona
	 * dle typu osoby; stejný vzor jako IncomingMessagesViewer) a jediný tab
	 * Přehled s composite obsahem: vlastnosti záznamu + kontakty + adresy.
	 *
	 * Identifikátory zobrazené v hlavičce (IČO, DIČ, datum narození, kód
	 * osoby) se v Přehledu už neopakují — zrcadlí PersonsForm::buildHeaderInfo.
	 */
	public function renderDetail(int $recordId): array
	{
		$record = $this->db->fetchRow(
			'SELECT * FROM `' . $this->table . '` WHERE `id` = %i',
			$recordId,
		);

		if ($record === null) {
			return ['tabs' => []];
		}

		$header = $this->buildDetailHeader($record);

		return [
			'title'    => $header['title'],
			'subtitle' => $header['subtitle'],
			'badges'   => $header['badges'],
			'icon'     => $header['icon'],
			'tabs'     => [[
				'id'      => 'overview',
				'label'   => $this->defaultOverviewLabel(),
				'content' => $this->buildOverviewContent($record),
			]],
		];
	}

	public function getToolbarActions(?array $selectedRow): array
	{
		// Base gives localized create/edit; append the registry-import action
		// only on the unselected (list) toolbar — picking it on a selected row
		// would be confusing UX.
		$actions = parent::getToolbarActions($selectedRow);

		if ($selectedRow !== null) {
			return $actions;
		}

		$personDefs = ($this->config?->cfgItem('base.persons.viewerDefaults') ?? [])['toolbarActions'] ?? [];
		$def = $personDefs['import_from_registry'] ?? ['name' => 'From registry', 'variant' => 'secondary'];

		$actions[] = [
			'id'      => 'import_from_registry',
			'label'   => $def['name']    ?? 'From registry',
			'variant' => $def['variant'] ?? 'secondary',
			'icon'    => 'cloud-download',
		];

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Private — detail header
	// -------------------------------------------------------------------------

	/**
	 * Hlavička detailu nad taby — full_name jako title, identifikátory jako
	 * subtitle (firma: IČO · DIČ · kód osoby; fyzická osoba: datum narození ·
	 * kód osoby), badges se stavem a příznakem Vlastní. Typ osoby vyjadřuje
	 * jen ikona (company/user) — stejné mapování jako renderRow().
	 *
	 * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>, icon: string}
	 */
	private function buildDetailHeader(array $record): array
	{
		$personType = (int) ($record['person_type'] ?? 0);

		$subtitleParts = [];
		if ($personType === 2) {
			if (!empty($record['company_id'])) {
				$subtitleParts[] = 'IČO ' . $record['company_id'];
			}
			if (!empty($record['tax_id'])) {
				$subtitleParts[] = 'DIČ ' . $record['tax_id'];
			}
		} else {
			$birthDate = $this->formatDate($record['birth_date'] ?? null);
			if ($birthDate !== null) {
				$subtitleParts[] = 'Datum narození ' . $birthDate;
			}
		}
		if (!empty($record['person_id'])) {
			$subtitleParts[] = 'Kód osoby ' . $record['person_id'];
		}

		$badges = [];
		$stateBadge = $this->buildStateBadge((int) ($record['docState'] ?? 10));
		if ($stateBadge !== null) {
			$badges[] = $stateBadge;
		}
		if (!empty($record['is_own'])) {
			$badges[] = ['label' => 'Vlastní', 'style' => 'primary'];
		}

		$fullName = trim((string) ($record['full_name'] ?? ''));

		return [
			'title'    => $fullName !== '' ? $fullName : '(bez názvu)',
			'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
			'badges'   => $badges,
			'icon'     => $personType === 2 ? 'company' : 'user',
		];
	}

	/** Badge stavu záznamu (label + stateStyle) z docState configu. */
	private function buildStateBadge(int $docState): ?array
	{
		if ($this->config === null || $this->docStatesCfgItem === null) {
			return null;
		}

		$cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
		$stateData = $cfg->getState($docState);
		$label = (string) ($stateData['stateName'] ?? '');
		if ($label === '') {
			return null;
		}

		return [
			'label' => $label,
			'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
		];
	}

	// -------------------------------------------------------------------------
	// Private — overview tab
	// -------------------------------------------------------------------------

	/**
	 * Composite obsah Přehledu: vlastnosti (Identifikace / Kontakt / Osobní
	 * údaje), kontakty a adresy jako properties řádky. Prázdné sekce se
	 * vynechávají.
	 */
	private function buildOverviewContent(array $record): array
	{
		$blocks = [];

		// IČO, DIČ, kód osoby a datum narození jsou v hlavičce detailu —
		// tady zůstávají méně časté identifikátory.
		$identityItems = [];
		$this->addItem($identityItems, 'DIČ pro DPH', $record['vat_id'] ?? null);
		$this->addItem($identityItems, 'Zápis v obchodním rejstříku', $record['court_registration'] ?? null);
		$this->addItem($identityItems, 'ID datové schránky', $record['gov_e_box_id'] ?? null);

		$contactItems = [];
		$this->addItem($contactItems, 'E-mail', $record['email'] ?? null);
		$this->addItem($contactItems, 'Telefon', $record['phone'] ?? null);
		$this->addItem($contactItems, 'Web', $record['web'] ?? null);

		$personalItems = [];
		$this->addItem($personalItems, 'Rodné číslo', $record['national_id'] ?? null);
		$this->addItem($personalItems, 'Číslo osobního dokladu', $record['id_card_number'] ?? null);

		$groups = [];
		if ($identityItems !== []) {
			$groups[] = ['title' => 'Identifikace', 'items' => $identityItems];
		}
		if ($contactItems !== []) {
			$groups[] = ['title' => 'Kontakt', 'items' => $contactItems];
		}
		if ($personalItems !== []) {
			$groups[] = ['title' => 'Osobní údaje', 'items' => $personalItems];
		}
		if ($groups !== []) {
			$blocks[] = ['type' => 'properties', 'groups' => $groups];
		}

		// Kontakty — properties řádky (label = jméno, value = funkce · e-mail · telefon)
		$contactRowItems = $this->buildContactItems((int) $record['id']);
		if ($contactRowItems !== []) {
			$blocks[] = [
				'type'   => 'properties',
				'groups' => [[
					'title' => $this->detailTabLabel('base.persons.viewerDetailLabels', 'contacts', 'Contacts'),
					'items' => $contactRowItems,
				]],
			];
		}

		// Adresy — properties řádky (label = název adresy / typ, value = display_line)
		$addressItems = $this->buildAddressItems((int) $record['id']);
		if ($addressItems !== []) {
			$blocks[] = [
				'type'   => 'properties',
				'groups' => [[
					'title' => $this->detailTabLabel('base.persons.viewerDetailLabels', 'addresses', 'Addresses'),
					'items' => $addressItems,
				]],
			];
		}

		if ($blocks === []) {
			return ['type' => 'html', 'html' => '<p class="muted">Záznam nemá žádné další údaje.</p>'];
		}
		if (count($blocks) === 1) {
			return $blocks[0];
		}

		return ['type' => 'composite', 'blocks' => $blocks];
	}

	/**
	 * Kontakty osoby jako properties položky. Label je jméno kontaktu,
	 * value spojuje funkci, e-mail, telefon a účely odesílání přes „·“;
	 * bez detailů „—“.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function buildContactItems(int $personId): array
	{
		$contacts = $this->db->fetchAll(
			'SELECT * FROM `base_persons_contacts` WHERE `person` = %i ORDER BY `order_pos`',
			$personId,
		);

		$items = [];
		foreach ($contacts as $c) {
			$label = trim((string) ($c['name'] ?? ''));
			if ($label === '') {
				$label = 'Kontakt';
			}

			$parts = [];
			foreach (['role', 'email', 'phone'] as $col) {
				$v = trim((string) ($c[$col] ?? ''));
				if ($v !== '') {
					$parts[] = $v;
				}
			}

			// Účely odesílání (#90 D34) — na co se kontakt při odesílání použije.
			$purposes = SendPurposes::labels(SendPurposes::decode($c['send_purposes'] ?? null) ?? [], $this->config);
			if ($purposes !== []) {
				$parts[] = implode(', ', $purposes);
			}

			$items[] = ['label' => $label, 'value' => $parts !== [] ? implode(' · ', $parts) : '—'];
		}

		return $items;
	}

	/**
	 * Adresy osoby jako properties položky. Label je název adresy; pokud
	 * chybí, použije se lokalizovaný typ adresy z cfgItem addressTypes.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function buildAddressItems(int $personId): array
	{
		$addresses = $this->db->fetchAll(
			'SELECT `name`, `address_type`, `display_line` FROM `base_persons_addresses` WHERE `person` = %i ORDER BY `order_pos`',
			$personId,
		);
		if ($addresses === []) {
			return [];
		}

		$typeLabels = [];
		$cfgTypes = $this->config?->cfgItem('base.persons.addressTypes');
		if (is_array($cfgTypes)) {
			foreach ($cfgTypes as $key => $entry) {
				$typeLabels[(int) $key] = (string) ($entry['name'] ?? $key);
			}
		}

		$items = [];
		foreach ($addresses as $a) {
			$value = trim((string) ($a['display_line'] ?? ''));
			if ($value === '') {
				continue;
			}
			$label = trim((string) ($a['name'] ?? ''));
			if ($label === '') {
				$label = $typeLabels[(int) ($a['address_type'] ?? 0)] ?? 'Adresa';
			}
			$items[] = ['label' => $label, 'value' => $value];
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Private — formátovací helpery
	// -------------------------------------------------------------------------

	/** DB datum (Y-m-d) → d.m.Y; neplatné/prázdné → null. */
	private function formatDate(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
		return $dt instanceof \DateTimeImmutable ? $dt->format('d.m.Y') : null;
	}

	/** @param array<int, array{label: string, value: string}> $items */
	private function addItem(array &$items, string $label, mixed $value): void
	{
		if ($value !== null && $value !== '') {
			$items[] = ['label' => $label, 'value' => (string) $value];
		}
	}
}
