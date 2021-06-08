<?php

namespace mac\access;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail, \e10\utils, \e10\str;



/**
 * Class TableLevelsCfg
 * @package mac\access
 */
class TablePersonsAccessLevels extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.personsAccessLevels', 'mac_access_personsAccessLevels', 'Úrovně přístupu Osob');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => '---'];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (utils::dateIsBlank($recData['validFrom']))
			$recData['validFrom'] = NULL;
		if (utils::dateIsBlank($recData['validTo']))
			$recData['validTo'] = NULL;

		parent::checkBeforeSave($recData, $ownerData);
	}
}


/**
 * Class ViewPersonsAccessLevels
 * @package mac\access
 */
class ViewPersonsAccessLevels extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		//$this->accessLevelNdx = intval($this->queryParam('accessLevel'));
		//$this->addAddParam ('accessLevel', $this->accessLevelNdx);

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = '---';
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		/**
		$listItem ['t2'] = $item['id'];

		$listItem ['i2'] = [];
		if ($item ['order'])
			$listItem ['i2'][] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order'], 0), 'class' => ''];
*/
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [pal].* FROM [mac_access_personsAccessLevels] AS [pal]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			/*
			array_push ($q, ' AND (');
			array_push ($q, ' [id] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
			*/
		}

		array_push ($q, ' ORDER BY [pal].[rowOrder], [pal].[ndx]');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class ViewDetailPersonAccessLevel
 * @package mac\access
 */
class ViewDetailPersonAccessLevel extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContent(['type' => 'line', 'line' => ['text' => 'cfg #'.$this->item['ndx']]]);
	}
}


/**
 * Class FormPersonAccessLevel
 * @package mac\access
 */
class FormPersonAccessLevel extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleDefault');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT );
//		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$this->addColumnInput ('accessLevel');
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');
		$this->closeForm ();
	}
}
