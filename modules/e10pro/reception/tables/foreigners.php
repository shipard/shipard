<?php

namespace e10pro\reception;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \e10\TableViewPanel, \E10\utils;


/**
 * Class TableForeigners
 * @package e10pro\reception
 */
class TableForeigners extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.reception.foreigners', 'e10pro_reception_foreigners', 'Evidence cizinců');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'normal', 'value' => $recData['lastName'].', '.$recData['firstName']];

		return $hdr;
	}
}


/**
 * Class ViewForeigners
 * @package e10pro\reception
 */
class ViewForeigners extends TableView
{
	var $usersAccPlaces;


	public function init ()
	{
		parent::init();

		$this->setPanels (TableView::sptQuery);

		$tableAccPlaces = $this->app()->table('e10pro.reception.foreignersAccPlaces');
		$this->usersAccPlaces = $tableAccPlaces->usersAccPlaces();

		$active = 1;
		$bt = [];
		forEach ($this->usersAccPlaces as $bi)
		{
			$bt [] = [
				'id' => $bi['ndx'], 'title' => $bi['sn'], 'active' => $active,
				'addParams' => ['accPlace' => $bi['ndx']]
			];

			$active = 0;
		}
		if (count($this->usersAccPlaces) > 1)
			$bt [] = ['id' => '0', 'title' => 'Vše', 'active' => 0];

		$this->setBottomTabs ($bt);

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['lastName'].', '.$item['firstName'];

		$listItem ['t2'] = [];
		$date = '';
		if ($item['dateBegin'])
			$date .= utils::datef($item['dateBegin'], '%d');
		if ($item['dateEnd'])
			$date .= ' → '.utils::datef($item['dateEnd'], '%d');

		if ($date !== '')
			$listItem ['t2'][] = ['text' => $date, 'icon' => 'icon-calendar'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bt = intval($this->bottomTabId ());

		$q [] = 'SELECT * FROM [e10pro_reception_foreigners]';
		array_push ($q, ' WHERE 1');

		if ($bt)
			array_push ($q, ' AND accPlace = %i', $bt);
		else
		{
			if (count($this->usersAccPlaces))
				array_push($q, ' AND accPlace IN %in', array_keys($this->usersAccPlaces));
			else
				array_push ($q, ' AND accPlace = %i', -1);
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [lastName] LIKE %s', '%'.$fts.'%',
				' OR [identityId] LIKE %s', '%'.$fts.'%',
				' OR [visa] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[lastName]', '[firstName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		//$panel->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => "Nazdááár"]);
	}
}


/**
 * Class FormForeigner
 * @package e10pro\reception
 */
class FormForeigner extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Údaje', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'icon-wrench'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('lastName');
					$this->addColumnInput ('firstName');

					$this->addColumnInput ('country');
					$this->addColumnInput ('residence');

					$this->openRow();
						$this->addColumnInput ('birthdayDay');
						$this->addColumnInput ('birthdayMonth');
						$this->addColumnInput ('birthdayYear');
					$this->closeRow();

					$this->addColumnInput ('identityId');
					$this->addColumnInput ('visa');
					$this->addColumnInput ('stayPurpose');

					$this->addColumnInput ('dateBegin');
					$this->addColumnInput ('dateEnd');

					$this->addColumnInput ('note');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('accPlace');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailForeigner
 * @package e10pro\reception
 */
class ViewDetailForeigner extends TableViewDetail
{
}

