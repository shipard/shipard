<?php

namespace e10pro\reception;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableForeignersAccPlaces
 * @package e10pro\reception
 */
class TableForeignersAccPlaces extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.reception.foreignersAccPlaces', 'e10pro_reception_foreignersAccPlaces', 'Místa ubytovaání cizinců');
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = [];

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'normal', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function usersAccPlaces()
	{
		$places = [];
		$rows = $this->db()->query ('SELECT * FROM [e10pro_reception_foreignersAccPlaces] WHERE [docState] != 9800 ORDER BY [fullName]');
		foreach ($rows as $r)
		{
			$places[$r['ndx']] = ['ndx' => $r['ndx'],'sn' => $r['fullName']];
		}

		return $places;
	}
}


/**
 * Class ViewForeignersAccPlaces
 * @package e10pro\reception
 */
class ViewForeignersAccPlaces extends TableView
{
	public function init ()
	{
		parent::init();

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10pro_reception_foreignersAccPlaces]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%',
				' OR [city] LIKE %s', '%'.$fts.'%',
				' OR [idub] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormForeignerAccPlace
 * @package e10pro\reception
 */
class FormForeignerAccPlace extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		//$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Údaje', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('idub');
					$this->addColumnInput ('shortcut');
					$this->addColumnInput ('provider');
					$this->addColumnInput ('contact');
					$this->addColumnInput ('county');
					$this->addColumnInput ('city');
					$this->addColumnInput ('cityPart');
					$this->addColumnInput ('street');
					$this->addColumnInput ('streetNumber1');
					$this->addColumnInput ('streetNumber2');
					$this->addColumnInput ('zipCode');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailForeignerAccPlace
 * @package e10pro\reception
 */
class ViewDetailForeignerAccPlace extends TableViewDetail
{
}

