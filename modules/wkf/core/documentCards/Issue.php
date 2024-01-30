<?php

namespace wkf\core\documentCards;

use \e10\utils, \e10\json, wkf\core\TableIssues, \lib\persons\LinkedPersons;


/**
 * Class Issue
 * @package wkf\core\documentCards
 */
class Issue extends \e10\DocumentCard
{
	var $info = [];

	/** @var  \lib\persons\LinkedPersons */
	var $lp = NULL;
	var $connectedIssues;
	var $connectedIssues2;
	var $systemInfo = NULL;

	public function createContent ()
	{
		$this->loadData();
		$this->createContentBody();
		//$this->createHeader();
	}

	public function createHeader ()
	{
		/*
		$this->header = [];
		$this->header['icon'] = $this->table->tableIcon($this->recData);
		$this->header['info'][] = ['class' => 'title', 'value' => [['text' => $this->recData ['fullName']], ['text' => '#'.$this->recData ['id'], 'class' => 'pull-right id']]];
		if (count($this->ids))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->ids];
		if (count($this->groups))
			$this->header['info'][] = ['class' => 'info', 'value' => $this->groups];

		$image = \E10\Base\getAttachmentDefaultImage ($this->app(), $this->table->tableId(), $this->recData ['ndx']);
		if (isset($image ['smallImage']))
			$this->header['image'] = $image ['smallImage'];

		*/
	}

	function loadData()
	{
		$this->lp = new LinkedPersons($this->app());
		if (isset($this->recData['ndx']))
		{
			$this->lp->setSource('wkf.core.issues', $this->recData['ndx']);
			$this->lp->setFlags(LinkedPersons::lpfHyperlinks);
			$this->lp->load();
		}

		$this->loadDataConnectedIssues();
		$this->loadDataConnectedIssues2();

		if (isset($this->recData['systemInfo']) && $this->recData['systemInfo'] !== '')
			$this->systemInfo = json_decode($this->recData['systemInfo'], TRUE);
	}

	function loadDataConnectedIssues()
	{
		$connectionsTypes = $this->app()->cfgItem ('wkf.issues.connections.types');

		$this->connectedIssues = [];
		$qli[] = 'SELECT connections.*, ';
		array_push ($qli, ' connectedIssues.subject, connectedIssues.issueType, connectedIssues.issueKind, connectedIssues.issueId AS issueDocNumber');
		array_push ($qli, ' FROM [wkf_core_issuesConnections] AS connections');
		array_push ($qli, ' LEFT JOIN [wkf_core_issues] AS connectedIssues ON connections.connectedIssue = connectedIssues.ndx');
		array_push ($qli, ' WHERE connections.[issue] = %i', $this->recData['ndx']);
		array_push ($qli, ' ORDER BY rowOrder');

		$rows = $this->db()->query($qli);
		foreach ($rows as $r)
		{
			$ct = $r['connectionType'];

			if (!isset($this->connectedIssues[$ct]))
			{
				$ctCfg = $connectionsTypes[$ct];
				$this->connectedIssues[$ct] = ['name' => $ctCfg['name'], 'issues' => []];
			}

			$issueLabel = [
				'text' => $r['subject'], 'prefix' => '#'.$r['issueDocNumber'],
				'icon' => $this->table->tableIcon($r), 'class' => 'block',
				'docAction' => 'edit', 'table' => $this->table->tableId(), 'pk' => $r['connectedIssue']
			];
			$this->connectedIssues[$ct]['issues'][] = $issueLabel;
		}
	}

