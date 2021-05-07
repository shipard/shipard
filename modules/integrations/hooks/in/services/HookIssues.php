<?php

namespace integrations\hooks\in\services;
use e10\Utility, e10\utils, e10\json;


/**
 * Class HookIssues
 * @package integrations\hooks\in\services
 */
class HookIssues extends \integrations\hooks\in\services\HookCore
{
	/** @var \wkf\core\TableIssues */
	var $tableIssues;
	/** @var \wkf\core\TableComments */
	var $tableComments;

	var $issueRecData = NULL;

	var $result = ['status' => 0, 'issues' => [], 'comments' => []];

	public function init()
	{
		parent::init();

		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->tableComments = $this->app()->table('wkf.core.comments');
	}

	protected function loadIssueFromLinkId($linkId)
	{
		$q[] = 'SELECT * FROM [wkf_core_issues]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [linkId] = %s', $linkId);

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
		{
			$this->issueRecData = $exist->toArray();
			return;
		}

		$this->issueRecData = [];
		$this->createNewIssue();
	}

	protected function createNewIssue()
	{
		$ik = $this->tableIssues->sectionIssueKind($this->issueRecData['section']);

		$this->issueRecData['issueKind'] = $ik['ndx'];
		$this->issueRecData['issueType'] = $ik['issueType'];

		$this->issueRecData['docState'] = 1200;
		$this->issueRecData['docStateMain'] = 1;

		$newNdx = $this->tableIssues->dbInsertRec($this->issueRecData);
		$this->issueRecData = $this->tableIssues->loadItem ($newNdx);
		$this->tableIssues->checkAfterSave2 ($this->issueRecData);
		$this->tableIssues->docsLog ($newNdx);

		if (!in_array($newNdx, $this->result['issues']))
			$this->result['issues'][] = $newNdx;
	}

	protected function updateIssue()
	{
		$newNdx = $this->tableIssues->dbUpdateRec($this->issueRecData);
		$this->issueRecData = $this->tableIssues->loadItem ($newNdx);
		$this->tableIssues->checkAfterSave2 ($this->issueRecData);
		$this->tableIssues->docsLog ($newNdx);

		if (!in_array($newNdx, $this->result['issues']))
			$this->result['issues'][] = $newNdx;
	}

	protected function addComment ($text, $issueNdx = 0, $dateCreate = NULL, $author = 0)
	{
		if (!$issueNdx)
			$issueNdx = $this->issueRecData['ndx'];

		$comment = ['text' => $text, 'docState' => 4000, 'docStateMain' => 2, 'issue' => $issueNdx];
		if ($dateCreate)
			$comment['dateCreate'] = $dateCreate;
		if ($author)
			$comment['author'] = $author;

		$newNdx = $this->tableComments->dbInsertRec($comment);
		$comment = $this->tableComments->loadItem ($newNdx);
		$this->tableComments->checkAfterSave2 ($comment);
		$this->tableComments->docsLog ($newNdx);

		if (!in_array($newNdx, $this->result['comments']))
			$this->result['comments'][] = $newNdx;
	}

	protected function searchIssuesHashTags ($str, &$dst)
	{
		$hashTagsArray = [];
		$strArray = explode(' ',$str);

		$pattern = '%(\A#([\w|:|\.]|(\p{L}\p{M}?)|-)+\b)|((?<=\s)#(\w|(\p{L}\p{M}?)|-)+\b)|((?<=\[)#.+?(?=\]))%u';

		foreach ($strArray as $b)
		{
			preg_match_all($pattern, ($b), $matches);
			$hashTag	= implode(', ', $matches[0]);

			if (!empty($hashtag) || $hashTag != '')
				array_push($hashTagsArray, $hashTag);
		}

		foreach ($hashTagsArray as $c)
		{
			$hashTagTitle = ltrim($c, "#");

			$exist = $this->db()->query ('SELECT ndx FROM [wkf_core_issues] WHERE [issueId] = %s', $hashTagTitle)->fetch();
			if ($exist && $exist['ndx'])
			{
				$dst['affectedIssues'][] = $exist['ndx'];
			}
		}
	}
}
