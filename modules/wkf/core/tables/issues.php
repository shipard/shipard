<?php

namespace wkf\core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10/web/web.php';

use E10\json;
use \e10\utils, \E10\TableViewDetail, \E10\DbTable;
use \Shipard\Form\TableForm;


/**
 * Class TableIssues
 * @package wkf\core
 */
class TableIssues extends DbTable
{
	CONST msHuman = 0, msEmail = 1, msAPI = 2, msTest = 3, msAlert = 4, msWebForm = 5;
	CONST mtNone = 9999, mtIssue = 0, mtInbox = 1, mtTalk = 2, mtOutbox = 3, mtTest = 4, mtAlert = 5, mtNote = 6;
	CONST ctUser = 0, ctWorkflow = 1, ctAlert = 2;

	var $currentStructVersion = 1;

	var $allSections = NULL;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.core.issues', 'wkf_core_issues', 'Zprávy', 1241);
	}

	public function checkAfterSave2 (&$recData)
	{
		if (isset($recData['issueId']) && $recData['issueId'] === '' && isset($recData['issueId']) && $recData['issueId'] !== 0)
		{
			$recData['issueId'] = str_replace(' ', '.', utils::nf(100000+$recData['ndx']));
			$this->app()->db()->query ("UPDATE [wkf_core_issues] SET [issueId] = %s WHERE [ndx] = %i", $recData['issueId'], $recData['ndx']);
		}

		$ik = $this->app()->cfgItem ('wkf.issues.kinds.'.$recData['issueKind'], NULL);
		if ($ik && $recData['docState'] === 4000 && $recData['activateCnt'] === 1)
		{
			$emailForwardOnFirstConfirm = intval($ik['emailForwardOnFirstConfirm'] ?? 0);
			if ($emailForwardOnFirstConfirm)
			{
				$ife = new \wkf\core\libs\IssueEmailForwardEngine($this->app());
				$ife->setIssueNdx($recData['ndx']);
				$ife->send();
			}
		}

		// -- add mark to notified/assigned users
		/*
		if ($recData['docStateMain'] === 1)
		{
			// to solve - add notified/assigned users
			$q [] = 'SELECT dstRecId, dstTableId, linkId FROM [e10_base_doclinks] AS docLinks';
			array_push($q, ' WHERE srcRecId = %i', $recData['ndx'], ' AND srcTableId = %s', 'wkf.core.issues');
			array_push($q, ' AND linkId IN %in', ['wkf-issues-assigned', 'wkf-issues-notify']);

			$rows = $this->db()->query ($q);
			foreach ($rows as $r)
			{
				if ($r['dstTableId'] === 'e10.persons.persons')
				{
					$personNdx = $r['dstRecId'];
					$this->addPeopleToNotify ($recData, $personNdx);
				}
				elseif ($r['dstTableId'] === 'e10.persons.groups')
				{
					$pig = $this->db()->query('SELECT person FROM e10_persons_personsgroups WHERE [group] = %i', $r['dstRecId']);
					foreach ($pig as $pg)
					{
						$this->addPeopleToNotify ($recData, $pg['person']);
					}
				}
			}
		}
		*/

		parent::checkAfterSave2 ($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		if (!isset($recData ['dateTouch']) || utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		$recData['displayOrder'] = $this->displayOrder ($recData);

		if (isset($recData['issueId']) && $recData['issueId'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
			$recData['issueId'] = str_replace(' ', '.', utils::nf(100000+$recData['ndx']));

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

		$recData ['structVersion'] = $this->currentStructVersion;

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->user()->data ('id');

		if (!isset($recData ['dateIncoming']))
			$recData ['dateIncoming'] = new \DateTime ();

		if (!isset($recData ['onTop']))
			$recData ['onTop'] = 0;
	}

	public function documentStates ($recData)
	{
		if ($recData['issueType'] == self::mtNote)
		{
			$states = $this->app()->model()->tableProperty ($this, 'states');
			$states ['states'] = $this->app()->cfgItem ('e10.base.defaultDocStatesArchive');
			return $states;
		}

		$specialDocStates = $this->app()->cfgItem('wkf.issues.docStates.kind'.$recData['issueKind'], NULL);
		if ($specialDocStates)
		{
			$states = $this->app()->model()->tableProperty ($this, 'states');
			if ($states)
				$states ['states'] = $specialDocStates;

			return $states;
		}

		return parent::documentStates($recData);
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		//if ($operation === 'show')
		//	return 'show';

		return 'default2';
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$issueType = $this->app()->cfgItem ('wkf.issues.types.' . $recData['issueType']);

		$info = [
			'title' => $recData['subject'], 'docID' => '#'.$recData['ndx'],
			'docType' => $recData['issueType'], 'docTypeName' => $issueType['name']
		];

		if (isset($recData['workOrder']) && $recData['workOrder'])
		{
			$woRecData = $this->app()->loadItem($recData['workOrder'], 'e10mnf.core.workOrders');
			if ($woRecData && isset($woRecData['customer']) && $woRecData['customer'])
				$info ['persons']['to'][] = $woRecData['customer'];
		}

		$info ['persons']['from'][] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

		return $info;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($options === 1)
		{
			$issuesKinds = $this->app()->cfgItem ('wkf.issues.kinds', []);
			if (isset($issuesKinds[$recData['issueKind']]))
			{
				$issueKind = $issuesKinds[$recData['issueKind']];
				if ($issueKind['icon'] !== '')
					return $issueKind['icon'];
			}
		}

		if (isset($recData['issueType']))
		{
			$issueTypeNdx = $recData['issueType'];
			$issueType = $this->app()->cfgItem('wkf.issues.types.' . $issueTypeNdx, NULL);
			if ($issueType)
				return $issueType['icon'];
		}

		return parent::tableIcon($recData, $options);
	}

	public function createHeader ($recData, $options)
	{
		$sourcesIcons = [0 => 'system/iconKeyboard', 1 => 'icon-envelope-o', 2 => 'icon-plug', 3 => 'icon-android', 4 => 'system/iconWarning'];
		$item = $recData;

		$hdr ['newMode'] = 1;
		$hdr ['icon'] = $this->tableIcon ($recData, 1);

		if (!isset($item['ndx']))
		{
			return $hdr;
		}

		$ndx = $recData['ndx'];
		$classification = \E10\Base\loadClassification ($this->app(), $this->tableId(), $ndx);

		$title = [];
		$title[] = ['class' => 'id pull-right ', 'XXicon' => 'iconKeyboard', 'text' => '#'.$item ['issueId']];

		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(101);
		$marks->loadMarks('wkf.core.issues', [$recData['ndx']]);
		$title[] = [
			'text' => '', 'docAction' => 'mark', 'mark' => 101, 'table' => 'wkf.core.issues', 'pk' => $item ['ndx'],
			'value' => isset($marks->marks[$item ['ndx']]) ? $marks->marks[$item ['ndx']] : 0,
			'class' => '', 'actionClass' => 'h2'
		];

		$title[] = ['text' => $item['subject'], 'class' => 'h2'];
		$hdr ['info'][] = ['class' => 'info', 'value' => $title];

		$props = [];
		if ($recData['author'])
		{
			$author = $this->loadItem($recData['author'], 'e10_persons_persons');
			$props[] = ['class' => 'label label-default', 'icon' => 'system/iconUser', 'text' => $author ['fullName']];
		}
		if (isset($item ['dateCreate']) && !utils::dateIsBlank($item ['dateCreate']))
			$props[] = ['class' => '', 'icon' => $sourcesIcons[$item['source']], 'text' => utils::datef ($item ['dateCreate'], '%D, %T')];


		if ($item['section'])
		{
			$section = $this->app()->cfgItem('wkf.sections.all.' . $item['section'], NULL);
			if ($section)
			{
				$sectionLabel = ['text' => $section['sn'], 'class' => 'label label-info', 'icon' => $section['icon']];

				if ($section['parentSection'])
				{
					$topSection = $this->topSection($item['section']);
					$sectionLabel['suffix'] = $topSection['sn'];
				}
				$props[] = $sectionLabel;
			}
		}

		if (isset ($classification [$ndx]))
		{
			forEach ($classification [$ndx] as $clsfGroup)
				$props = array_merge($props, $clsfGroup);
		}

		$docLinks = $this->docLinksDocs($recData);
		if (count($docLinks))
			$props = array_merge($props, $docLinks);

		if (count($props))
			$hdr ['info'][] = ['class' => 'info', 'value' => $props];

		return $hdr;
	}

	public function checkAccessToDocument ($recData)
	{
		if ($recData['issueType'] == self::mtNote)
			return 2;

		$tableSections = $this->app()->table ('wkf.base.sections');
		$accessLevel = $tableSections->userAccessToSection ($recData['section']);
		if (!$accessLevel)
			return 0;

		$thisUserId = $this->app()->userNdx();

		if ($recData['author'] === $thisUserId)
			$accessLevel = 2;

		return $accessLevel;
	}

	function addPeopleToNotify ($recData, $personNdx)
	{
		$personInfo = $this->db()->query ('SELECT [company], [personType], [accountState] FROM [e10_persons_persons] WHERE ndx = %i', $personNdx, ' AND [docState] = %i', 4000)->fetch();
		if (!$personInfo)
			return;
		if ($personInfo['personType'] != 1 || $personInfo['accountState'] != 1) // company or inactive account
			return;

		$q[] = 'SELECT [user], [state] FROM [wkf_base_docMarks]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [mark] = %i', 100);
		array_push($q, ' AND [user] = %i', $personNdx);
		array_push($q, ' AND [table] = %i', 1241, ' AND [rec] = %i', $recData['ndx']);
		$exist = $this->db()->query($q)->fetch();
		if ($exist)
			return;

		$newItem = ['mark' => 100, 'table' => 1241, 'rec' => $recData['ndx'], 'state' => 1, 'user' => $personNdx];
		$this->db()->query('INSERT INTO [wkf_base_docMarks] ', $newItem);
	}

	public function isDocumentAdmin ($recData, $allowSelfRights)
	{
		if (!isset ($recData['author']))
			$recData = $this->loadItem($recData['ndx']);

		$thisUserId = $this->app()->userNdx();
		$ug = $this->app()->userGroups ();

		if (($allowSelfRights || !$recData['project']) && $recData['author'] === $thisUserId)
			return TRUE;


		if ($recData['author'] === $thisUserId)
			return TRUE;

		return FALSE;
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'issueKind')
		{
			if ($form->recData['issueType'] != $cfgItem['issueType'])
				return FALSE;

			return TRUE;
		}

		if ($columnId === 'status')
		{
			$section = $this->topSection($form->recData['section']);
			if ($section['ndx'] != $cfgItem['section'])
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function displayOrder (&$recData)
	{
		if (!isset($recData['onTop']))
			$recData['onTop'] = 0;
		if (!isset($recData['priority']))
			$recData['priority'] = 10;

		$o = '';

		$p = ($recData['onTop']) ? '5' : '9' ;

		if ($recData['docStateMain'] < 2)
		{ // priority/ontop is used on unresolved only
			$o .= $p;
			$o .= sprintf('%02d', intval($recData['priority']));
		}
		else
			$o .= '9'.'10';

		$o .= $recData['docStateMain'];
		$o .= '50';

		$do = '0000000000';
		if (!utils::dateIsBlank($recData ['dateTouch']))
			$do = utils::createDateTime($recData['dateTouch'], TRUE)->format('ymdHi');
		elseif (!utils::dateIsBlank($recData ['dateCreate']))
			$do = utils::createDateTime($recData['dateCreate'], TRUE)->format('ymdHi');
		else
			$do = utils::now('ymdHi');

		$n = 9999999999 - $do;
		$o .= strval($n);

		return $o;
	}

	public function addWorkflowButtons (&$buttons, $params)
	{
		$issuesKinds = \e10\sortByOneKey($this->app()->cfgItem ('wkf.issues.kinds'), 'addOrder', TRUE);
		$issuesTypes = $this->app()->cfgItem ('wkf.issues.types');

		$enabledIssuesKinds = NULL;

		if (isset($params['section']))
		{
			$ts = $allSections = $this->app()->cfgItem('wkf.sections.all.' . $params['section'], NULL);
			if ($ts && isset($params['topSection']) && $ts['eik'] == 2)
				$ts = $allSections = $this->app()->cfgItem('wkf.sections.all.' . $params['topSection'], NULL);
			if ($ts && isset($ts['issuesKinds']))
			{
				$enabledIssuesKinds = [];
				foreach ($ts['issuesKinds'] as $ik)
				{
					$enabledIssuesKinds[$ik['ndx']] = $issuesKinds[$ik['ndx']];
				}
			}
		}

		if (!$enabledIssuesKinds)
			$enabledIssuesKinds = $issuesKinds;

		if (isset($params['diary']))
		{
			foreach ($issuesKinds as $ikNdx => $ik)
			{
				if ($ik['systemKind'] != 1 && $ik['systemKind'] != 7)
					continue;
				if (isset($enabledIssuesKinds[$ikNdx]))
					continue;
				$enabledIssuesKinds[$ik['ndx']] = $issuesKinds[$ik['ndx']];
			}
		}

		$buttonGroups = [];
		foreach ($enabledIssuesKinds as $issueKindNdx => $issueKind)
		{
			if ($issueKind['systemKind'] == 7 && !isset($params['diary']))
				continue;
			if ($issueKind['systemKind'] != 7 && !isset($params['section']))
				continue;
			$buttonGroups[$issueKind['issueType']][$issueKindNdx] = $issueKind;
		}

		foreach ($buttonGroups as $issueTypeNdx => $issueKinds)
		{
			if ($issueTypeNdx == 4 || $issueTypeNdx == 5)
				continue;

			if (count($issueKinds) > 2)
			{
				$issueKind = $issueKinds[key($issueKinds)];
				$issueType = $issuesTypes[$issueKind['issueType']];
				$addParams = '__issueKind=' . $issueKind['ndx'] . '&__issueType=' . $issueKind['issueType'];

				if (isset($params['tableNdx']) && $params['tableNdx'])
					$addParams .= '&__tableNdx=' . $params['tableNdx'];
				if (isset($params['recNdx']) && $params['recNdx'])
					$addParams .= '&__recNdx=' . $params['recNdx'];

				if (isset($params['section']) && $issueKind['issueType'] !== self::mtNote)
					$addParams .= '&__section=' . $params['section'];

				$icon = ($issueKind['icon'] !== '') ? $issueKind['icon'] : $issueType['icon'];
				$txtTitle = $issueKind['fn'];
				$txtText = '';
				$addButton = [
					'action' => 'new', 'data-table' => 'wkf.core.issues', 'icon' => $icon,
					'text' => $txtText, 'title' => $txtTitle, 'type' => 'button', 'actionClass' => 'btn',
					'class' => 'e10-param-addButton', 'btnClass' => 'btn-success', 'dropRight' => 1,
					'data-addParams' => $addParams,
				];

				if (isset($params['thisViewerId']))
				{
					$addButton['data-srcobjecttype'] = 'viewer';
					$addButton['data-srcobjectid'] = $params['thisViewerId'];
				}

				$addButton['dropdownMenu'] = [];
				foreach ($issueKinds as $issueKindNdx => $issueKind)
				{
					$issueType = $issuesTypes[$issueKind['issueType']];
					$addParams = '__issueKind=' . $issueKindNdx . '&__issueType=' . $issueKind['issueType'];

					if (isset($params['tableNdx']) && $params['tableNdx'])
						$addParams .= '&__tableNdx=' . $params['tableNdx'];
					if (isset($params['recNdx']) && $params['recNdx'])
						$addParams .= '&__recNdx=' . $params['recNdx'];

					if (isset($params['section']) && $issueKind['issueType'] !== self::mtNote)
						$addParams .= '&__section=' . $params['section'];

					$icon = ($issueKind['icon'] !== '') ? $issueKind['icon'] : $issueType['icon'];
					$txtTitle = $issueKind['fn'];
					$addSubButton = [
						'action' => 'new', 'data-table' => 'wkf.core.issues', 'icon' => $icon,
						'text' => $txtTitle, 'type' => 'span',
						'data-addParams' => $addParams,
					];

					if (isset($params['thisViewerId']))
					{
						$addSubButton['data-srcobjecttype'] = 'viewer';
						$addSubButton['data-srcobjectid'] = $params['thisViewerId'];
					}

					$addButton['dropdownMenu'][] = $addSubButton;
				}

				$buttons[] = $addButton;
			}
			else
			{
				foreach ($issueKinds as $issueKindNdx => $issueKind)
				{
					$issueType = $issuesTypes[$issueKind['issueType']];
					$addParams = '__issueKind=' . $issueKindNdx . '&__issueType=' . $issueKind['issueType'];

					if (isset($params['tableNdx']) && $params['tableNdx'])
						$addParams .= '&__tableNdx=' . $params['tableNdx'];
					if (isset($params['recNdx']) && $params['recNdx'])
						$addParams .= '&__recNdx=' . $params['recNdx'];

					if (isset($params['section']) && $issueKind['issueType'] !== self::mtNote)
						$addParams .= '&__section=' . $params['section'];

					$icon = ($issueKind['icon'] !== '') ? $issueKind['icon'] : $issueType['icon'];
					$txtTitle = $issueKind['fn'];
					$txtText = '';
					$addButton = [
						'action' => 'new', 'data-table' => 'wkf.core.issues', 'icon' => $icon,
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
		}
	}

	public function enabledIssuesKindForSection ($sectionNdx)
	{
		$issuesKinds = \e10\sortByOneKey($this->app()->cfgItem ('wkf.issues.kinds'), 'addOrder', TRUE);
		$enabledIssuesKinds = NULL;

		if ($sectionNdx)
		{
			$ts = $this->app()->cfgItem('wkf.sections.all.' . $sectionNdx, NULL);
			if ($ts && isset($ts['parentSection']) && $ts['eik'] == 2)
				$ts = $this->app()->cfgItem('wkf.sections.all.' . $ts['parentSection'], NULL);
			if ($ts && isset($ts['issuesKinds']))
			{
				$enabledIssuesKinds = [];
				foreach ($ts['issuesKinds'] as $ik)
				{
					$enabledIssuesKinds[$ik['ndx']] = $issuesKinds[$ik['ndx']];
				}
			}
		}

		if (!$enabledIssuesKinds)
			$enabledIssuesKinds = $issuesKinds;

		return $enabledIssuesKinds;
	}


	public function topSection ($sectionNdx)
	{
		$section = NULL;

		if (!$this->allSections)
			$this->allSections = $this->app()->cfgItem ('wkf.sections.all', NULL);

		$section = $this->allSections[$sectionNdx];

		if ($section['parentSection'])
			$section = $this->allSections[$section['parentSection']];

		return $section;
	}

	public function issueKindDefault ($issueType, $ndxOnly = FALSE)
	{
		$issuesKinds = $this->app()->cfgItem ('wkf.issues.kinds', []);
		foreach ($issuesKinds as $issueKindNdx => $issueKind)
		{
			if ($issueKind['issueType'] != $issueType)
				continue;

			if ($ndxOnly)
				return $issueKindNdx;

			return $issueKind;
		}

		return FALSE;
	}

	public function defaultSection ($systemSectionType)
	{
		$allSections = $this->app()->cfgItem ('wkf.sections.all', []);
		foreach ($allSections as $sectionNdx => $section)
		{
			if ($section['sst'] === $systemSectionType)
				return $sectionNdx;
		}

		return 0;
	}

	public function defaultSystemKind ($systemKindType)
	{
		$allKinds = $this->app()->cfgItem ('wkf.issues.kinds', []);
		foreach ($allKinds as $kindNdx => $kind)
		{
			if ($kind['systemKind'] == $systemKindType)
				return $kindNdx;
		}

		return 0;
	}

	public function sectionIssueKind ($section)
	{
		$allSections = $this->app()->cfgItem ('wkf.sections.all', []);
		if (isset($allSections[$section]))
		{
			$s = $allSections[$section];
			if ($s['parentSection'])
				$s = $allSections[$s['parentSection']];

			if (isset($s['issuesKinds']) && count($s['issuesKinds']))
			{
				$issueKindNdx = $s['issuesKinds'][0]['ndx'];
				$ik = $this->app()->cfgItem ('wkf.issues.kinds.'.$issueKindNdx, NULL);
				return $ik;
			}
		}

		return NULL;
	}

	public function sectionStatus ($section)
	{
		$allSections = $this->app()->cfgItem ('wkf.sections.all', []);
		if (isset($allSections[$section]))
		{
			$s = $allSections[$section];
			if ($s['parentSection'])
				$s = $allSections[$s['parentSection']];

			$iss = $this->app()->cfgItem ('wkf.issues.statuses.section.'.$s['ndx'], NULL);
			if ($iss && count($iss))
				return $iss[0];
		}

		return 0;
	}

	public function docsLog ($ndx)
	{
		$recData = parent::docsLog($ndx);
		$this->doNotify($recData,0, NULL);
		return $recData;
	}

	public function doNotify($issueRecData, $reason = 0, $commentRecData = NULL)
	{
		$ain = new \wkf\core\libs\AddIssueNotification($this->app());
		$ain->setIssue($issueRecData, $reason, $commentRecData);
		$ain->run();
	}

	public function upload ()
	{
		$destFileName = __APP_DIR__ . '/att/incoming-email-' . time(). '-'.mt_rand (1000, 999999).'.eml';
		if (isset ($_FILES['file']['tmp_name']))
		{ // classic php way
			move_uploaded_file($_FILES['file']['tmp_name'], $destFileName);
		}
		else
		{
			$fileReader = fopen ('php://input', "r");
			$fileWriter = fopen ($destFileName, "w+");

			while (true)
			{
				$buffer = fgets ($fileReader, 4096);
				if (strlen ($buffer) == 0)
				{
					fclose ($fileReader);
					fclose ($fileWriter);
					break;
				}
				fwrite ($fileWriter, $buffer);
			}
		}

		$im = new \wkf\core\services\IncomingEmail($this->app());
		$im->setFileName($destFileName);
		$im->run();
	}

	public function subColumnsInfo ($recData, $columnId)
	{
		if ($columnId === 'data')
		{
			$ik = $this->app()->cfgItem ('wkf.issues.kinds.'.$recData['issueKind'], FALSE);
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

	public function addIssue($issue, $moveAttachments = true)
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
			{
				$fileCheckSum = sha1_file($att['fullFileName']);
				$attExist = $this->db()->query('SELECT ndx FROM [e10_attachments_files] WHERE recid = %i', $issueNdx,
											' AND [tableid] = %s', 'wkf.core.issues', ' AND [fileCheckSum] = %s', $fileCheckSum)->fetch();
				if ($attExist)
					continue;

				\E10\Base\addAttachments($this->app(), 'wkf.core.issues', $issueNdx, $att['fullFileName'], '', $moveAttachments, 0, $att['baseFileName'] ?? '');
			}
		}

		$this->checkAfterSave2($recData);

		// -- save to log
		$this->docsLog ($issueNdx);

		return $issueNdx;
	}

	function messageBodyContent ($textRenderer, $d)
	{
		if ($d['issueType'] === TableIssues::mtInbox)
			return ['type' => 'text', 'subtype' => 'auto', 'text' => $d['text'], 'class' => 'pageText',
				'iframeUrl' => $this->app()->urlRoot.'/api/call/e10pro.wkf.messagePreview/'.$d['ndx']];

		if ($d['source'] == TableIssues::msTest)
		{
			// -- content
			$contentData = json_decode($d['text'], TRUE);
			return ['type' => 'content', 'content' => $contentData, 'class' => 'pageText'];
		}

		$textRenderer->render ($d ['text']);
		return ['class' => 'pageText', 'code' => $textRenderer->code];
	}

	function docLinksDocs($item)
	{
		$labels = [];

		if ($item['tableNdx'])
		{
			$docTable = $this->app()->tableByNdx($item['tableNdx']);
			$docRecData = $docTable->loadItem ($item['recNdx']);
			$docInfo = $docTable->getRecordInfo ($docRecData);

			$docItem = [
				'icon' => $docTable->tableIcon ($docRecData), 'text' => $docInfo['docID'],
				'docAction' => 'edit', 'table' => $docTable->tableId(), 'pk' => $docRecData['ndx'], 'title' => $docInfo['title'],
				'class' => '', 'actionClass' => 'label label-info', 'type' => 'span'];

			$labels[] = $docItem;
		}


		$linkedRows = $this->db()->query (
			'SELECT * FROM [e10_base_doclinks] WHERE 1',
			' AND linkId = %s', 'e10docs-inbox',
			' AND dstRecId = %i', $item['ndx'],
			' AND dstTableId = %s', 'wkf.core.issues'
		);

		foreach($linkedRows as $r)
		{
			$docTable = $this->app()->table($r['srcTableId']);
			$docRecData = $docTable->loadItem ($r['srcRecId']);
			$docInfo = $docTable->getRecordInfo ($docRecData);

			$docItem = [
				'icon' => $docTable->tableIcon ($docRecData), 'text' => $docInfo['docID'],
				'docAction' => 'edit', 'table' => $docTable->tableId(), 'pk' => $docRecData['ndx'], 'title' => $docInfo['title'],
				'class' => '', 'actionClass' => 'label label-info', 'type' => 'span'];

			$labels[] = $docItem;
		}

		return $labels;
	}
}


/**
 * Class ViewDetailIssue
 * @package wkf\core
 */
class ViewDetailIssue extends TableViewDetail
{
}


/**
 * Class ViewDetailInbox
 * @package wkf\core
 */
class ViewDetailInbox extends ViewDetailIssue
{
}

