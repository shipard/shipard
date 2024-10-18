<?php

namespace e10mnf\base;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableWIPSettings
 */
class TableWIPSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.wipSettings', 'e10mnf_base_wipSettings', 'Nastavení monitoringu práce');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10mnf_base_wipSettings] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
        'ndx' => $r ['ndx'],
        'fn' => $r ['fullName'],
        'wrDocKind' => $r ['wrDocKind'],
				'btnTextStart' => $r ['btnTextStart'],
				'btnTextStop' => $r ['btnTextStop'],
      ];
			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg ['e10mnf']['base']['wipSettings'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.base.wipSettings.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * class ViewWorkActivities
 */
class ViewWIPSettings extends TableView
{
  var $personsLists = [];

	public function init ()
	{
		parent::init();
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT * FROM [e10mnf_base_wipSettings] as wips');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' wips.[fullName] LIKE %s', '%'.$fts.'%',
				' OR wips.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'wips.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

    $q = [];
    array_push ($q, 'SELECT wipsPersons.*, persons.fullName AS personFullName');
    array_push ($q, ' FROM [e10mnf_base_wipSettingsPersons] AS wipsPersons');
    array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON wipsPersons.person = persons.ndx');
    array_push ($q, ' WHERE wipsPersons.wipSettings IN %in', $this->pks);
    array_push ($q, ' ORDER BY wipsPersons.rowOrder');
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $pl = NULL;
      if ($r['rowType'] == 0)
      { // group
        $pg = $this->app()->cfgItem('e10.persons.groups.'.$r['personsGroup'], NULL);
        if ($pg)
          $pl = ['text' => $pg['name'], 'class' => 'label label-default', 'icon' => 'tables/e10.persons.groups'];
      }
      else
      { // person
        $pl = ['text' => $r['personFullName'], 'class' => 'label label-default', 'icon' => 'system/iconUser'];
      }
      if ($pl)
        $this->personsLists[$r['wipSettings']][] = $pl;
    }
	}

  function decorateRow (&$item)
	{
		if (isset($this->personsLists[$item ['pk']]))
		{
			$item['t2'] = array_merge($item['t2'], $this->personsLists[$item ['pk']]);
		}
	}
}




/**
 * class ViewDetailWIPSettings
 */
class ViewDetailWIPSettings extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class FormWIPSettings
 */
class FormWIPSettings extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
      $tabs ['tabs'][] = ['text' => 'Osoby', 'icon' => 'tables/e10.persons.persons'];
      $tabs ['tabs'][] = ['text' => 'Zakázky', 'icon' => 'tables/e10mnf.core.workOrders'];
      $tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
          $this->addColumnInput ('workActivity');
					$this->addColumnInput ('wrDocKind');
          $this->addColumnInput ('wrDbCounter');
          $this->addColumnInput ('order');
				$this->closeTab();
        $this->openTab();
          $this->addList('persons');
        $this->closeTab();
        $this->openTab();
          $this->addList('workOrders');
        $this->closeTab();
        $this->openTab ();
					$this->addColumnInput ('btnTextStart');
					$this->addColumnInput ('btnTextStop');
			$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
