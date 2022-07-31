<?php

namespace plans\core;

use \e10\TableForm, \e10\DbTable, \e10\TableView, e10\TableViewDetail, \e10\utils, \e10\str;


/**
 * Class TablePlans
 */
class TablePlans extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('plans.core.plans', 'plans_core_plans', 'Plány');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['fullName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['shortName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$rows = $this->app()->db->query ("SELECT * FROM [plans_core_plans] WHERE docState != 9800 ORDER BY [order], [fullName]");
		$plans = [];
		foreach ($rows as $r)
		{
			$plan = [
				'ndx' => $r['ndx'], 'fn' => $r['fullName'], 'sn' => $r['shortName'],
				'title' => $r['title'],
				'primaryViewType' => intval($r['primaryViewType']),
				'useViewDetail' => intval($r['useViewDetail']),
				'useWorkOrders' => intval($r['useWorkOrders']),
				'useCustomer' => intval($r['useCustomer']),
				'useProjectId' => intval($r['useProjectId']),
				'usePrice' => intval($r['usePrice']),
				'useText' => intval($r['useText']),
				'useTableViewTabsMonths' => intval($r['useTableViewTabsMonths']),

				'useAnnots' => intval($r['useAnnots']),
				'plansWorkOrdersRows' => intval($r['plansWorkOrdersRows']),

        //'icon' => $r['icon'],
        //'dp' => intval($r['dashboardPlace'])
			];



			$cntPeoples = 0;
      /*
			$cntPeoples += $this->saveConfigList ($wiki, 'admins', 'e10.persons.persons', 'e10pro-kb-wikies-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'adminsGroups', 'e10.persons.groups', 'e10pro-kb-wikies-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'users', 'e10.persons.persons', 'e10pro-kb-wikies-users', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($wiki, 'usersGroups', 'e10.persons.groups', 'e10pro-kb-wikies-users', $r ['ndx']);
      */
			$plan['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$plans [$r['ndx']] = $plan;
		}

		$cfg ['plans']['plans'] = $plans;
		file_put_contents(__APP_DIR__ . '/config/_plans.plans.json', utils::json_lint (json_encode ($cfg)));
	}

  public function usersPlans()
  {
    $plans = $this->app()->cfgItem('plans.plans');
    return $plans;
  }
}


/**
 * Class ViewPlans
 */
class ViewPlans extends TableView
{
	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];

		//$listItem ['t2'] = $item['id'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [plans_core_plans]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [shortName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormPlan
 */
class FormPlan extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Zakázky', 'icon' => 'tables/e10mnf.core.workOrders'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('primaryViewType');
					$this->addColumnInput ('useViewDetail');
					$this->addColumnInput ('useWorkOrders');
					$this->addColumnInput ('useCustomer');
					$this->addColumnInput ('useProjectId');
					$this->addColumnInput ('usePrice');
					$this->addColumnInput ('useText');

					$this->addColumnInput ('useTableViewTabsMonths');

					$this->addColumnInput ('useAnnots');
					$this->addColumnInput ('plansWorkOrdersRows');
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
 * Class ViewDetailPlan
 */
class ViewDetailPlan extends TableViewDetail
{
}

