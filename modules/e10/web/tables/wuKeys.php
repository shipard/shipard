<?php

namespace e10\web;

use e10\DbTable, e10\TableView, e10\TableForm, e10\utils;


/**
 * Class TableWuKeys
 * @package e10\web
 */
class TableWuKeys extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.web.wuKeys', 'e10_web_wuKeys', 'Klíče uživatelů webu');
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($recData['keyType'] == 1 && isset($recData['keyValue']) && $recData['keyValue'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['keyValue'] = utils::createToken(60);
			$this->app()->db()->query("UPDATE [e10_web_wuKeys] SET [keyValue] = %s WHERE [ndx] = %i", $recData['keyValue'], $recData['ndx']);
		}
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		$recData['created'] = new \DateTime ();
	}

	public function checkUrlKey($webServerNdx, $personNdx)
	{
		$exist = $this->db()->query ('SELECT keyValue FROM e10_web_wuKeys WHERE keyType = 1 AND webServer = %i', $webServerNdx,
			' AND [person] = %i', $personNdx)->fetch();
		if ($exist)
			return $exist['keyValue'];

		$newItem = [
			'keyType' => 1, 'webServer' => $webServerNdx, 'person' => $personNdx,
			'created' => new \DateTime(), 'docState' => 4000, 'docStateMain' => 2
		];
		$newItem['keyValue'] = utils::createToken(60);

		$this->db()->query ('INSERT INTO [e10_web_wuKeys]', $newItem);
		$newNdx = $this->db()->getInsertId();
		if ($newNdx)
		{
			$exist = $this->db()->query ('SELECT keyValue FROM e10_web_wuKeys WHERE ndx = %i', $newNdx)->fetch();
			if ($exist)
				return $exist['keyValue'];
		}
		return FALSE;
	}
}


/**
 * Class ViewWuKeys
 * @package e10\web
 */
class ViewWuKeys extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['personName'];
		$listItem ['t2'] = substr($item['keyValue'], 0, 10).'...'.substr($item['keyValue'], -10, 10);
		$listItem ['i2'] = [
			['text' => $item['webServerName'], 'icon' => 'icon-globe', 'class' => 'label label-default']
		];
		$listItem ['icon'] = $this->table->tableIcon($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT wuKeys.*, persons.[fullName] AS personName, webServers.fullName AS webServerName';
		array_push ($q, ' FROM [e10_web_wuKeys] AS wuKeys');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON wuKeys.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_web_servers AS webServers ON wuKeys.webServer = webServers.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (');
			array_push($q, ' wuKeys.[keyValue] LIKE %s', '%' . $fts . '%');
			array_push($q, ' OR persons.[fullName] LIKE %s', '%' . $fts . '%');
			array_push($q, ')');
		}

		$this->queryMain ($q, 'wuKeys.', ['persons.[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWuKey
 * @package e10\web
 */
class FormWuKey extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput('keyType');
			$this->addColumnInput('webServer');
			$this->addColumnInput('person');
			$this->addColumnInput('keyValue');
		$this->closeForm ();
	}
}
