<?php

namespace plans\core\libs;

use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\World, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Viewer\TableViewPanel;



/**
 * class ViewItemsGrid
 */
class ViewItemsGrid extends TableViewGrid
{
	var $planNdx = 0;
	var $planCfg = NULL;
	var $itemStates = NULL;
	var $lifeCycleItemStates = NULL;
	var $useTableViewTabsMonths = 0;
	var $useWorkOrders = 0;
	var $useCustomer = 0;
	var $useProjectId = 0;
	var $lastGroupId = '';
	var $fixedMainQuery = NULL;

	var $workOrdersKinds = NULL;

	var $useViewDetail = 0;
	var $useViewCompact = 0;
	var $showColumnPrice = 0;

	var $showPrevItemInMonth = 1;

	var $annots = [];
	var $classification = [];
	var $linkedPersons = [];

	public function init ()
	{
		parent::init();

		$this->workOrdersKinds = $this->app()->cfgItem ('e10mnf.workOrders.kinds', NULL);

		$this->planNdx = intval($this->queryParam('plan'));
		if ($this->planNdx)
		{
			$this->addAddParam ('plan', $this->planNdx);
			$this->planCfg = $this->app()->cfgItem('plans.plans.'.$this->planNdx, NULL);
			if ($this->planCfg)
			{
				$this->useWorkOrders = $this->planCfg['useWorkOrders'] ?? 0;
				$this->useCustomer = $this->planCfg['useCustomer'] ?? 0;
				$this->useProjectId = $this->planCfg['useProjectId'] ?? 0;
				$this->useTableViewTabsMonths = $this->planCfg['useTableViewTabsMonths'] ?? 0;
				$this->useViewDetail = $this->planCfg['useViewDetail'] ?? 0;
				$this->useViewCompact = $this->planCfg['useViewCompact'] ?? 0;
				$this->showColumnPrice = $this->planCfg['usePrice'] ?? 0;;
			}
		}

		$this->itemStates = $this->app()->cfgItem('plans.itemStates', []);
		$this->lifeCycleItemStates = $this->app()->cfgItem('plans.lifeCycleItemStates', []);

		$this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;

		if ($this->useViewDetail)
		{
			$this->objectSubType = self::vsMain;
			$this->linesWidth = 65;
			$this->setPanels (self::sptQuery);
		}
		else
		{
			$this->objectSubType = self::vsDetail;
		}

		//$this->createBottomTabs();

		$g = [
			'iid' => 'ID',
		];

		if ($this->useViewCompact)
		{
			if ($this->useProjectId)
				$g['prjId'] = 'Ev.č.';

			$g['subject'] = 'Věc';
			$g['begin'] = 'Zahájení';
			$g['deadline'] = 'Termín';
			if ($this->showColumnPrice)
			{
				$g['price'] = ' Cena';
				$g['currency'] = 'Měna';
			}
			//$g['note'] = 'Pozn.';
		}
		else
		{
			if ($this->useWorkOrders)
			{
				$g['woParent'] = 'Zakázka';
				$g['wo'] = 'VP';
			}

			if ($this->useProjectId)
				$g['prjId'] = 'Ev.č.';

			$g['subject'] = 'Název';
			if ($this->useCustomer)
				$g['personCust'] = 'Zákazník';
			$g['begin'] = 'Zahájení';
			$g['deadline'] = 'Termín';
			if ($this->showColumnPrice)
			{
				$g['price'] = ' Cena';
				$g['currency'] = 'Měna';
			}
			$g['note'] = 'Pozn.';
		}

		$this->setGrid ($g);

		if (!$this->fixedMainQuery)
			$this->setMainQueries ();
	}

