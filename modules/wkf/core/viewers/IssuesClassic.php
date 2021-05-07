<?php

namespace wkf\core\viewers;


use \e10\TableView, \e10\utils, \e10\TableViewPanel, \e10pro\wkf\TableMessages, \wkf\core\TableIssues;


/**
 * Class IssuesClassic
 * @package wkf\core\viewers
 */
class IssuesClassic extends TableView
{
	/** @var  \wkf\base\TableSections */
	var $tableSections;
	var $usersSections;
	var $issuesStatuses;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->enableDetailSearch)
		{
			$mq [] = ['id' => 'active', 'title' => 'K řešení', 'icon' => 'icon-bolt'];
			$mq [] = ['id' => 'done', 'title' => 'Hotovo', 'icon' => 'icon-check'];
			$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'icon-archive'];
			$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'icon-toggle-on'];
			if ($this->app()->hasRole('pwuser'))
				$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'icon-trash'];
			$this->setMainQueries($mq);
		}

		$this->tableSections = $this->app->table ('wkf.base.sections');
		$this->usersSections = $this->tableSections->usersSections();
		$this->issuesStatuses = $this->app->cfgItem ('wkf.issues.statuses.all');

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['t1'] = $item['subject'];

		$props = [];
		$props[] = ['text' => '#'.utils::nf($item['ndx']), 'class' => 'label label-default'];
		if (count($props))
			$listItem ['i2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		$mqId = $this->mainQueryId ();
		if ($mqId === '')
			$mqId = 'active';

		$q = [];
		array_push ($q, 'SELECT issues.*,');
		array_push ($q, ' persons.fullName AS authorFullName, ');
		array_push ($q, ' targets.shortName AS targetName');
		array_push ($q, ' FROM [wkf_core_issues] AS issues');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push ($q, ' LEFT JOIN wkf_base_targets AS [targets] ON issues.target = targets.ndx');
		array_push ($q, ' WHERE 1');

		$this->qrySection($q);

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, 'issues.[subject] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR issues.[text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}


		// -- fulltext & docState
		if ($fts !== '')
		{
			if ($mqId === 'active')
			{
				array_push($q, ' AND (issues.[docStateMain] IN %in', [1, 2, 5],
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					')');
			}
			elseif ($mqId === 'done')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
			elseif ($mqId === 'archive')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
			elseif ($mqId === 'trash')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 4);
		}
		else
		{
			if ($mqId === 'active')
			{
				array_push($q, ' AND (issues.[docStateMain] = %i', 1,
					' OR (issues.[docStateMain] = %i', 0, ' AND [author] IN %in', [0, $this->thisUserId], ')',
					' OR issues.[docState] = 8000',
					')');
			}
			elseif ($mqId === 'done')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 2);
			elseif ($mqId === 'archive')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 5);
			elseif ($mqId === 'trash')
				array_push($q, ' AND (issues.[docStateMain] = %i)', 4);
		}

		array_push ($q, ' ORDER BY [displayOrder]');
		array_push($q, $this->sqlLimit ());
		$this->runQuery ($q);
	}

	public function qrySection(&$q)
	{
		array_push ($q, ' AND issues.[section] IN %in', array_keys($this->usersSections['all']));
	}
}