<?php

namespace wkf\core\viewers;

use \e10\utils;


/**
 * Class IssuesBoardColumns
 * @package wkf\core\viewers
 */
class IssuesBoardColumns extends \wkf\core\viewers\DashboardIssuesCore
{
	var $boardNdx = 0;
	var $boardCfg = NULL;

	var $columnsInfo = [];

	var $columnsTargets = NULL;
	var $columnsProjects = NULL;


	//var $usedTargets = [];
	var $usedTargetsColumns = [];

	var $viewerGroups = [
		'targets' => ['cntColumns' => 6],
	//	'unassigned' => ['cntColumns' => 5, 'title' => ['text' => 'Nepřiřazeno', 'icon' => 'icon-flag-o', 'class' => 'e10-widget-big-text e10-me']],
	];

	var $viewerGroupsStatuses = [];
	var $useStatuses = 0;

	const cvmTargets = 1, cvmProjects = 2;

	public function init ()
	{
		parent::init();

		$this->usePanelRight = 0;
		$this->enableDetailSearch = TRUE;

		$this->boardNdx = $this->queryParam('board');
		$this->boardCfg = $this->app()->cfgItem ('wkf.issues.boards.'.$this->boardNdx, NULL);

		$this->initColumns();

		$this->selectParts = ['targets'/*, 'unassigned'*/];
	}

	function initColumns()
	{
		if ($this->boardCfg['columnsView'] === self::cvmTargets)
			$this->initColumns_Targets();
		elseif ($this->boardCfg['columnsView'] === self::cvmProjects)
			$this->initColumns_Projects();
	}