	function loadDataConnectedIssues2()
	{
		$connectionsTypes = $this->app()->cfgItem ('wkf.issues.connections.types');

		$this->connectedIssues2 = [];
		$qli[] = 'SELECT connections.*, ';
		array_push ($qli, ' connectedIssues.subject, connectedIssues.issueType, connectedIssues.issueKind, connectedIssues.issueId AS issueDocNumber');
		array_push ($qli, ' FROM [wkf_core_issuesConnections] AS connections');
		array_push ($qli, ' LEFT JOIN [wkf_core_issues] AS connectedIssues ON connections.issue = connectedIssues.ndx');
		array_push ($qli, ' WHERE connections.[connectedIssue] = %i', $this->recData['ndx']);
		array_push ($qli, ' ORDER BY rowOrder');

		$rows = $this->db()->query($qli);
		foreach ($rows as $r)
		{
			$ct = $r['connectionType'];

			if (!isset($this->connectedIssues2[$ct]))
			{
				$ctCfg = $connectionsTypes[$ct];
				$this->connectedIssues2[$ct] = ['name' => $ctCfg['oppositeName'], 'issues' => []];
			}

			$issueLabel = [
				'text' => $r['subject'], 'prefix' => '#'.$r['issueDocNumber'],
				'icon' => $this->table->tableIcon($r), 'class' => 'block',
				'docAction' => 'edit', 'table' => $this->table->tableId(), 'pk' => $r['issue']
			];
			$this->connectedIssues2[$ct]['issues'][] = $issueLabel;
		}
	}

	function createSystemInfo()
	{
		if (!isset($this->recData['systemInfo']) || $this->recData['systemInfo'] === '' || !$this->recData['systemInfo'])
			return;

		$sit = [];

		$systemInfo = json_decode($this->recData['systemInfo'], TRUE);

		// -- structured info for users
		if (isset($systemInfo['email']))
		{
			$sit[] = [
				'p1' => ['text' => 'E-mail'],
				'_options' => ['class' => 'header', 'colSpan' => ['p1' => 2], 'cellClasses' => ['p1' => 'width30 pull-left']]
			];
			$this->createSystemInfo_emails($sit, $systemInfo, 'from');
			$this->createSystemInfo_emails($sit, $systemInfo, 'to');
			//$this->createSystemInfo_emailHeaders($sit, $systemInfo);
		}

		if ($this->recData['linkId'] !== '')
		{
			$sit[] = [
				'p1' => ['text' => 'linkId'],
				'_options' => ['class' => 'header', 'colSpan' => ['p1' => 2], 'cellClasses' => ['p1' => 'width30 pull-left']]
			];
			$sit[] = [
				'p1' => ['text' => 'Hodnota'],
				't1' => $this->recData['linkId'],
			];
		}

		if (count($sit))
		{
			$h = ['p1' => ' ', 't1' => ''];
			$this->addContent('body', [
				'type' => 'table',
				'header' => $h, 'table' => $sit, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth'],
				'detailsTitle' => [['text' => 'Doplňující informace', 'class' => '']],
				'details' => 'e10-pane e10-pane-table'

			]);
		}

		// -- raw data for admins
		if ($this->app()->hasRole('admin'))
		{
			$pl = ($systemInfo) ? json::lint($systemInfo) : $this->recData['systemInfo'];
			$pc = [
				'type' => 'text', 'subtype' => 'code', 'text' => $pl,
				'detailsTitle' => [['text' => 'Systémové informace', 'class' => '']],
				'details' => 'e10-pane e10-pane-table'
			];
			$this->addContent('body', $pc);
		}
	}

	function createSystemInfo_emails(&$dstTable, $systemInfo, $keyId)
	{
		if (!$systemInfo['email'][$keyId])
			return;
		$titles = ['from' => 'Od', 'to' => 'Pro'];
		foreach ($systemInfo['email'][$keyId] as $a)
		{
			$item = ['p1' => isset($titles[$keyId]) ? $titles[$keyId] : $keyId, 't1' => ['text' => $a['address']]];
			if (isset($a['name']) && $a['name'] !== '')
				$item['t1']['suffix'] = $a['name'];
			$dstTable[] = $item;
		}
	}

	function createSystemInfo_emailHeaders(&$dstTable, $systemInfo)
	{
		if (!$systemInfo['email']['headers'] || !count($systemInfo['email']['headers']))
			return;

		foreach ($systemInfo['email']['headers'] as $a)
		{
			$item = ['p1' => $a['header'], 't1' => $a['value']];
			$dstTable[] = $item;
		}
	}

	public function createContentBody ()
	{
		$this->createContentIssueProperties();
		$this->createContentIssueText('text');
		$this->createContentIssueText('body');
		$this->addContentAttachments ($this->recData ['ndx']);
		$this->createContentLinkedDoc();
		$this->createSystemInfo();
	}

