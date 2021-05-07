<?php

namespace integrations\hooks\in\services\bitBucket;
use e10\utils;


/**
 * Class BitBucket
 * @package integrations\hooks\in\services\bitBucket
 */
class BitBucket extends \integrations\hooks\in\services\HookIssues
{
	var $repository = NULL;
	var $branch = NULL;
	var $commits = [];

	var $logPeriodId = '';
	var $issueLinkId = '';

	var $ignoredCommitBranches = NULL;

	var $dateCreate = NULL; // TODO: delete

	function parseCommits()
	{
		if ($this->hookSettings['ignoredCommitBranches'] !== '')
			$this->ignoredCommitBranches = explode(' ', $this->hookSettings['ignoredCommitBranches']);

		$repo = $this->inPayload['data']['repository'];
		$this->repository = ['name' => $repo['name']];

		$this->branch = [];

		$changes = $this->inPayload['data']['push']['changes'];
		foreach ($changes as $oneChange)
		{
			if ($oneChange['new']['type'] === 'branch')
			{
				$this->branch['name'] = $oneChange['new']['name'];
			}

			if ($this->ignoredCommitBranches && isset($this->branch['name']) && in_array($this->branch['name'], $this->ignoredCommitBranches))
				continue;

			foreach ($oneChange['commits'] as $c)
			{
				if ($c['type'] !== 'commit')
					continue;

				$commit = [];

				$commit['hash'] = $c['hash'];
				$commit['summary'] = $c['summary']['raw'];
				$commit['date'] = new \DateTime($c['date']);
				$commit['authorTitle'] = $c['author']['raw'];
				$commit['authorName'] = $c['author']['user']['display_name'];

				$commit['links'] = [];

				foreach ($c['links'] as $linkId => $linkInfo)
				{
					$commit['links'][$linkId] = ['id' => $linkId, 'href' => $linkInfo['href']];
				}

				$commit['logIdMonth'] = $commit['date']->format('Y-m');
				$commit['logIdWeek'] = $commit['date']->format('o-W');

				if (!$this->dateCreate)
					$this->dateCreate = $commit['date'];

				$this->searchIssuesHashTags ($c['summary']['raw'],$commit);


				$this->commits[] = $commit;
			}
		}
	}

	function saveCommits()
	{
		foreach ($this->commits as $commit)
		{
			if ($this->hookSettings['usePeriodLogs'] !== 'N')
				$this->addCommitToLog ($commit);
		}
	}

	function addCommitToLog ($commit)
	{
		$this->logPeriodId = '';
		switch ($this->hookSettings['usePeriodLogs'])
		{
			case 'M': $this->logPeriodId = $commit['logIdMonth']; break;
			case 'W': $this->logPeriodId = $commit['logIdWeek']; break;
		}
		if ($this->logPeriodId === '')
			return;

		$this->issueLinkId = 'WHI-'.$this->inRecData['hook'].'-'.$this->logPeriodId;
		$this->loadIssueFromLinkId($this->issueLinkId);

		$textLines = explode("\n", $commit['summary']);
		$this->issueRecData['text'] .= '- '.$textLines[0]."\n";

		$this->updateIssue();

		$commentText = '';
		$commentText .= $commit['summary'];
		$commentText .= "\n\n";

		$commentText .= "---------------------------------\n";
		$commentText .= 'Datum: '.utils::datef($commit['date'], '%d, %T')."\n";
		$commentText .= ' Autor: '.$commit['authorName']."\n";

		$commentText .= ' '.$this->repository['name'].' | '.$this->branch['name'].' | ';
		$commentText .= "\n\"".substr($commit['hash'], 0, 7)."\":".$commit['links']['html']['href']."\n\n";

		$this->addComment($commentText, 0, $commit['date'], $this->hookSettings['periodLogsAuthor']);

		if (isset($commit['affectedIssues']))
		{
			foreach ($commit['affectedIssues'] as $affectedIssueNdx)
			{
				$this->addComment($commentText, $affectedIssueNdx, $commit['date'], $this->hookSettings['periodLogsAuthor']);
			}
		}
	}

	protected function createNewIssue()
	{
		// -- unpin old issues
		$rows = $this->db()->query('SELECT * FROM [wkf_core_issues] WHERE onTop != %i', 0, ' AND [section] = %i', $this->hookSettings['periodLogsSection']);
		foreach ($rows as $r)
		{
			$update = ['onTop' => 0, 'docState' => 4000, 'docStateMain' => 2];
			$this->db()->query('UPDATE [wkf_core_issues] SET ', $update, ' WHERE [ndx] = %i', $r['ndx']);
			$recData = $this->tableIssues->loadItem ($r['ndx']);
			$this->tableIssues->checkAfterSave2 ($recData);
			$this->tableIssues->docsLog ($r['ndx']);
		}

		// -- set new issue
		$this->issueRecData['section'] = $this->hookSettings['periodLogsSection'];
		$this->issueRecData['onTop'] = 7;

		switch ($this->hookSettings['usePeriodLogs'])
		{
			case 'M': $this->issueRecData['subject'] = 'Změny za měsíc '.$this->logPeriodId; break;
			case 'W': $this->issueRecData['subject'] = 'Změny v týdnu '.$this->logPeriodId; break;
		}

		$this->issueRecData['linkId'] = $this->issueLinkId;
		$this->issueRecData['author'] = $this->hookSettings['periodLogsAuthor'];

		$this->issueRecData['dateCreate'] = $this->dateCreate; // TODO: delete

		parent::createNewIssue();
	}

	public function run()
	{
		$this->parseCommits();
		$this->saveCommits();

		$this->setResult($this->result);
	}
}
