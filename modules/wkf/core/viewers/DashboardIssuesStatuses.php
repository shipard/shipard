<?php

namespace wkf\core\viewers;


use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesStatuses
 * @package wkf\core\viewers
 */
class DashboardIssuesStatuses extends \wkf\core\viewers\DashboardIssuesCore
{
	var $columnsInfo = [];
	var $usedStatuses = [];
	var $usedTargetsColumns = [];

	public function init ()
	{
		parent::init();

		$this->usePanelRight = 0;
		$this->enableDetailSearch = TRUE;
		$this->rowsPageSize = 1000;
	}

	function initPaneMode()
	{
		$q [] = 'SELECT statuses.* ';
		array_push ($q, ' FROM [wkf_base_issuesStatuses] AS [statuses]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [section] = %i', $this->topSectionNdx);
		array_push ($q, ' ORDER BY [order], [ndx]');

		$cntColumns = 0;

		/*
		$ci = [];
		$ci['titleCode'] = utils::es('Nezařazeno');
		$this->columnsInfo[] = $ci;
		$this->usedStatuses[] = 0;
		$this->usedTargetsColumns[0] = $cntColumns;
		$cntColumns++;
		*/

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ci = [];
			$ci['titleCode'] = utils::es($r['shortName']);
			$this->columnsInfo[] = $ci;
			$this->usedStatuses[] = $r['ndx'];
			$this->usedTargetsColumns[$r['ndx']] = $cntColumns;
			$cntColumns++;
		}

		$this->setPaneMode($cntColumns, 28, $this->columnsInfo);
	}

	function initPanesOptions()
	{
		$this->viewerStyle = self::dvsPanesMicro;
		$this->withBody = FALSE;
		$this->paneClass = 'e10-pane e10-pane-mini';
		$this->simpleHeaders = TRUE;
	}

	function renderPane (&$item)
	{
		parent::renderPane($item);
		$item['columnNumber'] = $this->usedTargetsColumns[$item['status']];
	}

	public function qrySection(&$q, $selectPart)
	{
		$allUsersSections = array_keys($this->usersSections['all']);

		if (count($this->subSections))
			$ss = array_keys($this->subSections);
		else
			$ss = [$this->topSectionNdx];

		if (count($ss))
			array_push ($q, ' AND issues.[section] IN %in', $ss);
		else
			array_push ($q, ' AND issues.[section] = %i', -1);

		array_push ($q, ' AND [status] IN %in', $this->usedStatuses);
	}

	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar-fixed');
	}

	function createStaticContent()
	{
	}

	function initSubSections ()
	{
		if (!count($this->topSectionCfg['subSections']))
			return;

		foreach ($this->topSectionCfg['subSections'] as $ss)
		{
			if (!isset($this->usersSections['all'][$ss]))
				continue;
			$subSectionCfg = $this->usersSections['all'][$ss];
			$this->subSections[$ss] = $subSectionCfg['sn'];
		}

		$this->usePanelLeft = FALSE;
	}
}