	function createContentLinkedDoc()
	{
		if ($this->recData['tableNdx'] && $this->recData['recNdx'] && $this->recData['issueType'] != 6)
		{
			/** @var \e10\DbTable $srcTable */
			$srcTable = $this->app()->tableByNdx($this->recData['tableNdx']);
			if ($srcTable)
			{
				$srcRecData = $srcTable->loadItem($this->recData['recNdx']);
				/** @var \e10\DocumentCard $srcDocumentCard */
				$srcDocumentCard = $srcTable->documentCard ($srcRecData, 'issue');
				if ($srcDocumentCard)
				{
					$srcDocumentCard->setDocument($srcTable, $srcRecData);
					$srcDocumentCard->createContent();

					$documentInfo = $srcTable->getRecordInfo ($srcRecData);
					$title = ['class' => 'h1'];

					$title['text'] = $documentInfo['docID'];
					if (isset($documentInfo['docTypeName']))
						$title['suffix'] = $documentInfo['docTypeName'];
					elseif (isset($documentInfo['title']))
						$title['suffix'] = $documentInfo['title'];
					if (isset($documentInfo['icon']))
						$title['icon'] = $documentInfo['icon'];

					$dsClass= '';
					$docState = $srcTable->getDocumentState ($srcRecData);
					if ($docState)
						$dsClass = '  e10-ds '.$srcTable->getDocumentStateInfo ($docState ['states'], $srcRecData, 'styleClass');


					$tt[] = ['text' => '', 'icon' => 'system/iconPinned', 'class' => 'e10-fs2 e10-me pr1'];
					$tt[] = $title;
					$tt[] = [
						'text' => 'Otevřít', 'icon' => 'system/actionOpen',
						'docAction' => 'edit', 'table' => $srcTable->tableId(), 'pk' => $this->recData['recNdx'],
						'class' => 'pull-right',
						'actionClass' => 'btn btn-primary btn-sm', 'type' => 'button',
					];

					$this->addContent('body', [
						//'content' => array_merge($srcDocumentCard->content['header'], $srcDocumentCard->content['body']),
						'content' => $srcDocumentCard->content['body'],
						'pane' => 'e10-pane-core e10-bg-t8 padd5 mt1'.$dsClass, 'title' => $tt]);
				}
			}
		}
	}

