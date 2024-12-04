<?php

namespace e10pro\emps;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils;


/**
 * class TableOrgs
 */
class TableOrgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.emps.orgs', 'e10pro_emps_orgs', 'Organizační struktura');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$this->checkTree (0, '', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkTree ($ownerNdx, $ownerTreeId, $level)
	{
		$treeRows = $this->app()->db->query ('SELECT * FROM [e10pro_emps_orgs] WHERE [parentItem] = %i', $ownerNdx, ' ORDER BY [order], [fullName]');
		$rowIndex = 1;
		forEach ($treeRows as $row)
		{
			$rowTreeId = $ownerTreeId . sprintf ("%03d", $rowIndex);
			$rowUpdate = [];
			$rowUpdate ['treeLevel'] = $level;
			$rowUpdate ['treeId'] = $rowTreeId;

			$this->db()->query ('UPDATE [e10pro_emps_orgs] SET', $rowUpdate, ' WHERE [ndx] = %i', $row ['ndx']);
			$this->checkTree ($row ['ndx'], $rowTreeId, $level + 1);

			$rowIndex++;
		}
	}
}


/**
 * class ViewOrgs
 */
class ViewOrgs extends TableView
{
	var $orgsPersons = [];

	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];
		$listItem ['level'] = $item['treeLevel'];
		$listItem ['t1'] = $item['fullName'];

    $props = [];
		if ($item['order'])
			$props[] = ['text' => Utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];
		if (count($props))
			$listItem ['i2'] = $props;

    $listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [orgs].*');
    array_push ($q, ' FROM [e10pro_emps_orgs] AS [orgs]');
    array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
    {
      array_push ($q, 'AND (');
			array_push ($q, '[orgs].[orgs] LIKE %s ', '%'.$fts.'%');
			array_push ($q, ' OR [orgs].[shortName] LIKE %s ', '%'.$fts.'%');
      array_push ($q, ')');
    }

		$this->queryMain ($q, '[orgs].', ['[treeId]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$q = [];
		array_push($q, 'SELECT [orgsPersons].*, [persons].[fullName] AS [personName]');
		array_push($q, ' FROM [e10pro_emps_orgsPersons] AS [orgsPersons]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [orgsPersons].[person] = [persons].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orgsPersons].[orgs] IN %in', $this->pks);
		array_push($q, ' AND [orgsPersons].[superior] = %i', 1);
		array_push($q, ' ORDER BY [orgsPersons].[orgs], [orgsPersons].[rowOrder]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->orgsPersons [$r ['orgs']][] = ['text' => $r['personName'], 'class' => 'label label-default'];
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->orgsPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->orgsPersons [$item ['pk']];
		}
	}
}


/**
 * class FormOrgs
 */
class FormOrgs extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Osoby', 'icon' => 'system/formRows'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
          $this->addColumnInput ('order');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addList ('persons');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('parentItem');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailOrgs
 */
class ViewDetailOrgs extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10pro.emps.libs.dc.DCOrgs');
	}
}
