<?php

namespace mac\access;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\str;


/**
 * Class TableGates
 * @package mac\access
 */
class TableGates extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.gates', 'mac_access_gates', 'Brány a dveře');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['id']];

		return $hdr;
	}
}


/**
 * Class ViewGates
 * @package mac\access
 */
class ViewGates extends TableView
{
	var $gateTypes;

	public function init ()
	{
		parent::init();

		$this->gateTypes = $this->app()->cfgItem('mac.access.gateTypes');

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$gt = $this->gateTypes[$item['gateType']];

		$listItem ['t2'] = $item['id'];

		$listItem ['i2'] = [];
		$listItem ['i2'][] = ['text' => $gt['name'], 'class' => 'label label-default'];
		if ($item ['order'])
			$listItem ['i2'][] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order'], 0), 'class' => ''];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [mac_access_gates]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormGate
 * @package mac\access
 */
class FormGate extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Brána', 'icon' => 'icon-home'];
		$tabs ['tabs'][] = ['text' => 'Rozvrh', 'icon' => 'icon-clock-o'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
					$this->addColumnInput ('gateType');
					$this->addColumnInput ('order');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('schedule', 'default');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
