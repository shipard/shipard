<?php

namespace E10Doc\Base;

//require_once __DIR__ . '/../../base/base.php';


use \E10\Application, \E10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;


/**
 * Class TableWarehouses
 * @package E10Doc\Base
 */
class TableWarehouses extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.warehouses', 'e10doc_base_warehouses', 'Sklady');
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
		$warehouses = array ();
		$rows = $this->app()->db->query ('SELECT * FROM [e10doc_base_warehouses] WHERE [docState] != 9800 ORDER BY [order], [id]');

		foreach ($rows as $r)
		{
			$warehouses [$r ['ndx']] = [
				'ndx' => $r ['ndx'], 'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'ownerOffice' => $r ['ownerOffice'],
				'useTransportOnDocs' => $r ['useTransportOnDocs'],
			];
		}
		// save to file
		$cfg ['e10doc']['warehouses'] = $warehouses;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.warehouses.json', utils::json_lint (json_encode ($cfg)));
	}
} // class TableWarehouses


/**
 * Class ViewWarehouses
 * @package E10Doc\Base
 */
class ViewWarehouses extends TableView
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
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_warehouses]';
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
} // class ViewWarehouses


/**
 * Class FormWarehouses
 * @package E10Doc\Base
 */
class FormWarehouses extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('shortName');
				$this->addColumnInput ('id');
				$this->addColumnInput ('order');
				$this->addSeparator(self::coH4);
				$this->addColumnInput ('ownerOffice');
				$this->addSeparator(self::coH4);
				$this->addColumnInput ('street');
				$this->addColumnInput ('city');
				$this->addColumnInput ('zipcode');
				$this->addColumnInput ('country');
				$this->addSeparator(self::coH4);
				$this->addColumnInput ('useTransportOnDocs');
			$this->closeTab ();
				$this->openTab ();
					$this->addList ('rows');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.base.warehouses' && $srcColumnId === 'ownerOffice')
		{
			$cp = [
				'personNdx' => strval(intval($this->app()->cfgItem ('options.core.ownerPerson', 0)))
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}

