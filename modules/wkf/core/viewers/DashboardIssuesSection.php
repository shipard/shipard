<?php

namespace wkf\core\viewers;
use \e10\utils;
use translation\dicts\e10\base\system\DictSystem;


/**
 * Class DashboardIssuesSection
 * @package wkf\core\viewers
 */
class DashboardIssuesSection extends \wkf\core\viewers\DashboardIssuesCore
{
	var $viewerGroups = [
		'bboard' => ['cntColumns' => 2, 'title' => ['text' => 'Nástěnka', 'icon' => 'icon-thumb-tack', 'class' => 'h2 e10-me']],
		'unread' => ['cntColumns' => 0, 'title' => [['text' => 'Nepřečtené', 'icon' => 'icon-eye-slash', 'class' => 'h2 e10-me']]],
		'concept' => ['cntColumns' => 0, 'title' => ['text' => 'Novinky a koncepty', 'icon' => 'icon-pencil-square', 'class' => 'h2 e10-me']],
		'important' => ['cntColumns' => 0, 'title' => ['text' => 'Důležité', 'icon' => 'icon-bolt', 'class' => 'h2 e10-me']],
		'marked' => ['cntColumns' => 0, 'title' => ['text' => 'Označené', 'icon' => 'icon-star', 'class' => 'h2 e10-me']],
		'other' => ['cntColumns' => 0, 'title' => ['text' => 'K řešení', 'icon' => 'icon-check', 'class' => 'h2 e10-me']],
	];

	public function init ()
	{
		parent::init();

		//if ($this->viewerStyle !== self::dvsViewer)
			$this->usePanelRight = 1;
		$this->panesColumns = 1;

		$mqId = $this->mainQueryId ();
		if ($mqId === 'dashboard' || $mqId === '')
			$this->selectParts = ['bboard', 'unread',  'important', 'concept', 'marked', 'other'];
	}

	function initMainQueries()
	{
		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'dashboard', 'title' => 'Přehled', 'icon' => 'icon-dashboard'];
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'icon-bolt'];
			$mq [] = ['id' => 'done', 'title' => 'Hotovo', 'icon' => 'icon-check'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'icon-archive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'icon-toggle-on'];
			if ($this->app()->hasRole('pwuser'))
				$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'icon-trash'];
			$this->setMainQueries($mq);
		}
	}

	public function qrySection(&$q, $selectPart)
	{
		if ($this->treeMode)
		{
			if ($this->sectionNdx === 0)
			{
				$es = array_keys($this->usersSections['all']);
				if (count($es))
					array_push ($q, ' AND issues.[section] IN %in', $es);
				else
					array_push ($q, ' AND 0');
			}
			else
			if (isset($this->usersSections['top'][$this->sectionNdx]))
			{
				if (isset($this->usersSections['top'][$this->sectionNdx]['ess']))
					$es = $this->usersSections['top'][$this->sectionNdx]['ess'];
				else
					$es = [$this->sectionNdx];
				array_push ($q, ' AND issues.[section] IN %in', $es);
			}
			else
				array_push ($q, ' AND issues.[section] = %i', $this->sectionNdx);
		}
		else
			array_push ($q, ' AND issues.[section] = %i', $this->sectionNdx);

		if (!$this->selectParts || $selectPart === 'concept')
			return;

		if ($selectPart === 'unread')
		{
			if (count($this->notifications))
				array_push($q, ' AND [issues].ndx IN %in', array_keys($this->notifications));
			else
				array_push($q, ' AND 0');

			return;
		}

		if ($selectPart === 'bboard')
		{
			array_push($q, ' AND issues.[onTop] != %i', 0);
			return;
		}

		if ($selectPart === 'important')
		{
			array_push($q, ' AND issues.[priority] < %i', 10);
			array_push($q, ' AND issues.[onTop] = %i', 0); // not on bboard
			return;
		}

		if ($selectPart === 'marked')
		{
			array_push($q, ' AND EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
				' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $this->thisUserId, ')');
			array_push($q, ' AND issues.[onTop] = %i', 0);
			return;
		}

		array_push($q, ' AND issues.[onTop] = %i', 0); // not bboard
		array_push($q, ' AND issues.[priority] >= %i', 10); // not important

		$mqId = $this->mainQueryId ();
		if ($mqId === 'dashboard')
		{
			if (count($this->notifications)) // not unread
				array_push($q, ' AND [issues].ndx NOT IN %in', array_keys($this->notifications));
		}

		array_push($q, ' AND NOT EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
			' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $this->thisUserId, ')');
	}

	function checkViewerGroup (&$item)
	{
		if (!$this->selectParts)
			return;

		$item['vgId'] = $item['selectPart'];
		$this->addViewerGroup($item['vgId'], $this->viewerGroups[$item['vgId']]);
	}

	function addViewerGroup ($groupId, $groupDef)
	{
		if (!$this->selectParts)
			return;

		if (!isset($this->objectData['viewerGroups']))
			$this->objectData['viewerGroups'] = [];
		if (isset ($this->objectData['viewerGroups'][$groupId]))
			return;

		$titleCode = '';
		if ($groupId === 'unread')
		{
			$t = $groupDef['title'];

			$unreadButton = [
				'text' => DictSystem::text(DictSystem::diBtn_Seen),

				'action' => 'viewer-inline-action',
				'class' => 'pull-right',

				'icon' => 'icon-eye',
				'btnClass' => 'btn-xs btn-primary',
				'actionClass' => 'df2-action-trigger',
				'data-object-class-id' => 'wkf.core.libs.IssuesBulkAction',
				'data-action-type' => 'markAsUnread',
				'data-action-param-section' => $this->sectionNdx,
				'data-pk' => implode(',', array_keys($this->notifications)),

				'dropRight' => 1, 'dropdownMenu' => []
				];

			$variants = [
				'done' => ['t' => 'Jen vyřešené', 'i' => 'icon-check-square'],
				'archive' => ['t' => 'Jen ukončené', 'i' => 'icon-thumbs-down'],
			];

			foreach ($variants as $variantId => $variant)
			{
				$unreadButton['dropdownMenu'][] = [
					'text' => $variant['t'],

					'action' => 'viewer-inline-action',
					'class' => 'pull-right',

					'icon' => $variant['i'],
					'btnClass' => 'btn-xs btn-primary',
					'actionClass' => 'df2-action-trigger',
					'data-object-class-id' => 'wkf.core.libs.IssuesBulkAction',
					'data-action-type' => 'markAsUnread_'.$variantId,
					'data-action-param-section' => $this->sectionNdx,
					'data-pk' => implode(',', array_keys($this->notifications)),
				];

			}

			$t[] = $unreadButton;

			$titleCode = $this->app()->ui()->composeTextLine($t);
		}
		else
			$titleCode = (isset($groupDef['title'])) ? $this->app()->ui()->composeTextLine($groupDef['title']) : '';

		$this->objectData['viewerGroups'][$groupId] = [
			'code' => $titleCode,
			'cntColumns' => (isset($groupDef['cntColumns'])) ? $groupDef['cntColumns'] : 0,
		];
	}
}