	public function createBottomTabs ()
	{
		$thisYear = 2023;
		$thisMonth = 4;

		$anyActiveTab = 0;
		$bt = [];

		$nbt = ['id' => '!', 'title' => '☆','active' => 0,];
		$bt [] = $nbt;
		$nbt = ['id' => '', 'title' => '★','active' => 0,];
		$bt [] = $nbt;

		if ($this->useTableViewTabsMonths)
		{
			/*
			for ($yearIndex = -1; $yearIndex < 2; $yearIndex++)
			{
				$year = $thisYear + $yearIndex;
				$nbt = [
					'id' => 'Y'.$year, 'title' => strval($year),
					'active' => 0,
				];
				$bt [] = $nbt;
				for ($month = $startMonths[$yearIndex]; $month <= $stopMonths[$yearIndex]; $month++)
				{
					$isActive = ($thisMonth === $month && $thisYear === $year);
					$nbt = [
						'id' => $year.$month, 'title' => ($thisYear === $year) ? Utils::$monthNames[$month-1] : Utils::$monthSc3[$month-1].($year-2000),
						'active' => $isActive,
					];
					$bt [] = $nbt;

					if ($isActive)
						$anyActiveTab = 1;
				}
			}
			*/

			$cntMonths = 0;
			$year = $thisYear;
			$month = $thisMonth;

			while(1)
			{
				$isActive = ($thisMonth === $month && $thisYear === $year);
				$nbt = [
					'id' => $year.$month, 'title' => ($thisYear === $year) ? Utils::$monthNames[$month-1] : Utils::$monthSc3[$month-1].($year-2000),
					'active' => $isActive,
				];
				$bt [] = $nbt;

				if ($isActive)
					$anyActiveTab = 1;

				$month++;
				if ($month === 13)
				{
					$month = 1;
					$year++;
				}

				$cntMonths++;
				if ($cntMonths > 5)
					break;
			}
		}

		if (!$anyActiveTab && isset($bt[0]))
			$bt[0]['active'] = 1;

		$this->setBottomTabs ($bt);
	}

