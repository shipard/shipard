<?php

namespace e10\base\libs;

use \Shipard\Utils\Utils, \Shipard\Base\Content;


/**
 * class AttachmentsReview
 */
class AttachmentsReview extends Content
{
	var $partner = 0;

	function create()
	{
		$biggestDocs = $this->createBiggestDocs();
		$biggestTables = $this->createBiggestTables();

		$tabs = [
			['title' => ['icon' => 'icon-user', 'text' => 'Tabulky'], 'content' => $biggestTables],
			['title' => ['icon' => 'icon-file-text-o', 'text' => 'Dokumenty'], 'content' => $biggestDocs],
		];

		$this->addContent(['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
	}

	function createBiggestDocs ()
	{
		$pieData = [];
		$data = [];
		$totals = ['fs' => 0];

		// -- top 'ten'
		$q = [];
		array_push($q, 'SELECT tableid, recid, SUM(fileSize) AS fs');
		array_push($q, ' FROM [e10_attachments_files]');
		array_push($q, ' WHERE 1');
		array_push($q, ' GROUP BY 1, 2');
		array_push($q, ' ORDER BY 3 DESC, 1, 2');
		array_push($q, ' LIMIT 100');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$table = $this->app()->table ($r['tableid']);
			$tableName = $table ? $table->tableName() : $r['tableid'];

			$item = [
					'table' => $tableName,
					'doc' => '#'.$r['recid'],
					'fs' => Utils::memf($r['fs']),
			];

			$docLabel = NULL;
			if ($table)
			{
				$info[] = ['p1' => 'Tabulka', 't1' => $table->tableName()];
				$srcDocRecData = $table->loadItem($r['recid']);
				if ($srcDocRecData)
				{
					$ri = $table->getRecordInfo($srcDocRecData);
					$docTitle = $ri['title'] ?? '--- bez názvu ---';
					if ($docTitle === '')
						$docTitle = '--- bez názvu ---';
					$docLabel = [
						[
							'text' => $docTitle,
							'docAction' => 'edit', 'pk' => $r['recid'], 'table' => $r['tableid'], 'class' => 'block'
						]
					];

					$docStates = $table->documentStates ($srcDocRecData);
					if ($docStates)
					{
						$docStateClass = $table->getDocumentStateInfo ($docStates, $srcDocRecData, 'styleClass');
						$item['_options']['cellClasses']['doc'] = 'e10-ds '.$docStateClass;
					}

					$item['doc'] = $docLabel;
				}
			}

			if (!$docLabel )
				$item['_options']['class'] = 'e10-warning1';

			$data[] = $item;
      $sn = $r['tableid'].'_'.$r['recid'];
			$pieData[] = [$sn, $r['fs']];

			$totals['fs'] += $r['fs'];
		}

		// -- content
		$h = ['#' => '#', 'table' => 'Tabulka', 'doc' => 'Dokument', 'fs' => ' Velikost'];

		$totalItem = [
			'table' => 'CELKEM',
			'fs' => Utils::memf($totals['fs']),
			'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
		];
		$data[] = $totalItem;

		$content = [
				['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $data],
				//['pane' => 'e10-pane e10-pane-table', 'type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData]
		];

		return $content;
	}

	function createBiggestTables ()
	{
		$pieData = [];
		$data = [];
		$totals = ['fs' => 0];

		// -- top 'ten'
		$q = [];
		array_push($q, 'SELECT tableid, SUM(fileSize) AS fs');
		array_push($q, ' FROM [e10_attachments_files]');
		array_push($q, ' WHERE 1');
		array_push($q, ' GROUP BY 1');
		array_push($q, ' ORDER BY 2 DESC, 1');
		array_push($q, ' LIMIT 100');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$table = $this->app()->table ($r['tableid']);
			$tableName = $table ? $table->tableName() : $r['tableid'];
			$item = [
					'table' => $tableName,
					'fs' => Utils::memf($r['fs']),
			];

			if (!$table)
				$item['_options']['class'] = 'e10-warning1';


			$data[] = $item;
      $sn = $tableName;
			$pieData[] = [$sn, $r['fs']];

			$totals['fs'] += $r['fs'];
		}

		// -- content
		$h = ['#' => '#', 'table' => 'Tabulka', 'fs' => ' Velikost'];

		$totalItem = [
			'table' => 'CELKEM',
			'fs' => Utils::memf($totals['fs']),
			'_options' => ['class' => 'sumtotal', 'beforeSeparator' => 'separator']
		];
		$data[] = $totalItem;

		$content = [
				['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $data],
				['pane' => 'e10-pane e10-pane-table', 'type' => 'graph', 'graphType' => 'pie', 'graphData' => $pieData]
		];

		return $content;
	}
}
