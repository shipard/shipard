<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Viewer\ViewerRegistry;

class ViewerController
{
	public function meta(string $viewerId, AuthContext $auth, ViewerRegistry $registry, array $tables, DataSourceConnection $db, ?ConfigRuntime $config = null, ?string $language = null): Response
	{
		$def = $registry->get($viewerId);
		if ($def === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$guardErr = TableAccessGuard::guardTable($def->table, $auth, $tables[$def->table] ?? null);
		if ($guardErr !== null) {
			return $guardErr;
		}

		$viewer = $registry->createViewer($viewerId, $db, $config, $language);
		if ($viewer === null) {
			return Response::error('VIEWER_CLASS_NOT_FOUND', "Viewer class for '{$viewerId}' not found", 500);
		}

		// Layouty jsou odvozené: list vždy, grid když viewer deklaruje sloupce.
		// defaultLayout se validuje proti layouts — nepodporovaná hodnota
		// (např. 'grid' na list-only vieweru) padá na 'list'.
		$gridColumns = $viewer->getGridColumns();
		$layouts     = ['list'];
		if ($gridColumns !== null) {
			$layouts[] = 'grid';
		}
		$defaultLayout = $viewer->getDefaultLayout();
		if (!in_array($defaultLayout, $layouts, true)) {
			$defaultLayout = 'list';
		}

		$meta = [
			'id'                 => $def->id,
			'name'               => $def->name,
			'table'              => $def->table,
			'filters'            => $viewer->getFilters(),
			'toolbar'            => $viewer->getToolbarActions(null),
			'viewGroups'         => $viewer->getViewGroups(),
			'defaultViewGroup'   => $viewer->getDefaultViewGroup(),
			'bottomTabs'         => [
				'tabs'    => $viewer->getBottomTabs(),
				'default' => $viewer->getDefaultBottomTab(),
			],
			'newRecordDefaults'  => $viewer->getNewRecordDefaults(),
			'layouts'            => $layouts,
			'defaultLayout'      => $defaultLayout,
		];

		if ($gridColumns !== null) {
			$meta['grid'] = [
				'columns'   => $gridColumns,
				'showIndex' => (bool) ($viewer->getGridOptions()['showIndex'] ?? true),
			];
		}

		return Response::success($meta);
	}

	public function rows(string $viewerId, Request $request, AuthContext $auth, ViewerRegistry $registry, array $tables, DataSourceConnection $db, ?ConfigRuntime $config = null, ?string $language = null): Response
	{
		$def = $registry->get($viewerId);
		if ($def === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$guardErr = TableAccessGuard::guardTable($def->table, $auth, $tables[$def->table] ?? null);
		if ($guardErr !== null) {
			return $guardErr;
		}

		$viewer = $registry->createViewer($viewerId, $db, $config, $language);
		if ($viewer === null) {
			return Response::error('VIEWER_CLASS_NOT_FOUND', "Viewer class for '{$viewerId}' not found", 500);
		}

		$params = $request->getQueryParams();
		$search = isset($params['search']) && is_string($params['search']) ? $params['search'] : null;
		$page   = max(0, (int) ($params['page'] ?? 0));
		$layout = isset($params['layout']) && is_string($params['layout']) ? $params['layout'] : 'list';

		// Guard — meta-driven frontend layout=grid na list-only viewer nikdy
		// nepošle, ručně sestavený request dostane jasnou chybu.
		if ($layout === 'grid' && $viewer->getGridColumns() === null) {
			return Response::error('LAYOUT_NOT_SUPPORTED', "Viewer '{$viewerId}' does not support the grid layout", 400);
		}

		// Sort (jen grid layout): `sort=<colId>:<asc|desc>`, colId musí být
		// sortable sloupec gridu. Nevalidní hodnota se tiše ignoruje — padá
		// na výchozí řazení vieweru, žádná chyba (D9).
		if ($layout === 'grid') {
			$sortParam = $params['sort'] ?? null;
			if (is_string($sortParam) && $sortParam !== '') {
				[$sortCol, $sortDir] = array_pad(explode(':', $sortParam, 2), 2, '');
				$sortable = array_column(
					array_filter($viewer->getGridColumns(), static fn (array $c): bool => ($c['sortable'] ?? false) === true),
					'id',
				);
				if (in_array($sortDir, ['asc', 'desc'], true) && in_array($sortCol, $sortable, true)) {
					$viewer->setSort(['column' => $sortCol, 'dir' => $sortDir]);
				}
			}
		}

		$filters = [];
		if (isset($params['filter']) && is_array($params['filter'])) {
			foreach ($params['filter'] as $filterId => $value) {
				$filters[] = ['id' => $filterId, 'value' => $value];
			}
		}

		$rawRows  = $viewer->selectRows($search, $filters, $page);
		$pageSize = $viewer->getPageSize();
		$hasMore  = count($rawRows) > $pageSize;

		if ($hasMore) {
			$rawRows = array_slice($rawRows, 0, $pageSize);
		}

		$rows = [];
		if ($layout === 'grid') {
			// Grid řádky ikonu nemají — default icon se nedoplňuje.
			foreach ($rawRows as $row) {
				$rows[] = $viewer->renderGridRow($row);
			}
		} else {
			$defaultIcon = $def->icon;
			foreach ($rawRows as $row) {
				$rendered = $viewer->renderRow($row);
				if (!isset($rendered['icon']) && $defaultIcon !== null) {
					$rendered['icon'] = $defaultIcon;
				}
				$rows[] = $rendered;
			}
		}

		$result = [
			'rows'    => $rows,
			'hasMore' => $hasMore,
		];

		// Součtový footer jen na první stránce — frontend si ho drží
		// z page 0, další stránky klíč neposílají (D7).
		if ($layout === 'grid' && $page === 0) {
			$footer = $viewer->renderGridFooter($search, $filters);
			if ($footer !== null) {
				$result['footer'] = $footer;
			}
		}

		return Response::success($result);
	}

	public function detail(
		string $viewerId,
		int $recordId,
		AuthContext $auth,
		ViewerRegistry $registry,
		array $tables,
		DataSourceConnection $db,
		?ConfigRuntime $config = null,
		?string $language = null,
		?DocumentRegistry $documents = null,
		?DataSourceConfig $dsConfig = null,
		?PrintRegistry $prints = null,
	): Response {
		$def = $registry->get($viewerId);
		if ($def === null) {
			return Response::error('VIEWER_NOT_FOUND', "Viewer '{$viewerId}' not found", 404);
		}

		$guardErr = TableAccessGuard::guardTable($def->table, $auth, $tables[$def->table] ?? null);
		if ($guardErr !== null) {
			return $guardErr;
		}

		$viewer = $registry->createViewer($viewerId, $db, $config, $language);
		if ($viewer === null) {
			return Response::error('VIEWER_CLASS_NOT_FOUND', "Viewer class for '{$viewerId}' not found", 500);
		}

		$record = $db->fetchRow('SELECT * FROM `' . $def->table . '` WHERE `id` = %i', $recordId);
		if ($record === null) {
			return Response::error('RECORD_NOT_FOUND', "Record {$recordId} not found", 404);
		}

		$toolbar = $viewer->getToolbarActions($record);
		$detail  = $viewer->renderDetail($recordId);

		// Zámek záznamu (documentLockProviders, #55 D24): banner v detailu
		// a bez toolbar akce Otevřít — formulář by byl jen read-only.
		if ($documents !== null && $documents->hasLockProviders($def->table)) {
			$lock = DocumentLockRegistry::forDocuments($documents, $db->getDibiConnection(), $config, $dsConfig)
				->describe($def->table, $record);
			$detail['lock'] = $lock;
			if ($lock['locked']) {
				$toolbar = array_values(array_filter(
					$toolbar,
					static fn(array $a): bool => ($a['id'] ?? '') !== 'edit',
				));
			}
		}

		// Tisk (#90 D19): generický háček nad registrem tisků — viewer o něm
		// neví, takže tisky dalších tabulek fungují bez zásahu do něj.
		$printAction = self::printAction($prints?->forRecord($def->table, $record) ?? [], $config);
		if ($printAction !== null) {
			$detail['actions'] = [...($detail['actions'] ?? []), $printAction];
		}

		return Response::success([
			'toolbar' => $toolbar,
			'detail'  => $detail,
		]);
	}

	/**
	 * Akce Tisk pro `detail.actions`: jeden dostupný tisk = tlačítko
	 * s `target.printId`, víc tisků = dropdown (`value` položky = id tisku).
	 * Popisek z `core.system.viewerDefaults.detailActions.print`.
	 *
	 * @param PrintDefinition[] $definitions Tisky dostupné pro záznam, už seřazené.
	 * @return array<string, mixed>|null
	 */
	private static function printAction(array $definitions, ?ConfigRuntime $config): ?array
	{
		if ($definitions === []) {
			return null;
		}

		$def    = ($config?->cfgItem('core.system.viewerDefaults') ?? [])['detailActions']['print'] ?? [];
		$action = [
			'id'      => 'print',
			'label'   => $def['name'] ?? 'Print',
			'variant' => $def['variant'] ?? 'secondary',
		];

		if (count($definitions) === 1) {
			return $action + ['kind' => 'button', 'target' => ['printId' => $definitions[0]->id]];
		}

		return $action + [
			'kind'  => 'dropdown',
			'items' => array_map(
				static fn (PrintDefinition $d): array => ['label' => $d->name, 'value' => $d->id],
				$definitions,
			),
		];
	}
}