	function initColumns_Targets()
	{
		$q = [];
		array_push($q, 'SELECT targets.*');
		array_push($q, ' FROM [wkf_base_targets] AS [targets]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [targets].docState IN %in', [4000, 8000]);
		array_push($q, ' ORDER BY [targets].[order], [targets].[shortName]');

		$this->columnsTargets = [];
		$cntColumns = 0;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->columnsTargets[$r['ndx']] = $r->toArray();

			$ci = [];
			$ci['titleCode'] = utils::es($r['shortName']);
			$this->columnsInfo[] = $ci;
			$this->usedTargetsColumns[$r['ndx']] = $cntColumns;

			$cntColumns++;
		}
	}

	function initColumns_Projects()
	{
		$q = [];
		array_push($q, 'SELECT projects.*');
		array_push($q, ' FROM [wkf_base_projects] AS [projects]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [projects].docState IN %in', [4000, 8000]);
		array_push($q, ' ORDER BY [projects].[order], [projects].[shortName]');

		$this->columnsProjects = [];
		$cntColumns = 0;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->columnsProjects[$r['ndx']] = $r->toArray();

			$ci = [];
			$ci['titleCode'] = utils::es($r['shortName']);
			$this->columnsInfo[] = $ci;
			$this->usedTargetsColumns[$r['ndx']] = $cntColumns;

			$cntColumns++;
		}
	}

	function initPaneMode()
	{
		$this->setPaneMode(4, 28/*, $this->columnsInfo*/);
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

		if ($item['columnNdx'])
			$item['columnNumber'] = $this->usedTargetsColumns[$item['columnNdx']];
	}

	public function qrySelectRows (&$q, $selectPart, $selectPartNumber)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = $this->mainQueries[0]['id'];

		$q [] = 'SELECT issues.*, ';

		if ($selectPart)
			array_push ($q, ' %i', $selectPartNumber, ' AS selectPartOrder, %s', $selectPart, ' AS selectPart,');

		if ($this->boardCfg['columnsView'] === self::cvmTargets)
			array_push ($q, ' docLinksTargets.dstRecId AS columnNdx,');
		if ($this->boardCfg['columnsView'] === self::cvmProjects)
			array_push ($q, ' docLinksProjects.dstRecId AS columnNdx,');

		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName,');
		array_push ($q, ' statuses.[order] AS statusOrder');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_issuesStatuses AS [statuses] ON issues.status = statuses.ndx');


		if ($this->boardCfg['columnsView'] === self::cvmTargets)
			array_push ($q, ' RIGHT JOIN e10_base_doclinks AS [docLinksTargets] ON issues.ndx = docLinksTargets.srcRecId',
				' AND docLinksTargets.linkId = %s', 'wkf-issues-targets',
				' AND docLinksTargets.srcTableId = %s', 'wkf.core.issues',
				' AND docLinksTargets.dstTableId = %s', 'wkf.base.targets');
		elseif ($this->boardCfg['columnsView'] === self::cvmProjects)
			array_push ($q, ' RIGHT JOIN e10_base_doclinks AS [docLinksProjects] ON issues.ndx = docLinksProjects.srcRecId',
				' AND docLinksProjects.linkId = %s', 'wkf-issues-projects',
				' AND docLinksProjects.srcTableId = %s', 'wkf.core.issues',
				' AND docLinksProjects.dstTableId = %s', 'wkf.base.projects');

		array_push ($q, ' WHERE 1');

		$this->qrySection($q, $selectPart);

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'issues.[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR issues.[text] LIKE %s', '%'.$fts.'%');

			array_push ($q, ' OR EXISTS (',
				'SELECT persons.fullName FROM [e10_base_doclinks] AS docLinks, e10_persons_persons AS p',
				' WHERE issues.ndx = srcRecId AND srcTableId = %s', 'wkf.core.issues',
				' AND dstTableId = %s', 'e10.persons.persons', ' AND docLinks.dstRecId = p.ndx',
				' AND p.fullName LIKE %s)', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->qryDocState($q, $mqId, $fts, $selectPart);
		$this->qryClassification ($q);
		$this->qryOrder($q, $selectPart);
	}

	public function qrySection(&$q, $selectPart)
	{
		$allUsersSections = array_keys($this->usersSections['all']);

		if (count($allUsersSections))
			array_push ($q, ' AND issues.[section] IN %in', $allUsersSections);
		else
			array_push ($q, ' AND issues.[section] = %i', -1);
	}

	protected function qryOrder (&$q, $selectPart)
	{
		array_push ($q, ' ORDER BY [displayOrder]');
	}

	protected function qryOrderAll (&$q)
	{
		if ($this->useStatuses)
		{
			array_push($q, ' ORDER BY selectPartOrder, statusOrder DESC, [displayOrder]');
			return;
		}

		array_push ($q, ' ORDER BY columnNdx, [displayOrder]');
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
		$this->usePanelLeft = FALSE;
	}

	function checkViewerGroup (&$item)
	{
		if ($item['selectPart'] === 'targets')
		{
			$item['vgId'] = $item['selectPart'];
			$this->addViewerGroup($item['vgId'], $this->viewerGroups[$item['vgId']]);

			return;
		}

		// -- unassigned
		$vgId = 's'.$item['status'];
		$item['vgId'] = $vgId;
		$this->addViewerGroup($item['vgId'], $this->viewerGroupsStatuses[$item['vgId']]);
	}

	function addViewerGroup ($groupId, $groupDef)
	{
		if (!isset($this->objectData['viewerGroups']))
			$this->objectData['viewerGroups'] = [];
		if (isset ($this->objectData['viewerGroups'][$groupId]))
			return;

		$vg =  [
			'code' => (isset($groupDef['title'])) ? $this->app()->ui()->composeTextLine($groupDef['title']) : '',
			'cntColumns' => (isset($groupDef['cntColumns'])) ? $groupDef['cntColumns'] : 0,
		];

		if ($groupId === 'targets')
		{
			$chc = '';
			$columnClass = 'e10-vp-col-'.count($this->columnsInfo);
			foreach ($this->columnsInfo as $oci)
			{
				$chc .= "<div class='$columnClass' style='float: left; padding: 0 1ex; margin-bottom: -.5ex;'>";
				$chc .= "<div style='display: inline-block; width: 100%; text-align: center; background-color: #efefef; '>".$oci['titleCode'].'</div>';
				$chc .= '</div>';
			}

			$vg['columnsHeaderCode'] = $chc;
			$vg['cntColumns'] = count($this->columnsInfo);
		}

		$this->objectData['viewerGroups'][$groupId] = $vg;
	}
}

