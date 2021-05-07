<?php

namespace wkf\core\viewers;

use \e10\TableView, \e10\utils, \wkf\core\TableIssues;


/**
 * Class DashboardIssuesTargets
 * @package wkf\core\viewers
 */
class DashboardIssuesTargets extends \wkf\core\viewers\DashboardIssuesCore
{
	var $columnsInfo = [];
	var $usedTargets = [];
	var $usedTargetsColumns = [];

	var $viewerGroups = [
		'targets' => ['cntColumns' => 6],
		'unassigned' => ['cntColumns' => 5, 'title' => ['text' => 'Nepřiřazeno', 'icon' => 'icon-flag-o', 'class' => 'e10-widget-big-text e10-me']],
	];

	var $viewerGroupsStatuses = [];
	var $useStatuses = 0;

	public function init ()
	{
		parent::init();

		$this->usePanelRight = 0;
		$this->enableDetailSearch = TRUE;

		$this->selectParts = ['targets', 'unassigned'];

		$statuses = $this->app()->cfgItem ('wkf.issues.statuses.section.'.$this->topSectionNdx, []);
		foreach ($statuses as $statusNdx)
		{
			$sid = 's'.$statusNdx;
			$statusCfg = $this->issuesStatuses[$statusNdx];
			$sg = ['cntColumns' => 5, 'title' => ['text' => $statusCfg['fn'], 'icon' => 'icon-flag-o', 'class' => 'e10-widget-big-text e10-me']];
			$this->viewerGroupsStatuses[$sid] = $sg;
		}

		if (count($this->viewerGroupsStatuses))
			$this->useStatuses = 1;
	}

	function initPaneMode()
	{
		$q [] = 'SELECT targets.* ';
		array_push ($q, ' FROM [wkf_base_targets] AS [targets]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [section] = %i', $this->topSectionNdx);
		array_push ($q, ' ORDER BY [order], [ndx]');

		$cntColumns = 0;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ci = [];
			$ci['titleCode'] = utils::es($r['shortName']);
			$this->columnsInfo[] = $ci;
			$this->usedTargets[] = $r['ndx'];
			$this->usedTargetsColumns[$r['ndx']] = $cntColumns;
			$cntColumns++;
		}

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

		if ($item['target'])
			$item['columnNumber'] = $this->usedTargetsColumns[$item['target']];
	}

	public function qrySection(&$q, $selectPart)
	{
		$allUsersSections = array_keys($this->usersSections['all']);

		if ($this->subSections && count($this->subSections))
			$ss = array_keys($this->subSections);
		else
			$ss = [$this->topSectionNdx];

		if (count($ss))
			array_push ($q, ' AND issues.[section] IN %in', $ss);
		else
			array_push ($q, ' AND issues.[section] = %i', -1);

		if ($selectPart === 'targets')
			array_push ($q, ' AND [target] IN %in', $this->usedTargets);
		else
			array_push ($q, ' AND [target] = %i', 0);
	}

	protected function qryOrder (&$q, $selectPart)
	{
		if ($selectPart === 'unassigned' && $this->useStatuses)
		{
			array_push ($q, ' ORDER BY statuses.[order] DESC, issues.[displayOrder]');
			return;
		}
		array_push ($q, ' ORDER BY [displayOrder]');
	}

	protected function qryOrderAll (&$q)
	{
		if ($this->useStatuses)
		{
			array_push($q, ' ORDER BY selectPartOrder, statusOrder DESC, [displayOrder]');
			return;
		}

		array_push ($q, ' ORDER BY [displayOrder]');
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

	function checkViewerGroup (&$item)
	{
		if ($item['selectPart'] === 'targets' || !$this->useStatuses)
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