	function createContentIssueProperties()
	{
		$ndx = $this->recData['ndx'];

		$t = [];

		// -- linkedPersons
		if (isset ($this->lp->lp[$ndx]))
		{
			forEach ($this->lp->lp[$ndx] as $linkId => $linkContent)
			{

				$t [] = [
					'c1' => ['text' => $linkContent['name'], /*'icon' => $linkContent['icon'],*/ 'class' => 'nowrap'],
					'c2' => $linkContent['labels'],
				];
			}
		}

		// -- email info
		if ($this->systemInfo)
		{
			if (isset($this->systemInfo['email']['from']))
			{
				foreach ($this->systemInfo['email']['from'] as $ea)
				{
					$senderAddressCode = "<a href='mailto:".utils::es($ea['address'])."' target='_blank'>".utils::es($ea['address'])."</a>&nbsp; ";
					$sender = [['code' => $senderAddressCode]];
					if (isset($ea['name']) && $ea['name'] !== '')
						$sender[] = ['text' => $ea['name'], 'class' => 'label label-default'];
					$t [] = ['c1' => 'Odesílatel', 'c2' => $sender];
				}
			}
			elseif (isset($this->systemInfo['webForm']['from']))
			{
				// -- from
				$ea = $this->systemInfo['webForm']['from'];
				$senderAddressCode = "<a href='mailto:".utils::es($ea['address'])."' target='_blank'>".utils::es($ea['address'])."</a>&nbsp; ";
				$sender = [['code' => $senderAddressCode]];
				if (isset($ea['name']) && $ea['name'] !== '')
					$sender[] = ['text' => $ea['name'], 'class' => 'label label-default'];
				$t [] = ['c1' => 'Odesílatel', 'c2' => $sender];
			}

			if (isset($this->systemInfo['webForm']['server']))
			{
				$srvNdx = intval ($this->systemInfo['webForm']['server']);
				$webServer = $this->app()->cfgItem ('e10.web.servers.list.'.$srvNdx, NULL);
				if ($webServer)
				{
					$info = [['text' => $webServer['fn'], 'class' => '']];
					if (isset($this->systemInfo['webForm']['server-url']))
					{
						$info[] = ['text' => $this->systemInfo['webForm']['server-url'], 'class' => 'label label-default'];
					}
					if (isset($this->systemInfo['webForm']['spam-score']))
					{
						$info[] = ['text' => $this->systemInfo['webForm']['spam-score'], 'prefix' => 'SPAM skóre', 'class' => 'label label-default'];
					}

					$t [] = ['c1' => 'Web', 'c2' => $info];
				}
			}
		}

		// -- connectedIssues
		if (count($this->connectedIssues))
		{
			foreach ($this->connectedIssues as $connectionId => $connectedIssues)
			{
				$t [] = [
					'c1' => ['text' => $connectedIssues['name'], /*'icon' => $linkContent['icon'],*/ 'class' => 'nowrap'],
					'c2' => $connectedIssues['issues'],
				];
			}
		}
		// -- connectedIssues2
		if (count($this->connectedIssues2))
		{
			foreach ($this->connectedIssues2 as $connectionId => $connectedIssues)
			{
				$t [] = [
					'c1' => ['text' => $connectedIssues['name'], /*'icon' => $linkContent['icon'],*/ 'class' => 'nowrap'],
					'c2' => $connectedIssues['issues'],
				];
			}
		}

		if (count($t))
		{
			$h = ['c1' => 'c1', 'c2' => 'c2'];
			$this->addContent('body', [
				'pane' => 'e10-pane e10-pane-table e10-pane-top', 'type' => 'table', 'table' => $t, 'header' => $h,
				'params' => ['forceTableClass' => 'dcInfo dcInfoB fullWidth', 'hideHeader' => 1]
			]);
		}
	}

	function createContentIssueText($columnId)
	{
		if (!isset($this->recData [$columnId]) || $this->recData [$columnId] === NULL || $this->recData [$columnId] === '')
			return;

		//if ($this->recData [$columnId] !== '' && $this->recData [$columnId] !== '0')
		{
			if ($this->recData['source'] == TableIssues::msEmail)
			{
				$this->addContent('body',  ['type' => 'text', 'subtype' => 'auto', 'text' => $this->recData [$columnId],
					'iframeUrl' => $this->app()->urlRoot . '/api/call/wkf.core.issuePreview/' . $this->recData['ndx']]);
			}
			else
			{
				if ($this->recData['source'] == TableIssues::msTest)
				{
					$contentData = json_decode($this->recData [$columnId], TRUE);
					foreach ($contentData as $cdi)
						$this->addContent('body', $cdi);
				}
				elseif ($this->recData['source'] == TableIssues::msAlert)
				{
					$alertData = json_decode($this->recData [$columnId], TRUE);

					if (isset($alertData['title']))
						$this->addContent('body', ['pane' => 'e10-pane-core e10-pane-top pa1 h2 '.$alertData['title']['class'], 'type' => 'line', 'line' => $alertData['title']]);

					if (isset($alertData['content']))
					{
						foreach ($alertData['content'] as $cdi)
							$this->addContent('body', $cdi);
					}

					if (isset($alertData['payload']))
					{
						$pl = json::lint($alertData['payload']);
						$pc = [
							'type' => 'text', 'subtype' => 'code', 'text' => $pl,
							'detailsTitle' => [['text' => 'Data', 'class' => ''], ['text' => utils::memf(strlen($pl)), 'class' => 'pull-right e10-small']],
							'details' => 'e10-pane e10-pane-table'
						];
						$this->addContent('body', $pc);
					}
				}
				else
				{
					if ($this->recData [$columnId])
					{
						$textRenderer = new \lib\core\texts\Renderer($this->app());
						$textRenderer->render($this->recData [$columnId]);
						$this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
					}
				}
			}
		}
	}
}
