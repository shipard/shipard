<?php

namespace e10\witems;


use \Shipard\Utils\Utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableItemSubTypes
 */
class TableItemSubTypes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemSubTypes', 'e10_witems_itemSubTypes', 'Podtypy položek');
	}

	public function saveConfig ()
	{
    $list = [];
    $rows = $this->app()->db->query ('SELECT * FROM [e10_witems_itemSubTypes] WHERE docState != 9800 ORDER BY [itemType], [order], [fullName]');
    foreach ($rows as $r)
    {
      $itemTypeNdx = $r['itemType'];
      $itemSubTypeNdx = $r['ndx'];
      $st = [
        'fn' => $r['fullName'],
        'vds' => $r['vds']
      ];

      $list[$itemTypeNdx][$itemSubTypeNdx] = $st;
    }

		$cfg ['e10']['witems']['subTypes'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10.witems.subTypes.json', utils::json_lint (json_encode ($cfg)));
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['shortName']];
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']) && $recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon ($recData, $options);
	}
}


/**
 * class ViewItemSubTypes
 */
class ViewItemSubTypes extends TableView
{
	var $today = NULL;
  var $itemType = 0;

	public function init ()
	{
		$this->enableDetailSearch = TRUE;

		$this->today = utils::today();
		$this->setMainQueries ();

		if ($this->queryParam ('itemType'))
    {
      $this->itemType = intval($this->queryParam ('itemType'));
			$this->addAddParam ('itemType', $this->queryParam ('itemType'));
    }

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['id'];

		$props = [];

		if (!utils::dateIsBlank($item['validFrom']) && $item['validFrom'] > $this->today)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		elseif (!utils::dateIsBlank($item['validFrom']))
			$props[] = ['text' => 'Od '.utils::datef($item['validFrom']), 'icon' => 'system/iconCalendar', 'class' => 'label label-success'];

		if (!utils::dateIsBlank($item['validTo']) && $item['validTo'] < $this->today)
		{
			$listItem['!error'] = 1;
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-danger'];
		}
		elseif (!utils::dateIsBlank($item['validTo']))
			$props[] = ['text' => 'Do '.utils::datef($item['validTo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-success'];

		if (count($props))
			$listItem['t2'] = $props;

		$types = $this->table->columnInfoEnum ('type');
		$listItem ['i2'] = $types [$item['type']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		//$item ['t2'] = $this->propgroups [$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = "SELECT * FROM [e10_witems_itemSubTypes] WHERE 1";

		// -- fulltext
		if ($fts != '')
    {
			array_push ($q, " AND ([fullName] LIKE %s OR [id] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');
    }

		// -- item type
		if ($this->itemType)
			array_push ($q, ' AND [itemType] = %i', $this->itemType);

    $this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class ViewItemTypesCombo
 */
class ViewItemSubTypesCombo extends ViewItemSubTypes
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['t2'] = $item['id'];

		//$types = $this->table->columnInfoEnum ('itemType');
		//$listItem ['i2'] = $types [$item['type']];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}
}


/**
 * class ViewDetailItemSubType
 */
class ViewDetailItemSubType extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class FormItemSubType
 */
class FormItemSubType extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
          $this->addColumnInput ('itemType');
          $this->addColumnInput ('fullName');
          $this->addColumnInput ('shortName');
          $this->addColumnInput ('id');
          $this->addColumnInput ('icon');
          $this->addColumnInput ('order');
          $this->addColumnInput ('vds');
          $this->addColumnInput ('validFrom');
          $this->addColumnInput ('validTo');
          $this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

