<?php

namespace e10pro\zus;

use \Shipard\Viewer\TableView;
use \Shipard\Form\TableForm;
use \Shipard\Table\DbTable;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableOddeleni
 */
class TableOddeleni extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.zus.oddeleni", "e10pro_zus_oddeleni", "Oddělení");
	}

  public function saveConfig ()
	{
		$ca = array ();

		$rows = $this->app()->db->query ("SELECT * from [e10pro_zus_oddeleni] WHERE docState != 9800 ORDER BY [pos], [nazev]");
		forEach ($rows as $r)
		{
			$itm = [
				'id' => $r['ndx'], 'nazev' => $r ['nazev'],
				'tisk' => $r['tisk'], 'svp' => $r ['svp'],
				'obor' => $r ['obor'], 'oznaceni' => $r ['id'],
				'urovenStudia' => $r ['urovenStudia'],
				'platnostOd' => NULL, 'platnostDo' => NULL,
			];

			if ($r['navazneOddeleni'])
				$itm['navazneOddeleni'] = $r ['navazneOddeleni'];

			if ($itm['tisk'] === '')
				$itm['tisk'] = $r ['nazev'];

			if (!Utils::dateIsBlank($r['platnostOd']))
				$itm['platnostOd'] = $r['platnostOd']->format('Y-m-d');

			if (!Utils::dateIsBlank($r['platnostDo']))
				$itm['platnostDo'] = $r['platnostDo']->format('Y-m-d');

			$ca [$r['ndx']] = $itm;
		}
		$ca [0] = [
			'id' => 0, 'nazev' => '---', 'tisk' => '', 'svp' => 0, 'obor' => 0,  'oznaceni' => '',
			'urovenStudia' => 0,
			'platnostOd' => NULL, 'platnostDo' => NULL,
		];

		// save to file
		$cfg ['e10pro']['zus']['oddeleni'] = $ca;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.zus.oddeleni.json', Json::lint ($cfg));
	}
}


/**
 * class ViewOddeleni
 */
class ViewOddeleni extends TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['nazev'];
		$listItem ['icon'] = 'tables/e10pro.zus.oddeleni';

		if ($item ['urovenStudia'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("zus.urovenStudia.{$item ['urovenStudia']}.nazev"), 'class' => 'label label-default'];

		if ($item ['svp'])
			$listItem ['t2'][] = ['text' => $this->app()->cfgItem ("e10pro.zus.svp.{$item ['svp']}.nazev"), 'class' => 'label label-default'];

		if ($item ['obor'])
			$listItem ['t2'][] = ['text' => 'obor '.$this->app()->cfgItem ("e10pro.zus.obory.{$item ['obor']}.nazev"), 'class' => 'label label-default'];

		if ($item['pos'])
			$listItem['i2'] = ['icon' => 'icon-sort', 'text' => strval ($item['pos'])];


		$props = [];

		if (!Utils::dateIsBlank($item['platnostOd']))
		{
			$props[] = ['text' => 'Od '.utils::datef($item['platnostOd']), 'icon' => 'system/iconCalendar', 'class' => 'label label-default'];
		}
		if (!Utils::dateIsBlank($item['platnostDo']))
		{
			$props[] = ['text' => 'Do '.utils::datef($item['platnostDo']), 'icon' => 'system/iconCalendar', 'class' => 'label label-default'];
		}

		if ($item['navazneNazev'])
		{
			$props[] = ['text' => $item['navazneNazev'], 'class' => 'label label-default', 'icon' => 'system/iconAngleRight'];
		}

		if (count($props))
			$listItem['t3'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q = [];
		array_push ($q, 'SELECT oddeleni.*, navazneOddeleni.nazev AS navazneNazev');
		array_push ($q, ' FROM [e10pro_zus_oddeleni] AS oddeleni');
		array_push ($q, ' LEFT JOIN e10pro_zus_oddeleni AS navazneOddeleni ON oddeleni.navazneOddeleni = navazneOddeleni.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND oddeleni.[nazev] LIKE %s", '%'.$fts.'%');

		// -- active
		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND oddeleni.[docStateMain] < 4");

		// -- archive
		if ($mainQuery == 'archive')
			array_push ($q, " AND oddeleni.[docStateMain] = 5");

		// trash
		if ($mainQuery == 'trash')
			array_push ($q, " AND oddeleni.[docStateMain] = 4");

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY oddeleni.[pos], oddeleni.[nazev] ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY oddeleni.[docStateMain], oddeleni.[pos], oddeleni.[nazev] ' . $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * class FormOddeleni
 */
class FormOddeleni extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
    $this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Pobočky', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('svp');
					$this->addColumnInput ('obor');
					$this->addColumnInput ('urovenStudia');
					$this->addColumnInput ('nazev');
					$this->addColumnInput ('tisk');
					$this->addColumnInput ('id');
					$this->addColumnInput ('pos');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('stop');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('platnostOd');
					$this->addColumnInput ('platnostDo');
					$this->addColumnInput ('navazneOddeleni');
				$this->closeTab ();
				$this->openTab ();
					$this->addList ('pobocky');
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}

