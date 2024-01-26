<?php

namespace plans\core;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;

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
				'useViewCompact' => intval($r['useViewCompact']),
				'useViewStatesColors' => intval($r['useViewStatesColors']),
				'useViewTree' => intval($r['useViewTree']),
				'useWorkOrders' => intval($r['useWorkOrders']),
				'useCustomer' => intval($r['useCustomer']),
				'useProjectId' => intval($r['useProjectId']),
				'usePrice' => intval($r['usePrice']),
				'useText' => intval($r['useText']),
				'useTeams' => intval($r['useTeams']),
				'useTableViewTabsMonths' => intval($r['useTableViewTabsMonths']),

				'useAnnots' => intval($r['useAnnots']),
				'plansWorkOrdersRows' => intval($r['plansWorkOrdersRows']),

        'icon' => $r['icon'],
        'order' => intval($r['order']),
        'addToDashboardHome' => intval($r['addToDashboardHome']),
				'addToDashboardMnf' => intval($r['addToDashboardMnf']),
			];

			$cntPeoples = 0;

			$cntPeoples += $this->docLinksConfigList ($plan, 'makers', 'e10.persons.persons', 'plans-plans-makers', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($plan, 'makersGroups', 'e10.persons.groups', 'plans-plans-makers', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($plan, 'visibility', 'e10.persons.persons', 'plans-plans-visibility', $r ['ndx']);
			$cntPeoples += $this->docLinksConfigList ($plan, 'visibilityGroups', 'e10.persons.groups', 'plans-plans-visibility', $r ['ndx']);

			$plan['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			$plans [$r['ndx']] = $plan;
		}

		$cfg ['plans']['plans'] = $plans;
		file_put_contents(__APP_DIR__ . '/config/_plans.plans.json', utils::json_lint (json_encode ($cfg)));
	}

	function docLinksConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

  public function usersPlans($enabledCfgItem = '')
  {
    $allPlans = $this->app()->cfgItem('plans.plans', NULL);
		$plans = [];
		if ($allPlans === NULL)
			return $plans;

		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		foreach ($allPlans as $itemNdx => $i)
		{
			if ($enabledCfgItem !== '' && !($i[$enabledCfgItem] ?? 0))
				continue;

			$enabled = 0;
			if (!isset($i['allowAllUsers'])) $enabled = 1;
			elseif ($i['allowAllUsers']) $enabled = 1;
			elseif (isset($i['makers']) && in_array($userNdx, $i['makers'])) $enabled = 2;
			elseif (isset($i['makersGroups']) && count($userGroups) && count(array_intersect($userGroups, $i['makersGroups'])) !== 0) $enabled = 2;
			elseif (in_array($userNdx, $i['visibility'] ?? [])) $enabled = 1;
			elseif (count($userGroups) && count(array_intersect($userGroups, $i['visibityGroups'] ?? [])) !== 0) $enabled = 1;

			if (!$enabled)
				continue;

			$plans[$itemNdx] = $i;
			$plans[$itemNdx]['accessLevel'] = $enabled;
		}

    return $plans;
  }
}


/**
 * Class ViewPlans
 */
class ViewPlans extends TableView
{
	var $linkedPersons = [];

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

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
		{
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
		}
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

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
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
					$this->addList ('doclinksPersons', '', TableForm::loAddToFormLayout);
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('addToDashboardHome');
					$this->addColumnInput ('addToDashboardMnf');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('primaryViewType');
					$this->addColumnInput ('useViewDetail');
					$this->addColumnInput ('useViewCompact');
					$this->addColumnInput ('useViewStatesColors');
					$this->addColumnInput ('useViewTree');
					$this->addColumnInput ('useWorkOrders');
					$this->addColumnInput ('useCustomer');
					$this->addColumnInput ('useProjectId');
					$this->addColumnInput ('usePrice');
					$this->addColumnInput ('useText');
					$this->addColumnInput ('useTeams');

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

