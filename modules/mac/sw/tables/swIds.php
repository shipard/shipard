<?php

namespace mac\sw;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewGrid, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TableSWIds
 * @package mac\sw
 */
class TableSWIds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.swIds', 'mac_sw_swIds', 'ID software');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}
}


/**
 * Class ViewSWIds
 * @package mac\sw
 */
class ViewSWIds extends TableViewGrid
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
			'id' => 'Id',
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


		$listItem ['id'] = $item['id'];
		$listItem ['note'] = '';

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [ids].*';
		array_push ($q, ' FROM [mac_sw_swIds] AS [ids]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [ids].[sw] = %i', $this->swNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [ids].[rowOrder], [ids].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailSWId
 * @package mac\sw
 */
class ViewDetailSWId extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormSWId
 * @package mac\sw
 */
class FormSWId extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault viewerFormList');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_PARENT_FORM);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'ID', 'icon' => 'icon-crosshairs'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('id');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