	public function renderRow ($item)
	{
		$itemState = $this->itemStates[$item['itemState']] ?? NULL;

		$listItem ['pk'] = $item ['ndx'];

		$listItem ['iid'] = $item['iid'];
		$listItem ['prjId'] = $item['projectId'];

		$wok = NULL;
		if ($this->workOrdersKinds)
			$wok = $this->workOrdersKinds[$item['woDocKind'] ?? 1];

		if ($this->useViewCompact)
		{
			$subj = [];
			if ($item['isPrivate'])
				$subj[] = ['text' => $item['subject'], 'icon' => 'system/iconLocked', 'class' => 'e10-me e10-bold  block'];
			else
				$subj[] = ['text' => $item['subject'], '_icon' => 'system/iconLocked', 'class' => 'e10-bold block'];

			if ($item['note'] !== '')
				$subj[] = ['text' => $item['note'], 'class' => 'block e10-off'];

			if ($item['workOrder'])
				$subj[] = [
					'docAction' => 'show',
					'text' => match($wok['viewerLabelTitle'] ?? 0) {1 => $item['woRefId2'], 2 => $item['woIntTitle'], default => $item['woDocNumber']},
					'table' => 'e10mnf.core.workOrders', 'pk' => $item['workOrder'], 'class' => 'label label-info', 'icon' => 'tables/e10mnf.core.workOrders'
				];

			$listItem ['subject'] = $subj;


			$listItem ['begin'] = $item['datePlanBegin'];
			$listItem ['deadline'] = $item['dateDeadline'];
		}
		else
		{
			if ($this->useWorkOrders)
			{
				if ($item['workOrder'])
					$listItem ['wo'] = ['docAction' => 'show', 'text' => $item['woDocNumber'], 'table' => 'e10mnf.core.workOrders', 'pk' => $item['workOrder']];

				if ($item['workOrderParent'])
					$listItem ['woParent'] = ['docAction' => 'edit', 'text' => $item['woParentDocNumber'], 'table' => 'e10mnf.core.workOrders', 'pk' => $item['workOrderParent']];
			}

			if ($item['personCustomer'])
				$listItem ['personCust'] = $item['personCustName'];

			$listItem ['subject'] = $item['isPrivate'] ? [['text' => $item['subject'], 'class' => ''], ['text' => '', 'icon' => 'system/iconLocked', 'class' => 'e10-me']] : $item['subject'];


			if ($item['note'] !== '')
				$listItem ['note'] = [['text' => $item['note'], 'class' => 'block']];

			$listItem ['begin'] = $item['datePlanBegin'];
			$listItem ['deadline'] = $item['dateDeadline'];

			$listItem ['price'] = $item['price'];
			$curr = World::currency($this->app(), $item ['currency']);
			$listItem ['currency'] = strtoupper($curr['i']);

			if ($itemState)
			{
				$listItem ['icon'] = $itemState['icon'] ?? '';//$this->table->tableIcon ($item);

				$css = "background-color: ".$itemState['colorbg'].'; color: '.$itemState['colorfg'];
				$listItem['_options']['cellCss'] = ['subject' => $css];
			}

			if ($this->useTableViewTabsMonths)
			{
				/*
				if (!Utils::dateIsBlank($item['dateDeadline']))
					$groupId = $item['dateDeadline']->format('Y-m');
				else
					$groupId = '---';
				if ($this->lastGroupId !== $groupId)
				{
					$headerTitle = '__!!!!___';
					if (!Utils::dateIsBlank($item['dateDeadline']))
						$headerTitle.= $item['dateDeadline']->format('Y-m');
					$this->addGroupHeader ($headerTitle);
					$this->lastGroupId = $groupId;
				}
				*/
			}

			$listItem['_options']['cellCss']['note'] = 'line-height: 1.5;';
		}

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->annots [$item ['pk']]))
		{
			if (!isset($item ['note']))
				$item ['note'] = [];
			else
				$item ['note'][0]['class'] .= ' pb1';

			foreach ($this->annots [$item ['pk']] as $a)
				$item ['note'][] = $a['label'];
		}

		if (isset ($this->classification [$item ['pk']]))
		{
			if ($this->useViewCompact)
			{
				forEach ($this->classification [$item ['pk']] as $clsfGroup)
					$item ['subject'] = array_merge ($item ['subject'], $clsfGroup);
			}
			else
			{
				if (!isset($item ['note']))
					$item ['note'] = [];
				else
					$item ['note'][0]['class'] .= ' pb1';

				forEach ($this->classification [$item ['pk']] as $clsfGroup)
					$item ['note'] = array_merge ($item ['note'], $clsfGroup);
			}
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId();

		$q = [];
		array_push ($q, ' SELECT [items].*');
		array_push ($q, ', [personsCust].fullName AS [personCustName]');
		if ($this->useWorkOrders)
		{
			array_push ($q, ', wo.docKind AS woDocKind, [wo].docNumber AS [woDocNumber], [wo].intTitle AS [woIntTitle], [wo].refId2 AS [woRefId2]');
			array_push ($q, ', [woParent].docNumber AS [woParentDocNumber]');
		}
		array_push ($q, ' FROM [plans_core_items] AS [items]');

		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS [personsCust] ON [items].[personCustomer] = [personsCust].ndx');

		if ($this->useWorkOrders)
		{
			array_push ($q, ' LEFT JOIN [e10mnf_core_workOrders] AS [wo] ON [items].[workOrder] = [wo].ndx');
			array_push ($q, ' LEFT JOIN [e10mnf_core_workOrders] AS [woParent] ON [items].[workOrderParent] = [woParent].ndx');
		}
		array_push ($q, ' WHERE 1');

		if ($this->planNdx)
			array_push ($q, ' AND [plan] = %i', $this->planNdx);

		/*
		if ($bottomTabId !== '')
		{
			$dateBegin = '';
			$dateEnd = '';
			$showInProgressOnly = 0;
			if ($bottomTabId === '!')
			{
				$showInProgressOnly = 1;
			}
			elseif ($bottomTabId[0] === 'Y')
			{
				$year = intval(substr($bottomTabId, 1));
				if ($year)
				{
					$dateBegin = $year.'-01-01';
					$dateEnd = $year.'-12-31';
					//array_push ($q, ' AND ([items].[dateDeadline] >= %d', $dateBegin, ' AND [items].[dateDeadline] <= %d)', $dateEnd);
				}
			}
			else
			{
				$year = intval(substr($bottomTabId, 0, 4));
				$month = intval(substr($bottomTabId, 4));
				$dateBegin = $year.sprintf('-%02d-01', $month);
				$dateBeginDate = Utils::createDateTime($dateBegin);
				$dateEnd = $dateBeginDate->format('Y-m-t');
				//array_push ($q, ' AND ([items].[dateDeadline] >= %d', $dateBegin, ' AND [items].[dateDeadline] <= %d)', $dateEnd);
			}

			if ($showInProgressOnly)
			{
				$inProgressIS = array_merge($this->lifeCycleItemStates[20] ?? [], $this->lifeCycleItemStates[10] ?? []);

				if (count($inProgressIS))
					array_push ($q, ' AND [itemState] IN %in', $inProgressIS);
				if ($dateBegin !== '')
					array_push ($q, ' AND [items].[dateDeadline] >= %d', $dateBegin);
				if ($dateEnd !== '')
					array_push ($q, ' AND [items].[dateDeadline] <= %d', $dateEnd);
			}
			else
			{
					//array_push ($q, ' AND ([items].[dateDeadline] >= %d', $dateBegin, ' AND [items].[dateDeadline] <= %d)', $dateEnd);
					array_push ($q, ' AND ([items].[dateDeadline] >= %d', $dateBegin, ' OR [itemState] != %i)', 2);
					array_push ($q, ' AND [items].[dateDeadline] <= %d', $dateEnd);
			}
		}
		elseif ($this->fixedMainQuery === 'active')
		{
			$inProgressIS = array_merge($this->lifeCycleItemStates[20] ?? [], $this->lifeCycleItemStates[10] ?? []);

			if (count($inProgressIS))
				array_push ($q, ' AND [itemState] IN %in', $inProgressIS);
		}
		*/

		// -- public/private item
		$thisUserId = $this->app()->userNdx();
		array_push ($q, ' AND (');
		array_push ($q, ' [items].isPrivate = %i', 0);
		array_push ($q, ' OR ([items].isPrivate = %i', 1);
		array_push ($q, ' AND EXISTS (SELECT ndx FROM [e10_base_doclinks] WHERE [items].ndx = srcRecId',
			' AND srcTableId = %s','plans.core.items',
			' AND dstTableId = %s', 'e10.persons.persons',
			' AND dstRecId = %i', $thisUserId, ')');
		array_push ($q, ')');
		array_push ($q, ')');

		// -- special queries
		$qv = $this->queryValues ();
		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE items.ndx = recid AND tableId = %s', $this->table->tableId());
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [items].[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [projectId] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [note] LIKE %s', '%'.$fts.'%');

			if ($this->useWorkOrders)
			{
				array_push ($q, ' OR [wo].[docNumber] LIKE %s', '%'.$fts.'%');
				array_push ($q, ' OR [woParent].[docNumber] LIKE %s', '%'.$fts.'%');
			}

			array_push ($q, ')');
		}

		$this->queryMain ($q, 'items.', ['!ISNULL([dateDeadline]) DESC', '[dateDeadline]', '[datePlanBegin]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		// -- annots
		$q = [];
		array_push($q,'SELECT annots.*, kinds.shortName AS kindFullName');
		array_push($q,' FROM [e10pro_kb_annots] AS [annots]');
		array_push($q,' LEFT JOIN [e10pro_kb_annotsKinds] AS [kinds] ON annots.annotKind = kinds.ndx');
		array_push($q,' WHERE');
		array_push($q, '(annots.docTableNdx = %i', $this->table->ndx, ' AND annots.docRecNdx IN %in)', $this->pks);
		array_push($q, ' ORDER BY annots.[order], annots.title');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['title' => $r['title'], 'url' => $r['url']];

			if ($item['title'] === '' && $r['kindFullName'] && $r['kindFullName'] !== '')
				$item['title'] = $r['kindFullName'];

			if ($r['perex'] != '')
				$item['perex'] = $r['perex'];

			$label = ['text' => $r['title'], 'class' => 'e10-tag', 'icon' => 'system/iconLink'];
			if ($r['url'] !== '')
				$label['url'] = $r['url'];

			$item['label'] = $label;

			$this->annots[$r['docRecNdx']][] = $item;
		}

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}
