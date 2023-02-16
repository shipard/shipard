<?php

namespace E10Doc\Base;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableCashBoxes
 * @package E10Doc\Base
 */
class TableCashBoxes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.cashboxes', 'e10doc_base_cashboxes', 'Pokladny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$cashBoxes = array ();
		$rows = $this->app()->db->query ('SELECT * from [e10doc_base_cashboxes] WHERE [docState] != 9800 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$cb = [
				'ndx' => $r['ndx'], 'id' => $r['id'], 'curr' => $r['currency'],
				'debsAccountId' => isset ($r['debsAccountId']) ? $r['debsAccountId'] : '',
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'warehouseCashreg' => $r ['warehouseCashreg'], 'warehousePurchase' => $r ['warehousePurchase'],
				'efd' => $r['exclFromDashboard']
			];

			// -- payment terminals
			$ptRows = $this->app()->db->query (
				'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
				' WHERE doclinks.linkId = %s', 'cashboxes-payTerminals',
				' AND doclinks.srcRecId = %i', $r['ndx']
			);
			foreach ($ptRows as $pt)
			{
				$cb['pt'][] = $pt['dstRecId'];
			}
			$cashBoxes [$r['ndx']] = $cb;
		}

		// save to file
		$cfg ['e10doc']['cashBoxes'] = $cashBoxes;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.cashBoxes.json', utils::json_lint (json_encode ($cfg)));
	}
} // class TableCashBoxes


/**
 * Class ViewCashBoxes
 * @package E10Doc\Base
 */
class ViewCashBoxes extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];

		$props = [];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'pull-right label label-default'];

		$listItem ['t2'] = $props;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_cashboxes]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%', ' OR [shortName] LIKE %s', '%'.$fts.'%',
				' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[id]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCashBoxes
 * @package E10Doc\Base
 */
class FormCashBoxes extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('currency');
			$this->addColumnInput ('debsAccountId');
			$this->addColumnInput ('order');
			$this->addColumnInput ('exclFromDashboard');

			if ($this->app()->model()->table ('e10doc.inventory.journal') !== FALSE)
				$this->addColumnInput ('warehouseCashreg');
			if ($this->app()->model()->module ('e10doc.purchase') !== FALSE)
				$this->addColumnInput ('warehousePurchase');

			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}
}

