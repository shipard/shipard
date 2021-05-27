<?php

namespace e10pro\canteen;
use \e10\TableView, \e10\TableForm, \e10\DbTable;
use \e10\base\libs\UtilsBase;

/**
 * Class TablePersonsOptions
 * @package e10pro\canteen
 */
class TablePersonsOptions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.canteen.personsOptions', 'e10pro_canteen_personsOptions', 'Nastavení strávníků');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData['fullName'] = '';
		$person = $this->app()->loadItem($recData['person'], 'e10.persons.persons');
		if ($person)
			$recData['fullName'] = $person['fullName'];

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
 * Class ViewPersonsOptions
 * @package e10pro\canteen
 */
class ViewPersonsOptions extends TableView
{
	var $usersCanteens;
	var $personsFoods = [];
	var $foodTakings;
	var $linkedPersons;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();


		$this->foodTakings = $this->app()->cfgItem ('e10pro.canteen.foodTakings');
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
		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$props = [];
		if ($item['canteenName'])
			$props[] = ['text' => $item['canteenName'], 'icon' => 'icon-cutlery', 'class' => 'label label-default'];


		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->personsFoods[$item['pk']]))
		{
			$item ['t2'] = [];
			foreach ($this->personsFoods[$item['pk']] as $uf)
			{
				$info = ['text' => $uf['name'], 'class' => 'label label-info', 'icon' => 'icon-cutlery'];
				$ft = $this->foodTakings[$uf['taking']];
				if ($uf['taking'])
					$info['suffix'] = $ft['name'];
				$item ['t2'][] = $info;
			}
		}

		if (isset ($this->linkedPersons [$item ['pk']]['e10pro-canteens-payers']))
			$item ['t3'] = $this->linkedPersons [$item ['pk']]['e10pro-canteens-payers'];
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$bt = intval($this->bottomTabId ());

		$q [] = 'SELECT opts.*, persons.fullName AS personName, persons.id AS personId, canteens.shortName AS canteenName';
		array_push ($q, ' FROM [e10pro_canteen_personsOptions] AS opts');
		array_push ($q, ' LEFT JOIN e10pro_canteen_canteens AS canteens ON opts.canteen = canteens.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON opts.person = persons.ndx');
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
			array_push ($q, ' opts.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR persons.fullName LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'opts.', ['persons.[lastName]', 'fullName', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		parent::selectRows2();

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-primary');

		$q[] = 'SELECT * FROM [e10pro_canteen_personsOptionsFoods]';
		array_push ($q, ' WHERE personOptions IN %in', $this->pks);
		array_push ($q, ' ORDER BY personOptions, rowOrder');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personsFoods[$r['personOptions']][] = $r->toArray();
		}
	}
}


/**
 * Class FormPersonOptions
 * @package e10pro\canteen
 */
class FormPersonOptions extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Jídla', 'icon' => 'formMeals'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('person');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addList ('foods');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('canteen');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}

