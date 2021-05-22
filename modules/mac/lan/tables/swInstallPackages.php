<?php

namespace mac\lan;


use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TableSwInstallPackages
 * @package mac\lan
 */
class TableSwInstallPackages extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.lan.swInstallPackages', 'mac_lan_swInstallPackages', 'Instalační balíčky SW');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		if (!isset($recData['id']) || $recData['id'] === '')
			$recData['id'] = md5 ($this->app()->cfgItem('dsid').'-'.$recData['fullName'].'-'.time().mt_rand(10000000, 99999999));
	}

	public function checkAfterSave2 (&$recData)
	{
		$this->saveConfig();
	}

	public function createHeader ($recData, $options)
	{
		$hdr ['icon'] = $this->tableIcon ($recData);
		$hdr ['info'] = array();

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$packages = [];

		$rows = $this->app()->db->query ('SELECT * FROM [mac_lan_swInstallPackages] WHERE [docState] != 9800 ORDER BY [fullName]');

		foreach ($rows as $r)
		{
			$fn = trim ($r ['fullName']);
			$fn = str_replace("\0", '', $fn);
			$pkg = ['ndx' => $r ['ndx'], 'fullName' => $fn, 'app' => $r['app'], 'names' => []];
			$names = explode ("\n", $r['pkgNames']);
			foreach ($names as $n)
			{
				$name = trim($n);
				$name = str_replace("\0", '', $name);
				if ($name == '')
					continue;
				$pkg['names'][] = $name;
			}
			$packages[] = $pkg;
		}

		// -- save to file
		$cfg ['mac']['lan']['swInstallPackages'] = $packages;
		file_put_contents(__APP_DIR__ . '/config/~mac.lan.swInstallPackages.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewSwInstallPackages
 * @package mac\lan
 */
class ViewSwInstallPackages extends TableView
{
	public function init ()
	{
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['id'];
		//$listItem ['t2'] = $item['ownerFullName'];
		//$listItem ['i1'] = ['text' => $item['shortName'], 'class' => 'id'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT installPackages.* FROM [mac_lan_swInstallPackages] AS installPackages ';

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push($q, ' AND (',
				'[fullName] LIKE %s', '%'.$fts.'%',
					' OR [shortName] LIKE %s', '%'.$fts.'%',
					' OR [pkgNames] LIKE %s', '%'.$fts.'%',
				')');
		}
		$this->queryMain ($q, 'installPackages.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormSwInstallPackage
 * @package mac\lan
 */
class FormSwInstallPackage extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Síť', 'icon' => 'x-content'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'x-wrench'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-image'];

		$this->openTabs ($tabs);
			$this->openTab ();
				$this->addColumnInput ('fullName');
				$this->addColumnInput ('shortName');
				$this->addColumnInput ('app');
				$this->addColumnInput ('pkgNames');
			$this->closeTab ();

			$this->openTab ();
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

		$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSwInstallPackage
 * @package mac\lan
 */
class ViewDetailSwInstallPackage extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.lan.DocumentCardSwPackage');
	}
}

