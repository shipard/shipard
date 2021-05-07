<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableMenuRecipientsDefs
 * @package e10pro\canteen
 */
class TableMenuRecipientsDefs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.menuRecipientsDefs', 'e10pro_canteen_menuRecipientsDefs', 'Definice strávníků');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if ($recData['recipientType'] == 0)
		{
			$recData['fullName'] = '';
			$person = $this->app()->loadItem($recData['person'], 'e10.persons.persons');
			if ($person)
				$recData['fullName'] = $person['fullName'];
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'fullName', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewMenuRecipientsDefs
 * @package e10pro\canteen
 */
class ViewMenuRecipientsDefs extends TableView
{
	var $usersCanteens;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$tableCanteens = $this->app()->table('e10pro.canteen.canteens');
		$this->usersCanteens = $tableCanteens->usersCanteens();

		$active = 1;
		forEach ($this->usersCanteens as $canteen)
		{
			$bt [] = [
				'id' => $canteen['ndx'], 'title' => $canteen['sn'], 'active' => $active,
				'addParams' => ['canteen' => $canteen['ndx']]
			];

			$active = 0;
		}
		if (count($this->usersCanteens) > 1)
			$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$props = [];
		if ($item['canteenName'])
			$props[] = ['text' => $item['canteenName'], 'icon' => 'icon-cutlery', 'class' => 'label label-default'];

		if ($item['recipientType'] === 0)
		{
			if ($item['personName'])
				$props[] = ['text' => $item['personName'], 'icon' => 'icon-user', 'class' => 'label label-info'];
			else
				$props[] = ['text' => 'Osoba není zadána', 'icon' => 'icon-user', 'class' => 'label label-danger'];
		}
		elseif ($item['recipientType'] === 1)
		{
			$categoryTypes = $this->table->columnInfoEnum ('categoryType', 'cfgText');

			$props[] = ['text' => $categoryTypes[$item['categoryType']], 'icon' => 'icon-share', 'class' => 'label label-info'];

			if ($item['categoryPersonName'])
				$props[] = ['text' => $item['categoryPersonName'], 'icon' => 'icon-building', 'class' => 'label label-info'];
			else
				$props[] = ['text' => 'Osoba není zadána', 'icon' => 'icon-building', 'class' => 'label label-danger'];
		}

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$bt = intval($this->bottomTabId ());

		$q [] = 'SELECT defs.*, persons.fullName AS personName, categoryPersons.fullName AS categoryPersonName, canteens.shortName AS canteenName';
		array_push ($q, ' FROM [e10pro_canteen_menuRecipientsDefs] AS defs');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON defs.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON defs.person = persons.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS categoryPersons ON defs.categoryPerson = categoryPersons.ndx');
		array_push ($q, ' WHERE 1');

		if ($bt)
			array_push ($q, ' AND canteen = %i', $bt);
		else
		{
			if (count($this->usersCanteens))
				array_push($q, ' AND canteen IN %in', array_keys($this->usersCanteens));
			else
				array_push ($q, ' AND canteen = %i', -1);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' defs.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR persons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR categoryPersons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'defs.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormMenuRecipientDef
 * @package e10pro\canteen
 */
class FormMenuRecipientDef extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ('recipientType');

			if ($this->recData['recipientType'] === 0)
			{
				$this->addColumnInput ('person');
			}
			elseif ($this->recData['recipientType'] === 1)
			{
				$this->addColumnInput ('fullName');
				$this->addColumnInput('categoryType');
				$this->addColumnInput('categoryPerson');
			}
			elseif ($this->recData['recipientType'] === 2)
			{
				$this->addColumnInput ('fullName');
				$this->addColumnInput('personLabel');
			}

			$this->addColumnInput ('canteen');
		$this->closeForm ();
	}
}

