<?php

namespace e10mnf\core\libs;

use \Shipard\Viewer\TableView, \Shipard\Utils\Utils;


class ComboPlanWorkOrdersRowsViewer extends TableView
{
	var $workOrderNdx = 0;

	public function init ()
	{
		$this->htmlRowsElement = 'div';
		$this->htmlRowElement = 'div';

		parent::init();

		if (isset($this->queryParams['workOrderNdx']))
			$this->workOrderNdx = $this->queryParams['workOrderNdx'] ?? 0;

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = FALSE;
		$this->objectSubType = TableView::vsMini;
	}

	public function zeroRowCode ()
	{
		$c = '';

		$c .= "<div class='e10-tvw-item' id='{$this->vid}Footerdddd'>";
		$c .= $this->addBtnCode ([
			[
				'title' => 'Galerie všech obrázků', 'icons' => ['system/iconImage'], 'code' => '{{articleImage}}', 'text' => 'Vše'
			],
			[
				'title' => 'Galerie vybraných obrázků', 'icons' => ['system/iconCheck', 'system/iconImage'],
				'code' => '{{articleImage;', 'function' => 'webTextArticleImageSelected', 'text' => 'Vybrané'
			]
		]);
		$c .= '</div>';

		return $c;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch();

		$q = [];
		array_push ($q, 'SELECT [rows].*');
		array_push ($q, 'FROM [e10mnf_core_workOrdersRows] AS [rows] ');
		array_push($q, ' WHERE 1');

		array_push($q, ' AND [rows].[workOrder] = %i', $this->workOrderNdx);

		if ($fts !== '')
		{
//			array_push($q, ' AND att.[name] LIKE %s', '%'.$fts.'%');
		}

		array_push ($q, ' ORDER BY [rows].[ndx]');

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function rowHtml ($listItem)
	{
		$c = '';

		$class = '';

		$c .= "<div class='e10-pane e10-pane-mini {$class}' style='margin: .5ex .5ex;' data-pk='{$listItem['ndx']}'>";
		$c .= "<table class'fullWidth' style='padding: 3px;'>";

		$c .= '<tr>';
			$c .= "<td class='padd5' style='vertical-align: top;'>";
				$c .= "<input type='checkbox' name='vchbx_{$listItem['ndx']}' value='{$listItem ['ndx']}'/> ";
				$c .= "<span class='e10-bold'>{$listItem ['text']}</span>";
			$c .= '</td>';
		$c .= '</tr>';

		$c .= "<tr>";
			$c .= "<td class='padd5'>";
				$c .= utils::es('Pozice: ');
				$c .= utils::es($listItem ['refId3']);
			$c .= '</td>';
		$c .= '</tr>';

		$c .= '</table>';
		$c .= '</div>';
		return $c;
	}

	function addBtnCode ($buttons)
	{
		$c = '';

		foreach ($buttons as $b)
		{
			$c .= " <button class='btn btn-default btn-sm e10-sidebar-setval' title=\"" . utils::es($b['title']) . "\"";

			if (isset($b['code']))
				$c .= " data-b64-value='" . base64_encode($b['code']) . "'";
			if (isset($b['function']))
				$c .= " data-function-value='" . $b['function'] . "'";

			$c .= '>';

			foreach ($b['icons'] as $icon)
			{
				$c .= $this->app()->ui()->icons()->icon($icon).'&nbsp;';
			}
			if (isset($b['text']))
				$c .= utils::es($b['text']);

			$c .= '</button>';
		}
		return $c;
	}
}
