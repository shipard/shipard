<?php

namespace mac\sw;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableSWNames
 * @package mac\sw
 */
class TableSWNames extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.swNames', 'mac_sw_swNames', 'Názvy software');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		return $hdr;
	}
}


/**
 * Class ViewSWNames
 * @package mac\sw
 */
class ViewSWNames extends TableViewGrid
{
	var $swNdx = 0;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->type = 'form';
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		$g = [
			'name' => 'Název',
			'note' => 'Pozn.',
		];
		$this->setGrid ($g);

		$this->swNdx = intval($this->queryParam('sw'));
		$this->addAddParam ('sw', $this->swNdx);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$listItem ['name'] = $item['name'];
		$listItem ['note'] = '';

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [names].*';
		array_push ($q, ' FROM [mac_sw_swNames] AS [names]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [names].[sw] = %i', $this->swNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [names].[rowOrder], [names].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailSWName
 * @package mac\sw
 */
class ViewDetailSWName extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormSWName
 * @package mac\sw
 */
class FormSWName extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Název', 'icon' => 'icon-tags'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('name');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
