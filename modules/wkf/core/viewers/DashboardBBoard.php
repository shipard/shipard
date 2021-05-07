<?php

namespace wkf\core\viewers;


use \e10\utils, \wkf\core\TableIssues;


/**
 * Class DashboardBBoard
 * @package wkf\core\viewers
 */
class DashboardBBoard extends \wkf\core\viewers\DashboardIssuesCore
{
	var $viewerGroups = [
		'bboard' => ['cntColumns' => 3, 'title' => ['text' => 'Nástěnka', 'icon' => 'icon-flag-o', 'class' => 'e10-widget-big-text e10-me']],
		'notifications' => ['cntColumns' => 0, 'title' => ['text' => 'Nové zprávy', 'icon' => 'icon-bullhorn', 'class' => 'e10-widget-big-text e10-me']],
	];

	public function init ()
	{
		parent::init();

		$this->usePanelRight = 0;
		$this->selectParts = ['bboard', 'notifications'];
	}

	function qryDocState(&$q, $mqId, $fts, $selectPart)
	{
		if ($selectPart === 'notifications')
		{
			if ($mqId === 'active')
			{
				array_push($q, ' AND (issues.[docStateMain] IN %in', [1, 2, 4, 5],
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					')');
			}
			elseif ($mqId === 'done')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
			elseif ($mqId === 'archive')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
			elseif ($mqId === 'trash')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 4);

			if ($mqId === 'all')
			{
				array_push($q, ' AND (issues.[docStateMain] != %i', 0,
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					')');
			}

			return;
		}

		parent::qryDocState($q, $mqId, $fts, $selectPart);
	}

	public function qrySection(&$q, $selectPart)
	{
		if ($selectPart === 'bboard')
		{
			array_push ($q, ' AND issues.[section] = %i', $this->topSectionNdx);

		}

		if ($selectPart === 'notifications')
		{
			$allUsersSections = array_keys($this->usersSections['all']);
			if (count($allUsersSections))
				array_push ($q, ' AND issues.[section] IN %in', $allUsersSections);
			else
				array_push ($q, ' AND issues.[section] = %i', -1);

			array_push ($q, ' AND EXISTS (SELECT n.ndx FROM [e10_base_notifications] AS n',
				' WHERE issues.ndx = n.recIdMain AND n.[tableId] = %s', 'wkf.core.issues',
				' AND n.personDest = %i', $this->thisUserId, 'AND n.[state] = %i', 0, ')');
		}
	}

	function checkViewerGroup (&$item)
	{
		$item['vgId'] = $item['selectPart'];
		$this->addViewerGroup($item['vgId'], $this->viewerGroups[$item['vgId']]);
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

		$this->objectData['viewerGroups'][$groupId] = $vg;
	}


	public function createTopMenuSearchCode ()
	{
		return $this->createCoreSearchCode('e10-sv-search-toolbar-fixed');
	}

	function createStaticContent()
	{
	}

	public function endMark ($blank)
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		if ($fts === '')
			return '';

		return parent::endMark($blank);
	}
}

