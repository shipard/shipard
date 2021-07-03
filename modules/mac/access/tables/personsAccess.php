<?php

namespace mac\access;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TablePersonsAccess
 * @package mac\access
 */
class TablePersonsAccess extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.access.personsAccess', 'mac_access_personsAccess', 'Přístup Osob');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => '---'];

		return $hdr;
	}
}


/**
 * Class ViewPersonsAccess
 * @package mac\access
 */
class ViewPersonsAccess extends TableView
{
	var $pksPersons = [];
	var $personsKeys = [];
	var $accessLevels = [];
	var $tagTypes;

	public function init ()
	{
		parent::init();

		$this->tagTypes = $this->app()->cfgItem('mac.access.tagTypes');

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['person'] = $item ['person'];

		if ($item['person'] && !in_array($item['person'], $this->pksPersons))
			$this->pksPersons[] = $item['person'];

		$listItem ['t1'] = ($item['personName']) ? $item['personName'] : '---';
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->personsKeys[$item ['person']]))
			$item ['i2'] = $this->personsKeys[$item ['person']];
		if (isset ($this->accessLevels[$item ['pk']]))
			$item ['t2'] = $this->accessLevels[$item ['pk']];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [pa].*, ';
		array_push ($q, ' persons.fullName as personName, persons.id AS personId, persons.company AS personCompany, persons.personType');
		array_push ($q, ' FROM [mac_access_personsAccess] AS [pa]');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [pa].[person] = [persons].[ndx]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'pa.', ['[persons].lastName', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- keys
		if (count($this->pksPersons))
		{
			$q[] = 'SELECT ta.*, [tags].keyValue, [tags].tagType FROM [mac_access_tagsAssignments] AS [ta]';
			array_push($q, ' LEFT JOIN [mac_access_tags] AS [tags] ON [ta].tag = [tags].ndx');
			array_push($q, ' WHERE [ta].[person] IN %in', $this->pksPersons);
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$item = ['text' => $r['keyValue'], 'icon' => $this->tagTypes[$r['tagType']]['icon'], 'class' => 'label label-default'];
				$this->personsKeys[$r['person']][] = $item;
			}
		}

		// -- levels
		$q= [];
		$q[] = 'SELECT pal.*, [al].fullName FROM [mac_access_personsAccessLevels] AS [pal]';
		array_push($q, ' LEFT JOIN [mac_access_levels] AS [al] ON [pal].[accessLevel] = [al].ndx');
		array_push($q, ' WHERE [pal].[ndx] IN %in', $this->pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['text' => $r['fullName'], 'icon' => 'tables/mac.access.levels', 'class' => 'label label-default'];
			$this->accessLevels[$r['ndx']][] = $item;
		}
	}
}


/**
 * Class FormPersonAccess
 * @package mac\access
 */
class FormPersonAccess extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Oprávnění', 'icon' => 'formRights'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('person');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addList ('accessLevels');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailPersonsAccess
 * @package mac\access
 */
class ViewDetailPersonsAccess extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.access.dc.AccessPerson');
	}
}
