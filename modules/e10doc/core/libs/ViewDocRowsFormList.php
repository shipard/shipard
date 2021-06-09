<?php

namespace e10doc\core\libs;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils;


/**
 * Class ViewDocRowsFormList
 * @package e10doc\core\libs
 */
class ViewDocRowsFormList extends \e10\TableViewGrid
{
	var $documentNdx = 0;

	var $units;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$this->documentNdx = intval($this->queryParam('document'));
		$this->addAddParam('document', $this->documentNdx);

		$this->units = $this->app()->cfgItem ('e10.witems.units');

		$g = [
			//'#' => '#',
			'item' => 'Položka',
			'quantity' => ' Množství',
			'price' => ' Cena',
			'text' => 'Text',
		];
		$this->setGrid ($g);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [rows].*,';
		array_push ($q, ' items.fullName AS itemName');
		array_push ($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push ($q, ' LEFT JOIN [e10_witems_items] AS items ON [rows].item = items.ndx');

		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [rows].[document] = %i', $this->documentNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q,' AND (');
			array_push ($q,' items.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [rows].[rowOrder] ');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = 'system/iconUser';

		$listItem ['item'] = [
			['text' => $item['itemName'], 'class' => 'e10-bold'],
			['text' => $item['text'], 'class' => 'break e10-small']
		];

		$unit = $this->units[$item['unit']];
		$listItem ['quantity'] = [
			['text' => utils::nf($item['quantity'], 2), 'prefix' => $unit['shortcut'], 'class' => 'e10-bold'],
			//['text' => $unit['shortcut'], 'class' => 'break e10-small']
		];

		$listItem ['price'] = [
			['text' => utils::nf($item['priceAll'], 2), 'class' => 'e10-bold'],
			['text' => '', 'class' => 'block'],
			['text' => utils::nf($item['priceItem'], 2), 'class' => ''],
		];

		return $listItem;
	}
}
