<?php

namespace e10doc\debs;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableGroups
 * @package e10doc\debs
 */
class TableGroups extends DbTable
{
	CONST agtWitems = 0, agtPropertyDepreciated = 1, agtPropertyNonDepreciated = 2;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.debs.groups', 'e10doc_debs_groups', 'Účetní skupiny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$debsGroups = array ();
		$rows = $this->app()->db->query ("SELECT * from [e10doc_debs_groups] WHERE [docState] != 9800 ORDER BY [fullName]");

		foreach ($rows as $r)
		{
			$debsGroups [$r['ndx']] = ['ndx' => $r ['ndx'], 'analytics' => $r ['analytics'], 'fullName' => $r ['fullName'], 'groupKind' => $r['groupKind']];
		}
		$debsGroups [0] = array ('ndx' => 0, 'analytics' => '', 'fullName' => '---');

		// save to file
		$cfg ['e10debs']['groups'] = $debsGroups;
		file_put_contents(__APP_DIR__ . '/config/_e10debs.groups.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewGroups
 * @package e10doc\debs
 */
class ViewGroups extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10doc_debs_groups] WHERE 1';

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, " AND (");
			array_push ($q, "[fullName] LIKE %s", '%'.$fts.'%');
			array_push ($q, " OR ");
			array_push ($q, "[analytics] LIKE %s", $fts.'%');
			array_push ($q, ")");
		}

		$this->queryMain ($q, '', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	} // selectRows

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['i2'] = $item['analytics'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$groupKind = $this->table->columnInfoEnum ('groupKind', 'cfgText');
		$listItem ['t2'] = ['text' => $groupKind[$item['groupKind']], 'class' => 'label label-default'];

		return $listItem;
	}
}


/**
 * Class ViewDetailGroup
 * @package e10doc\debs
 */
class ViewDetailGroup extends TableViewDetail
{
}


/**
 * Class FormGroup
 * @package e10doc\debs
 */
class FormGroup extends TableForm
{
	public function renderForm ()
	{
		//$this->setFlag ('maximize', 1);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Vlastnosti', 'icon' => 'x-properties'];

		if ($this->recData['groupKind'] == TableGroups::agtPropertyDepreciated || $this->recData['groupKind'] == TableGroups::agtPropertyNonDepreciated)
			$tabs ['tabs'][] = ['text' => 'Majetek', 'icon' => 'icon-institution'];

		$tabs ['tabs'][] = ['text' => 'Poznámka', 'icon' => 'icon-edit'];

		$this->openForm ();
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('groupKind');
					$this->addColumnInput ('analytics');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
				if ($this->recData['groupKind'] == TableGroups::agtPropertyDepreciated)
				{
					$this->openTab();
						$this->addColumnInput('debsAccPropIdProperty');
						$this->addColumnInput('debsAccPropIdInclusion');
						$this->addColumnInput('debsAccPropIdEnhancement');
						$this->addColumnInput('debsAccPropIdDepDebit');
						$this->addColumnInput('debsAccPropIdDepCredit');
						$this->addColumnInput('debsAccPropIdSale');
					$this->closeTab();
				}
				elseif ($this->recData['groupKind'] == TableGroups::agtPropertyNonDepreciated)
				{
					$this->openTab();
						$this->addColumnInput('debsAccPropIdBuy');
						$this->addColumnInput('debsAccPropIdSale');
					$this->closeTab();
				}
				$this->openTab ();
					$this->addColumnInput ('note', TableForm::coFullSizeY);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
