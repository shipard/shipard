<?php

namespace wkf\base;

use e10\utils, e10\TableView, e10\TableForm, e10\DbTable, \lib\persons\LinkedPersons;
use \e10\base\libs\UtilsBase;

/**
 * Class TableSections
 * @package wkf\base
 */
class TableSections extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.sections', 'wkf_base_sections', 'Sekce workflow', 1246);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function checkAfterSave2 (&$recData)
	{
		parent::checkAfterSave2 ($recData);

		$this->updateTreeId();
	}

	function updateTreeId()
	{
		$rows = $this->db()->query ('SELECT ndx FROM [wkf_base_sections] WHERE [parentSection] = %i', 0, ' ORDER BY [order], [fullName]');

		$idx = 1;
		$usedIdx = [];
		foreach ($rows as $r)
		{
			$treeId = sprintf ('%05d.00000', $idx);
			$this->db()->query('UPDATE [wkf_base_sections] SET [treeId] = %s', $treeId, ' WHERE [ndx] = %i', $r['ndx']);
			$usedIdx[$r['ndx']] = $idx;
			$idx++;
		}

		$rows = $this->db()->query ('SELECT ndx, parentSection FROM [wkf_base_sections] WHERE [parentSection] != %i', 0, ' ORDER BY [order], [fullName]');

		$idx = 1;
		foreach ($rows as $r)
		{
			$treeId = sprintf ('%05d.%05d', $usedIdx[$r['parentSection']], $idx);
			$this->db()->query('UPDATE [wkf_base_sections] SET [treeId] = %s', $treeId, ' WHERE [ndx] = %i', $r['ndx']);
			$usedIdx[$r['ndx']] = $idx;
			$idx++;
		}
	}

	public function checkSection (&$s)
	{
		$sst = NULL;
		if ($s['systemSectionType'])
			$sst = $this->app()->cfgItem ('wkf.systemSections.types.'.$s['systemSectionType'], NULL);

		if ($s['icon'] === '')
			$s['icon'] = $sst['icon'] ?? 'system/iconFile';
	}

	public function saveConfig ()
	{
		/** @var \wkf\core\TableIssues */
		$tableIssues = $this->app()->table('wkf.core.issues');

		$list = [];
		$shipardEmails = [];
		$reportProblemButtons = [];

		$textRenderer = new \lib\core\texts\Renderer($this->app());

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_base_sections] WHERE [docState] != 9800 ORDER BY [treeId], [ndx]');

		foreach ($rows as $rec)
		{
			$r = $rec->toArray();
			$this->checkSection($r);

			$st = NULL;
			if ($r['systemSectionType'])
				$st = $this->app()->cfgItem ('wkf.systemSections.types.'.$r['systemSectionType'], NULL);

			$orderBy = $r['orderBy'];

			$section = [
				'ndx' => $r ['ndx'],
				'fn' => $r ['fullName'], 'sn' => ($r['shortName'] !== '') ? $r['shortName'] : $r['fullName'],
				'icon' => $r['icon'],
				'parentSection' => $r['parentSection'], 'subSectionRightsType' => $r['subSectionRightsType'],
				'sst' => $r['systemSectionType'], 'nia' => $r['newIssuesAllowed'], 'eik' => $r['enabledIssueKinds'],
				'orderBy' => $orderBy,
				'subSections' => [],
			];

			if (!$section['orderBy'] && $st && isset($st['orderBy']))
				$section['orderBy'] = $st['orderBy'];

			if ($rec['topic'] && $rec['topic'] !== '')
			{
				$textRenderer->render($rec['topic']);
				$section['topic'] = $textRenderer->code;
			}

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($section, 'members', 'e10.persons.persons', 'wkf-sections-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($section, 'membersGroups', 'e10.persons.groups', 'wkf-sections-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($section, 'admins', 'e10.persons.persons', 'wkf-sections-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($section, 'adminsGroups', 'e10.persons.groups', 'wkf-sections-admins', $r ['ndx']);

			$section['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			if ($r['enabledIssueKinds'] == 1)
			{ // -- manual issues kinds
				$ikr = $this->db()->query ('SELECT * FROM [wkf_base_sectionsIssuesKinds] WHERE [section] = %i', $r['ndx'],
					' AND [issueKind] != %i', 0,
					' ORDER BY rowOrder, ndx');
				foreach ($ikr as $ik)
				{
					$section['issuesKinds'][] = ['ndx' => $ik['issueKind']];
				}
			}
			elseif($r['enabledIssueKinds'] == 0 && $r['systemSectionType'] != 0)
			{ // -- auto
				if ($st && isset($st['dik']) && count($st['dik']))
				{
					foreach ($st['dik'] as $sikId)
					{
						$existedIssueKind = $this->db()->query('SELECT * FROM [wkf_base_issuesKinds] WHERE [systemKind] = %i', $sikId)->fetch();
						if (!$existedIssueKind)
							continue;
						$section['issuesKinds'][] = ['ndx' => $existedIssueKind['ndx']];
					}
				}
			}

			$sei = $r['shipardEmailId'];
			if ($sei !== '')
			{
				$shipardEmails[$sei] = ['type' => 'section', 'dstNdx' => $r['ndx'], 'id' => $sei, 'title' => $sei.': '.$r['fullName']];
				$section['sei'] = $r['shipardEmailId'];
			}

			$list [$r['ndx']] = $section;

			if ($r['parentSection'])
			{
				$list [$r['parentSection']]['subSections'][] = $r['ndx'];
			}

			if ($r['systemSectionType'] == 31)
			{ // helpDesk - report problem button
				$issueKind = $tableIssues->issueKindDefault (2);

				if ($issueKind)
				{
					$reportProblemButtons[] = [
					 'text' => 'Nahlásit problém',
					 'icon' => 'icon-bug',
					 'section' => $r['ndx'],
					 'issueKind' => $issueKind['ndx'], 'issueType' => $issueKind['issueType']
					 ];
				}
			}
		}

		// -- save to file
		$cfg ['wkf']['sections']['all'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.sections.json', utils::json_lint (json_encode ($cfg)));

		// -- shipard emails
		if (count($shipardEmails))
		{
			$cfgShipardEmails ['wkf']['shipardEmails'] = $shipardEmails;
			file_put_contents(__APP_DIR__ . '/config/_wkf.sections.shipardEmails.json', utils::json_lint (json_encode ($cfgShipardEmails)));
		}

		// -- report problem buttons
		$cfgReportProblemButtons ['wkf']['reportProblemButtons'] = $reportProblemButtons;
		file_put_contents(__APP_DIR__ . '/config/_wkf.reportProblemButtons.json', utils::json_lint (json_encode ($cfgReportProblemButtons)));
	}

	function saveConfigList (&$item, $key, $dstTableId, $listId, $activityTypeNdx)
	{
		$list = [];

		$rows = $this->app()->db->query (
			'SELECT doclinks.dstRecId FROM [e10_base_doclinks] AS doclinks',
			' WHERE doclinks.linkId = %s', $listId, ' AND dstTableId = %s', $dstTableId,
			' AND doclinks.srcRecId = %i', $activityTypeNdx
		);
		foreach ($rows as $r)
		{
			$list[] = $r['dstRecId'];
		}

		if (count($list))
		{
			$item[$key] = $list;
			return count($list);
		}

		return 0;
	}

	function usersSections ($wantedUserNdx = -1, $wantedUsersGroups = NULL)
	{
		$sections = ['all' => [], 'top' => []];

		$userNdx = ($wantedUserNdx === -1) ? $this->app()->userNdx() : $wantedUserNdx;
		$userGroups = ($wantedUsersGroups === NULL) ? $this->app()->userGroups() : $wantedUsersGroups;

		$allSections = $this->app()->cfgItem ('wkf.sections.all', NULL);
		if ($allSections === NULL)
			return $sections;

		foreach ($allSections as &$s)
		{
			$s['isAdmin'] = 0;
			$rs = $s;
			if (isset($s['parentSection']) &&$s['parentSection'] && $s['subSectionRightsType'] === 0 && isset($allSections[$s['parentSection']]))
				$rs = $allSections[$s['parentSection']];

			$enabled = 0;
			if (isset($rs['allowAllUsers']) && $rs['allowAllUsers']) $enabled = 1;
			elseif (isset($rs['members']) && in_array($userNdx, $rs['members'])) $enabled = 1;
			elseif (isset($rs['membersGroups']) && count($userGroups) && count(array_intersect($userGroups, $rs['membersGroups'])) !== 0) $enabled = 1;
			elseif (isset($rs['admins']) && in_array($userNdx, $rs['admins']))
			{
				$enabled = 1;
				$s['isAdmin'] = 1;
			}
			if (isset($rs['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $rs['adminsGroups'])) !== 0)
			{
				$enabled = 1;
				$s['isAdmin'] = 1;
			}

			if (!$enabled)
				continue;

			$sections['all'][$s['ndx']] = $s;
			if (!$s['parentSection'])
				$sections['top'][$s['ndx']] = $s;

			if ($s['parentSection'])
			{
				if (!isset($sections['top'][$s['parentSection']]['ess']))
					$sections['top'][$s['parentSection']]['ess'] = [];
				$sections['top'][$s['parentSection']]['ess'][] = $s['ndx'];
			}
		}

		return $sections;
	}

	function userAccessToSection ($sectionNdx)
	{
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allSections = $this->app()->cfgItem ('wkf.sections.all', NULL);
		if ($allSections === NULL)
			return 0;
		if (!isset($allSections[$sectionNdx]))
			return 0;

		$s = $allSections[$sectionNdx];
		if ($s['parentSection'] && $s['subSectionRightsType'] === 0)
		{
			if (!isset($allSections[$s['parentSection']]))
				return 0;
			$s = $allSections[$s['parentSection']];
		}

		$enabled = 0;

		if ($s['allowAllUsers'])
			$enabled = 1;
		elseif (isset($s['members']) && in_array($userNdx, $s['members']))
			$enabled = 1;
		elseif (isset($s['membersGroups']) && count($userGroups) && count(array_intersect($userGroups, $s['membersGroups'])) !== 0)
			$enabled = 1;

		if (isset($s['admins']) && in_array($userNdx, $s['admins']))
			$enabled = 2;
		elseif (isset($s['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $s['adminsGroups'])) !== 0)
			$enabled = 2;

		return $enabled;
	}

	public function sectionInfo ($sectionNdx, &$info, $widgetId, $viewerId)
	{
		$allSections = $this->app()->cfgItem ('wkf.sections.all', NULL);
		if ($allSections === NULL)
			return;
		if (!isset($allSections[$sectionNdx]))
			return;

		$s = $allSections[$sectionNdx];
		$thisSection = $s;
		if ($s['parentSection'] && $s['subSectionRightsType'] === 0)
		{
			if (!isset($allSections[$s['parentSection']]))
				return;
			$s = $allSections[$s['parentSection']];
		}

		$info[] = ['text' => $thisSection['fn'], 'class' => 'e10-bold'];
		$options = NULL;

		if ($thisSection['parentSection'] || (!$thisSection['parentSection'] && !count($thisSection['subSections'])))
		{
			$options = [
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'wkf.core.forms.SectionUserOptions', 'table' => 'wkf.core.sections',
				'dropRight' => 1, 'dropRightEl' => 1, 'element' => 'button', 'class' => 'pull-right-absolute',
				'text' => '', 'title' => 'Nastavení sekce', 'icon' => 'system/actionUserSettings', 'btnClass' => 'btn-link pull-right',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $widgetId, 'data-form-element-id' => $viewerId,
			];
		}

		if ($thisSection['parentSection'] || (!$thisSection['parentSection'] && !count($thisSection['subSections'])))
		{
			$options['dropdownMenu'][] = [
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'wkf.core.libs.WizardAddFromAttachments', 'table' => 'wkf.core.sections',
				'text' => 'Hromadně nahrát přílohy jako nové zprávy', 'icon' => 'system/actionUpload',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $widgetId, 'data-form-element-id' => $viewerId,
				'data-addParams' => 'dstSectionNdx=' . $sectionNdx,
			];
		}

		if ($options && count($options))
			$info[] = $options;
		else
			$info[] = ['text' => '', 'class' => 'pull-right-absolute'];

		if (isset($thisSection['topic']))
			$info[] = ['code' => $thisSection['topic']];

		$shipardEmailId = isset($thisSection['sei']) ? $thisSection['sei'] : '';
		if ($shipardEmailId !== '')
		{
			$dsId = $this->app()->cfgItem('dsi.dsId1', '');
			if ($dsId === '')
				$dsId = $this->app()->cfgItem('dsi.dsid', '');
			$emailDomain = $this->app()->cfgItem('dsi.portalInfo.emailDomain', 'shipard.email');
			$email = $dsId.'--'.$shipardEmailId.'@'.$emailDomain;
			$info[] = ['text' => $email, 'class' => 'block e10-small', 'icon' => 'system/iconEmail'];
		}

		$lp = new LinkedPersons($this->app());
		$lp->setSource('wkf.base.sections', $s['ndx']);
		$lp->setFlags(LinkedPersons::lpfNicknames|LinkedPersons::lpfExpandGroups);
		$lp->load();

		if (!count($lp->lp))
			return;

		$lp = $lp->lp[$s['ndx']];

		if (isset($lp['wkf-sections-admins']))
		{
			$info[] = ['text' => 'Správci'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['wkf-sections-admins']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
		if (isset($lp['wkf-sections-members']))
		{
			$info[] = ['text' => 'Členové'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['wkf-sections-members']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
	}
}


/**
 * Class ViewSections
 * @package wkf\base
 */
class ViewSections extends TableView
{
	var $linkedPersons;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$this->table->checkSection($item);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['treeId'];
		$listItem ['icon'] = $item['icon'];

		if ($item['parentSection'])
			$listItem ['level'] = 1;

		$props = [];

		if ($item['shipardEmailId'] !== '')
			$props [] = ['icon' => 'icon-at', 'text' => $item ['shipardEmailId'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => ''];

		if (count($props))
			$listItem ['i2'] = $props;

		if ($item['subSectionRightsType'] === 0 && $item['parentSection'])
		{
			$listItem['t2'] = ['icon' => 'system/actionLogIn', 'text' => 'Přístupová práva se přebírají z nadřazené sekce', 'class' => 'label label-default'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [wkf_base_sections]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%',
				' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[treeId]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->linkedPersons = UtilsBase::linkedPersons ($this->app(), $this->table, $this->pks, 'label label-default');
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
	}
}


/**
 * Class FormSection
 * @package wkf\base
 */
class FormSection extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			if ($this->recData['enabledIssueKinds'] == 1)
				$tabs ['tabs'][] = ['text' => 'Druhy zpráv', 'icon' => 'formIssueKinds'];
			$tabs ['tabs'][] = ['text' => 'Téma', 'icon' => 'formTheme'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('shipardEmailId');

					$this->addColumnInput('enabledIssueKinds');

					if ($this->recData['parentSection'])
						$this->addColumnInput ('subSectionRightsType');
					if (!$this->recData['parentSection'] || $this->recData['subSectionRightsType'])
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('parentSection');
					$this->addColumnInput ('systemSectionType');
					$this->addColumnInput ('newIssuesAllowed');
					$this->addColumnInput ('orderBy');
				$this->closeTab();
				if ($this->recData['enabledIssueKinds'] == 1)
				{
					$this->openTab(TableForm::ltNone);
						$this->addList('issuesKinds');
					$this->closeTab();
				}
				$this->openTab (self::ltNone);
					$this->addInputMemo ('topic', NULL, self::coFullSizeY);
				$this->closeTab();
		$this->closeTabs();
		$this->closeForm ();
	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		if ($newDocState === 9800 || $newDocState === 8000)
			return parent::validNewDocumentState($newDocState, $saveData);

		if (isset ($saveData['recData']['shipardEmailId']) && $saveData['recData']['shipardEmailId'] !== '')
		{
			// -- system ids
			if (in_array($saveData['recData']['shipardEmailId'], ['scan', 'outbox', 'note', 'documents']))
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota není povolena - je vyhražena pro systémové účely'));
				return FALSE;
			}

			// -- sections
			$exist = $this->app()->db()->query('SELECT [ndx], [fullName] FROM [wkf_base_sections] WHERE [shipardEmailId] = %s', $saveData['recData']['shipardEmailId'],
				' AND [ndx] != %i', isset($saveData['recData']['ndx']) ? $saveData['recData']['ndx'] : 0, ' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('shipardEmailId'), 0)."' ".' už existuje v sekci '.$exist['fullName']));
				return FALSE;
			}

			// -- trays
			$exist = $this->app()->db()->query('SELECT [ndx], [fullName] FROM [wkf_base_trays] WHERE [shipardEmailId] = %s', $saveData['recData']['shipardEmailId'],
				' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('shipardEmailId'), 0)."' ".' už existuje v přihrádce '.$exist['fullName']));
				return FALSE;
			}
		}

		return parent::validNewDocumentState($newDocState, $saveData);
	}
}

