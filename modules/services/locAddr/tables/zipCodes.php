<?php

namespace services\locAddr;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;

/**
 * class TableZIPCodes
 */
class TableZIPCodes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.locAddr.zipCodes', 'services_locAddr_zipCodes', 'PSČ');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => '_'.$recData ['zipCodeId'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewZIPCodes
 */
class ViewZIPCodes extends TableView
{
	public function init()
	{
		parent::init();
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['idName'];

		$listItem ['i1'] = ['text' => '#'.$item['zipCodeId'], 'class' => 'id'];

    $listItem ['t2'] = $item['fullName'];

		$listItem ['i2'] = Utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [zipCodes].*');
		array_push ($q, ' FROM [services_locAddr_zipCodes] AS [zipCodes]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
      array_push ($q, '[zipCodes].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [zipCodes].[idName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
    }

    array_push ($q, ' ORDER BY fullName, zipCodeId, ndx');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
	}
}


/**
 * Class FormZIPCode
 */
class FormZIPCode extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('zipCodeId');
			$this->addColumnInput ('idName');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('country');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailZIPCode
 */
class ViewDetailZIPCode extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
