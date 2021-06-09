<?php

namespace e10pro\canteen;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableCanteensAddFoods
 * @package e10pro\canteen
 */
class TableCanteensAddFoods extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.canteensAddFoods', 'e10pro_canteen_canteensAddFoods', 'Doplňková jídla');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewCanteenAddFoods
 * @package e10pro\canteen
 */
class ViewCanteenAddFoods extends TableViewGrid
{
	var $canteenNdx = 0;
	var $additionalFoodTypes;
	var $classification = [];

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'type' => 'Kdy',
			'name' => 'Název',
			'optional' => 'Volitelné',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->canteenNdx = intval($this->queryParam('canteen'));
		$this->addAddParam ('canteen', $this->canteenNdx);

		$this->additionalFoodTypes = $this->app()->cfgItem('e10pro.canteen.additionalFoodTypes');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$listItem ['action'] = [];
		$listItem ['name'] = $item['fullName'];
		$listItem ['type'] = $this->additionalFoodTypes[$item['addFoodType']]['name'];

		if ($item['addFoodOptional'])
			$listItem ['optional'] = 'Ano';

		$listItem ['note'] = [];

		$ft = utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);
		if ($ft !== '')
			$listItem['note'][] = ['text' => $ft, 'class' => 'label label-default'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [addFoods].*';
		array_push ($q, ' FROM [e10pro_canteen_canteensAddFoods] AS [addFoods]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND addFoods.[canteen] = %i', $this->canteenNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [addFoods].[rowOrder], [addFoods].[addFoodOrder], [addFoods].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$q[] = 'SELECT [links].*,';
		array_push($q, ' clsfItems.[group] AS [group]');
		array_push($q, ' FROM [e10_base_doclinks] AS [links]');
		array_push($q, ' LEFT JOIN e10_base_clsfitems AS clsfItems ON [links].dstRecId = clsfItems.ndx');
		array_push($q, ' WHERE [srcTableId] = %s', 'e10pro.canteen.canteensAddFoods', ' AND [srcRecId] IN %in', $this->pks);

		$class = 'label';
		$clsfItems = $this->app()->cfgItem ('e10.base.clsf');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$clsfItem = $clsfItems [$r['group']][$r['dstRecId']];
			$i = ['text' => $clsfItem['name'], 'class' => $class, 'clsfItem' => $r['dstRecId']];
			if (isset($clsfItem['css']) && $clsfItem['css'] !== '')
				$i['css'] = $clsfItem['css'];

			$this->classification[$r['srcRecId']][$r['group']][] = $i;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['note'] = array_merge ($item ['note'], $clsfGroup);
		}
	}
}


/**
 * Class ViewDetailAddFood
 * @package e10pro\canteen
 */
class ViewDetailAddFood extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormCanteenAddFood
 * @package e10pro\canteen
 */
class FormCanteenAddFood extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/iconCogs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput('addFoodType');
					$this->addColumnInput('fullName');
					$this->addColumnInput('shortName');
					$this->addColumnInput('addFoodOptional');
					$this->addColumnInput('addFoodOrder');

					$this->addColumnInput('validFrom');
					$this->addColumnInput('validTo');

					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
