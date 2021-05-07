<?php

namespace lib\wkf;


use \E10Pro\Wkf\TableMessages;


/**
 * Class ViewerDashboardSearch
 * @package lib\wkf
 */
class ViewerDashboardSearch extends \lib\wkf\ViewerDashboardCore
{
	public function init ()
	{
		$this->usePanelLeft = FALSE;
		$this->usePanelRight = 1;
		$this->hasProjectsFilter = FALSE;
		$this->msgTypes = [TableMessages::mtIssue, TableMessages::mtActivity, TableMessages::mtBBoard, TableMessages::mtInbox];
		$this->enableDetailSearch = TRUE;

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->enableDetailSearch ? $this->fullTextSearch () : '';
		if ($fts === '')
		{
			$q [] = 'SELECT messages.ndx ';

			array_push ($q, ' FROM [e10pro_wkf_messages] as messages');

			array_push ($q, ' WHERE 1 = 0');
			$this->runQuery ($q);
			return;
		}

		parent::selectRows();
	}

	public function qryMessageTypes (&$q, $selectPart)
	{
		$mqId = $this->mainQueryId ();

		array_push ($q, ' AND (');

		array_push ($q, '(', 'messages.[msgType] IN %in', $this->msgTypes);

		if ($this->activeProjectPartNdx !== FALSE && $this->activeProjectPartNdx > 0)
		{
			array_push($q, ' AND (messages.[docStateMain] >= 1)');
		}
		else
		{
			$fts = $this->fullTextSearch ();
			if ($fts !== '')
			{
				if ($mqId === 'active')
					array_push($q, ' AND (messages.[docStateMain] IN %in)', [1, 2, 5]);
				elseif ($mqId === 'done')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 2);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 4);
			}
			else
			{
				if ($mqId === 'active')
					array_push($q, ' AND (messages.[docStateMain] = %i OR messages.[docState] = 8000)', 1);
				elseif ($mqId === 'done')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 2);
				elseif ($mqId === 'archive')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 5);
				elseif ($mqId === 'trash')
					array_push($q, ' AND (messages.[docStateMain] = %i)', 4);

			}
		}

		array_push ($q, ')');

		if ($mqId === 'active')
		{
			array_push($q, ' OR (',
				'messages.[msgType] IN %in', $this->msgTypes,
				' AND messages.author = %i', $this->thisUserId, ' AND messages.docStateMain = 0',
				')');
		}
		array_push ($q, ')');
	}

	protected function qryOrder (&$q, $selectPart)
	{
		array_push ($q, ' ORDER BY [displayOrder]');
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
