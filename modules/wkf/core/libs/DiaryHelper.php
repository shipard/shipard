<?php


namespace wkf\core\libs;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use e10\Utility, e10\utils, wkf\core\TableIssues;


/**
 * Class DiaryHelper
 * @package wkf\core\libs
 */
class DiaryHelper extends Utility
{
	var $srcTableNdx = 0;
	var $srcRecNdx = 0;
	var $thisUserId = 0;

	var $pinnedIssues = [];

	/** @var \lib\core\texts\Renderer */
	var $textRenderer = NULL;

	/** @var \wkf\core\TableIssues */
	var $tableIssues;

	public function init()
	{
		$this->thisUserId = $this->app()->userNdx();
		$this->tableIssues = $this->app()->table('wkf.core.issues');
	}

	public function pinnedContent ($tableNdx, $recNdx, &$content)
	{
		$this->srcTableNdx = $tableNdx;
		$this->srcRecNdx = $recNdx;
		$this->loadPinnedContent();
		foreach ($this->pinnedIssues as $pi)
		{
			$codeTitle = "<div class='bb1'>".$this->app()->ui()->composeTextLine($pi['title']).'</div>';
			$codeBody = "<div class='body pageText'>".$this->app()->ui()->composeTextLine($pi['body']).'</div>';

			$content[] = ['pane' => 'e10-pane-core padd5 e10-bg-t10 e10-ds '.$pi['dsClass'],
				'type' => 'line', 'line' => [['code' => $codeTitle], ['code' => $codeBody]]];
		}
	}

	function loadPinnedContent()
	{
		$q [] = 'SELECT issues.*, ';
		array_push($q, ' persons.fullName AS authorFullName');
		array_push($q, ' FROM [wkf_core_issues] AS issues');
		array_push($q, ' LEFT JOIN e10_persons_persons as persons ON issues.author = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [issues].[tableNdx] = %i', $this->srcTableNdx);
		array_push($q, ' AND [issues].[recNdx] = %i', $this->srcRecNdx);
		array_push($q, ' AND (');
		array_push($q, ' [issues].[onTop] != %i', 0);
		array_push($q, ' OR EXISTS (SELECT ndx FROM [wkf_base_docMarks] WHERE issues.ndx = rec',
			' AND [table] = %i', 1241, ' AND [mark] = %i', 101, ' AND [state] != %i', 0, ' AND [user] = %i', $this->thisUserId, ')');
		array_push($q, ')');
		array_push($q, ' AND (issues.[docStateMain] <= %i)', 2);
		array_push($q, ' ORDER BY [displayOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$this->textRenderer)
				$this->textRenderer = new \lib\core\texts\Renderer($this->app());

			$docStates = $this->tableIssues->documentStates ($r);
			$docStateClass = $this->tableIssues->getDocumentStateInfo ($docStates, $r, 'styleClass');

			$title = [];
			$title[] = ['class' => 'title', 'text' => $r['subject'], 'class' => 'h2', 'icon' => $this->tableIssues->tableIcon($r, 1)];
			$title [] = [
				'class' => 'pull-right', 'icon' => 'system/actionOpen',
				'text' => '', 'title' => 'Opravit', 'type' => 'span',
				'pk' => $r['ndx'], 'docAction' => 'edit', 'data-table' => 'wkf.core.issues',
			];

			$body = $this->tableIssues->messageBodyContent($this->textRenderer, $r);

			$item = ['title' => $title, 'body' => $body, 'dsClass' => $docStateClass];
			$this->pinnedIssues[] = $item;
		}
	}
}