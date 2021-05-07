<?php

namespace wkf\docs;

use \e10\utils, \E10\TableViewDetail, \E10\DbTable;


/**
 * Class TableDocuments
 * @package wkf\docs
 */
class TableDocuments extends DbTable
{
	CONST msHuman = 0, msEmail = 1, msAPI = 2, msTest = 3, msAlert = 4, msWebForm = 5;

	var $allFolders = NULL;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.docs.documents', 'wkf_docs_documents', 'Dokumenty', 1331);
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['documentId']) && $recData['documentId'] === '' && isset($recData['documentId']) && $recData['documentId'] !== 0)
		{
			$recData['documentId'] = str_replace(' ', '.', utils::nf(10000+$recData['ndx']));
			$this->app()->db()->query ("UPDATE [wkf_docs_documents] SET [documentId] = %s WHERE [ndx] = %i", $recData['documentId'], $recData['ndx']);
		}

		parent::checkAfterSave2 ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		if (!isset($recData ['dateTouch']) || utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		if (isset($recData['documentId']) && $recData['documentId'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['documentId'] = str_replace(' ', '.', utils::nf(10000+$recData['ndx']));

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkDocumentState (&$recData)
	{
		parent::checkDocumentState ($recData);

		// -- activating
		if (!isset ($recData['activateCnt']))
			$recData['activateCnt'] = 0;
		if ($recData['docStateMain'] >= 1)
			$recData['activateCnt']++;
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->user()->data ('id');
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$info = [
			'title' => $recData['title'], 'docID' => '#'.$recData['ndx'],
		];

		return $info;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($options === 1)
		{
			$documentsKinds = $this->app()->cfgItem ('wkf.docs.kinds', []);
			if (isset($documentsKinds[$recData['documentKind']]))
			{
				$docKind = $documentsKinds[$recData['documentKind']];
				if ($docKind['icon'] !== '')
					return $docKind['icon'];
			}
		}

		return parent::tableIcon($recData, $options);
	}

	public function createHeader ($recData, $options)
	{
		$sourcesIcons = [0 => 'icon-keyboard-o', 1 => 'icon-envelope-o', 2 => 'icon-plug', 3 => 'icon-android', 4 => 'icon-exclamation-triangle'];
		$item = $recData;

		$hdr ['newMode'] = 1;
		$hdr ['icon'] = $this->tableIcon ($recData, 1);

		if (!isset($item['ndx']))
		{
			return $hdr;
		}

		$title = [];
		$title[] = ['class' => 'id pull-right ', 'XXicon' => 'icon-keyboard-o', 'text' => '#'.$item ['documentId']];

		/*
		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(101);
		$marks->loadMarks('wkf.core.issues', [$recData['ndx']]);
		$title[] = [
			'text' => '', 'docAction' => 'mark', 'mark' => 101, 'table' => 'wkf.core.issues', 'pk' => $item ['ndx'],
			'value' => isset($marks->marks[$item ['ndx']]) ? $marks->marks[$item ['ndx']] : 0,
			'class' => '', 'actionClass' => 'h2'
		];
		*/
		$title[] = ['text' => $item['title'], 'class' => 'h2'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $title];

		$props = [];
		if ($recData['author'])
		{
			$author = $this->loadItem($recData['author'], 'e10_persons_persons');
			$props[] = ['class' => 'label label-default', 'icon' => 'icon-user', 'text' => $author ['fullName']];
		}
		if (isset($item ['dateCreate']) && !utils::dateIsBlank($item ['dateCreate']))
			$props[] = ['class' => '', 'icon' => $sourcesIcons[$item['source']], 'text' => utils::datef ($item ['dateCreate'], '%D, %T')];


		if ($item['folder'])
		{
			$folder = $this->app()->cfgItem('wkf.docs.folders.all.' . $item['folder'], NULL);
			if ($folder)
			{
				$folderLabel = ['text' => $folder['sn'], 'class' => 'label label-info', 'icon' => $folder['icon']];

				if ($folder['parentFolder'])
				{
					$topFolder = $this->topFolder($item['folder']);
					$folderLabel['suffix'] = $topFolder['sn'];
				}
				$props[] = $folderLabel;
			}
		}

		if (count($props))
			$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		return $hdr;
	}

	public function checkAccessToDocument ($recData)
	{
		$tableFolders = $this->app()->table ('wkf.docs.folders');
		$accessLevel = $tableFolders->userAccessToFolder ($recData['folder']);
		if (!$accessLevel)
			return 0;

		$thisUserId = $this->app()->userNdx();

		if ($recData['author'] === $thisUserId)
			$accessLevel = 2;

		return $accessLevel;
	}

	public function isDocumentAdmin ($recData, $allowSelfRights)
	{
		if (!isset ($recData['author']))
			$recData = $this->loadItem($recData['ndx']);

		$thisUserId = $this->app()->userNdx();
		$ug = $this->app()->userGroups ();

		if ($recData['author'] === $thisUserId)
			return TRUE;

		return FALSE;
	}

	public function addDashboardButtons (&$buttons, $params)
	{
		//$issuesKinds = \e10\sortByOneKey($this->app()->cfgItem ('wkf.docs.kinds'), 'addOrder', TRUE);
		$docsKinds = $this->app()->cfgItem ('wkf.docs.kinds');

		$enabledDocsKinds = NULL;

		if (isset($params['folder']))
		{
			$ts = $allFolders = $this->app()->cfgItem('wkf.docs.folders.all.' . $params['folder'], NULL);
			if ($ts && isset($params['topFolder']) && $ts['edk'] == 2)
				$ts = $allFolders = $this->app()->cfgItem('wkf.docs.folders.all.' . $params['topFolder'], NULL);
			if ($ts && isset($ts['docsKinds']))
			{
				$enabledDocsKinds = [];
				foreach ($ts['docsKinds'] as $dk)
				{
					$enabledDocsKinds[$dk['ndx']] = $docsKinds[$dk['ndx']];
				}
			}
		}

		if (!$enabledDocsKinds)
			$enabledDocsKinds = $docsKinds;

		foreach ($enabledDocsKinds as $docsKindNdx => $docsKind)
		{
			$addParams = '__documentKind=' . $docsKindNdx;// . '&__issueType=' . $issueKind['issueType'];

			if (isset($params['folder']))
				$addParams .= '&__folder=' . $params['folder'];

			$icon = ($docsKind['icon'] !== '') ? $docsKind['icon'] : 'icon-file';
			$txtTitle = $docsKind['fn'];
			$txtText = '';
			$addButton = [
				'action' => 'new', 'data-table' => 'wkf.docs.documents', 'icon' => $icon,
				'text' => $txtText, 'title' => $txtTitle, 'type' => 'button', 'actionClass' => 'btn',
				'class' => 'e10-param-addButton', 'btnClass' => 'btn-success',
				'data-addParams' => $addParams,
			];

			if (isset($params['thisViewerId']))
			{
				$addButton['data-srcobjecttype'] = 'viewer';
				$addButton['data-srcobjectid'] = $params['thisViewerId'];
			}

			$buttons[] = $addButton;
		}
	}

	public function topFolder ($folderNdx)
	{
		$folder = NULL;

		if (!$this->allFolders)
			$this->allFolders = $this->app()->cfgItem ('wkf.docs.folders.all', NULL);

		$folder = $this->allFolders[$folderNdx];

		if ($folder['parentFolder'])
			$folder = $this->allFolders[$folder['parentFolder']];

		return $folder;
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'data')
		{
			$ik = $this->app()->cfgItem ('wkf.docs.kinds.'.$recData['documentKind'], FALSE);
			if (!$ik || !isset($ik['vds']) || !$ik['vds'])
				return FALSE;

			$vds = $this->db()->query ('SELECT * FROM [vds_base_defs] WHERE [ndx] = %i', $ik['vds'])->fetch();
			if (!$vds)
				return FALSE;

			$sc = json_decode($vds['structure'], TRUE);
			if (!$sc || !isset($sc['fields']))
				return FALSE;

			return $sc['fields'];
		}

		return parent::subColumnsInfo ($recData, $columnId);
	}

	public function addDocument($issue)
	{
		$recData = $issue['recData'];

		$issueKindCfg = $this->app()->cfgItem ('wkf.issues.kinds.'.$recData['issueKind'], NULL);
		$recData['issueType'] = $issueKindCfg['issueType'];

		$this->checkNewRec($recData);

		// -- system info
		if (isset($issue['systemInfo']))
			$recData['systemInfo'] = json_encode($issue['systemInfo']);

		// -- insert
		$issueNdx = $this->dbInsertRec ($recData);
		if (!$issueNdx)
			return 0;
		$recData = $this->loadItem($issueNdx);

		// -- add persons links
		if (isset($issue['persons']) && count($issue['persons']))
		{
			foreach ($issue['persons'] as $linkId => $linkPersons)
			{
				foreach ($linkPersons as $personNdx)
				{
					$newLink = [
						'linkId' => $linkId,
						'srcTableId' => 'wkf.core.issues', 'srcRecId' => $issueNdx,
						'dstTableId' => 'e10.persons.persons', 'dstRecId' => $personNdx
					];
					$this->db()->query ('INSERT INTO [e10_base_doclinks] ', $newLink);
				}
			}
		}

		// -- attachments
		if (isset($issue['attachments']) && count($issue['attachments']))
		{
			foreach ($issue['attachments'] as $att)
				\E10\Base\addAttachments($this->app(), 'wkf.core.issues', $issueNdx, $att['fullFileName'], '', true);
		}

		$this->checkAfterSave2($recData);

		// -- save to log
		$this->docsLog ($issueNdx);

		return $issueNdx;
	}
}


/**
 * Class ViewDetailDocument
 * @package wkf\docs
 */
class ViewDetailDocument extends TableViewDetail
{
}


