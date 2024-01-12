<?php

namespace plans\core\libs;

use DateTime;
use \Shipard\Viewer\TableViewGrid, \Shipard\Utils\World, \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class ViewItemsGantt
 */
class ViewItemsGantt extends TableViewGrid
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

	var $showPrevItemInMonth = 1;

  var $dateFirst = NULL;
  var $dateLast = NULL;
  var $firstDay = NULL;

	var $annots = [];
	var $classification = [];

	public function init ()
	{
		parent::init();

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
			}
		}

		$this->itemStates = $this->app()->cfgItem('plans.itemStates', []);
		$this->lifeCycleItemStates = $this->app()->cfgItem('plans.lifeCycleItemStates', []);

		$this->enableDetailSearch = TRUE;
    $this->type = 'form';

    $this->fullWidthToolbar = TRUE;
		$this->gridEditable = TRUE;
		$this->enableToolbar = TRUE;
    $this->objectSubType = self::vsDetail;

    $this->getFirstLastDate();

		$g = [];

		$g['subject'] = 'Název';



		$weekDate = clone $this->firstDay;
    while (1)
    {
      $weekYear = intval($weekDate->format('o'));
      $weekYearShort = $weekYear - 2000;
      $weekNumber = intval($weekDate->format('W'));

      $colId = 'W-'.$weekYear.'-'.sprintf('%02d', $weekNumber);
      $colTitle = [
        ['text' => $weekNumber/*.'/'.$weekYearShort*/, 'class' => 'e10-small block', 'css' => 'text-align: center;'],
        ['text' => $weekDate->format('d.m'), 'class' => 'id', 'css' => 'font-weight: normal;']
      ];

      $g[$colId] = $colTitle;
      $weekDate->add (new \DateInterval('P7D'));

      if ($weekDate > $this->dateLast)
        break;
    }

		$this->setGrid ($g);

		//$this->setMainQueries ();
	}

  protected function getFirstLastDate()
  {
    $inProgressIS = array_merge($this->lifeCycleItemStates[20] ?? [], $this->lifeCycleItemStates[10] ?? []);
		$q = [];
		array_push ($q, ' SELECT MIN([items].[datePlanBegin]) AS [dateFirst], MAX([items].[dateDeadline]) AS [dateLast]');
		array_push ($q, ' FROM [plans_core_items] AS [items]');
		array_push ($q, ' WHERE 1');

		if ($this->planNdx)
			array_push ($q, ' AND [plan] = %i', $this->planNdx);

    array_push ($q, ' AND [datePlanBegin] IS NOT NULL');
    array_push ($q, ' AND [dateDeadline] IS NOT NULL');

    $data = $this->db()->query($q)->fetch();
    if ($data)
    {
      $this->dateFirst = $data['dateFirst'];
      $this->dateLast = $data['dateLast'];

      $today = Utils::today();
      if ($today > $this->dateFirst)
        $this->dateFirst = $today;

      $this->firstDay = new DateTime($this->dateFirst->format('Y-m-d'));
      $this->firstDay->modify(('Monday' === $this->firstDay->format('l')) ? 'monday this week' : 'last monday');
    }
  }

	public function renderRow ($item)
	{
		$itemState = $this->itemStates[$item['itemState']] ?? NULL;

		$listItem ['pk'] = $item ['ndx'];

		$listItem ['subject'] = $item['isPrivate'] ? [['text' => $item['subject'], 'class' => ''], ['text' => '', 'icon' => 'system/iconLocked', 'class' => 'e10-me']] : $item['subject'];

		$listItem ['iid'] = $item['iid'];
		$listItem ['prjId'] = $item['projectId'];

		if ($item['note'] !== '')
			$listItem ['note'] = [['text' => $item['note'], 'class' => 'block']];

		$listItem ['begin'] = $item['datePlanBegin'];
		$listItem ['deadline'] = $item['dateDeadline'];

		$listItem ['price'] = $item['price'];
		$curr = World::currency($this->app(), $item ['currency']);
		$listItem ['currency'] = strtoupper($curr['i']);

		$listItem ['icon'] = $itemState['icon'];//$this->table->tableIcon ($item);

		if ($itemState)
		{
			$css = "background-color: ".$itemState['colorbg'].'; color: '.$itemState['colorfg'];
			$listItem['_options']['cellCss'] = ['subject' => $css];
		}


    $weekYearBegin = intval($item['datePlanBegin']->format('o'));
    $weekNumberBegin = intval($item['datePlanBegin']->format('W'));
    $colIdBegin = 'W-'.$weekYearBegin.'-'.sprintf('%02d', $weekNumberBegin);

    $weekYearEnd = intval($item['dateDeadline']->format('o'));
    $weekNumberEnd = intval($item['dateDeadline']->format('W'));
    $colIdEnd = 'W-'.$weekYearEnd.'-'.sprintf('%02d', $weekNumberEnd);

    $colSpanCnt = 0;
    $colSpanCol = '';
    foreach ($this->gridStruct as $gridColId => $gridColInfo)
    {
      if ($gridColId[0] !== 'W')
        continue;
      if ($gridColId < $colIdBegin)
        continue;
      if ($gridColId > $colIdEnd)
        break;

      $listItem['_options']['cellCss'][$gridColId] = 'background-color: #445566B0;';
      if ($colSpanCol === '')
        $colSpanCol = $gridColId;
      $colSpanCnt++;
    }

    if ($colSpanCnt > 1)
      $listItem['_options']['colSpan'][$colSpanCol] = $colSpanCnt;

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
			if (!isset($item ['note']))
				$item ['note'] = [];
			else
				$item ['note'][0]['class'] .= ' pb1';

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['note'] = array_merge ($item ['note'], $clsfGroup);
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
			array_push ($q, ', [wo].docNumber AS [woDocNumber]');
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

    array_push ($q, ' AND [datePlanBegin] IS NOT NULL');
    array_push ($q, ' AND [dateDeadline] IS NOT NULL');
		array_push ($q, ' AND [items].[docState] IN %in', [1000, 4000, 8000]);

		$inProgressIS = array_merge($this->lifeCycleItemStates[20] ?? [], $this->lifeCycleItemStates[10] ?? []);
		if (count($inProgressIS))
			array_push ($q, ' AND [itemState] IN %in', $inProgressIS);

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

    array_push ($q, 'ORDER BY items.[datePlanBegin], items.[dateDeadline], items.[ndx]');
    array_push ($q, $this->sqlLimit());

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
	}
}
