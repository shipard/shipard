<?php

namespace wkf\docs;
use e10\utils, e10\TableView, e10\TableForm, e10\DbTable, \lib\persons\LinkedPersons;
use \e10\base\libs\UtilsBase;

/**
 * Class TableFolders
 * @package wkf\docs
 */
class TableFolders extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.docs.folders', 'wkf_docs_folders', 'Složky dokumentů');
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
		$rows = $this->db()->query ('SELECT ndx FROM [wkf_docs_folders] WHERE [parentFolder] = %i', 0, ' ORDER BY [order], [fullName]');

		$idx = 1;
		$usedIdx = [];
		foreach ($rows as $r)
		{
			$treeId = sprintf ('%05d.00000', $idx);
			$this->db()->query('UPDATE [wkf_docs_folders] SET [treeId] = %s', $treeId, ' WHERE [ndx] = %i', $r['ndx']);
			$usedIdx[$r['ndx']] = $idx;
			$idx++;
		}

		$rows = $this->db()->query ('SELECT ndx, parentFolder FROM [wkf_docs_folders] WHERE [parentFolder] != %i', 0, ' ORDER BY [order], [fullName]');

		$idx = 1;
		foreach ($rows as $r)
		{
			$treeId = sprintf ('%05d.%05d', $usedIdx[$r['parentFolder']], $idx);
			$this->db()->query('UPDATE [wkf_docs_folders] SET [treeId] = %s', $treeId, ' WHERE [ndx] = %i', $r['ndx']);
			$usedIdx[$r['ndx']] = $idx;
			$idx++;
		}
	}

	public function checkFolder (&$s)
	{
		if ($s['icon'] === '')
			$s['icon'] = 'tables/wkf.docs.folders';
	}

	public function saveConfig ()
	{
		$list = [];
		$shipardEmails = [];

		$textRenderer = new \lib\core\texts\Renderer($this->app());

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_docs_folders] WHERE [docState] != 9800 ORDER BY [treeId], [ndx]');

		foreach ($rows as $rec)
		{
			$r = $rec->toArray();
			$this->checkFolder($r);
			$folder = ['ndx' => $r ['ndx'],
				'fn' => $r ['fullName'], 'sn' => ($r['shortName'] !== '') ? $r['shortName'] : $r['fullName'],
				'icon' => $r['icon'],
				'parentFolder' => $r['parentFolder'], 'subFolderRightsType' => $r['subFolderRightsType'],
				'edk' => $r['enabledDocsKinds'],
				'subFolders' => [],
			];

			if ($rec['description'] && $rec['description'] !== '')
			{
				$textRenderer->render($rec['description']);
				$folder['description'] = $textRenderer->code;
			}

			$cntPeoples = 0;
			$cntPeoples += $this->saveConfigList ($folder, 'members', 'e10.persons.persons', 'wkf-doc-folders-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($folder, 'membersGroups', 'e10.persons.groups', 'wkf-doc-folders-members', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($folder, 'admins', 'e10.persons.persons', 'wkf-doc-folders-admins', $r ['ndx']);
			$cntPeoples += $this->saveConfigList ($folder, 'adminsGroups', 'e10.persons.groups', 'wkf-doc-folders-admins', $r ['ndx']);

			$folder['allowAllUsers'] = ($cntPeoples) ? 0 : 1;

			if ($r['enabledDocsKinds'] == 1)
			{ // -- manual documents kinds
				$ikr = $this->db()->query ('SELECT * FROM [wkf_docs_foldersDocsKinds] WHERE [folder] = %i', $r['ndx'], ' ORDER BY rowOrder, ndx');
				foreach ($ikr as $ik)
				{
					$folder['docsKinds'][] = ['ndx' => $ik['docKind']];
				}
			}

			$sei = $r['shipardEmailId'];
			if ($sei !== '')
			{
				$shipardEmails[$sei] = ['type' => 'docFolder', 'dstNdx' => $r['ndx'], 'id' => $sei, 'title' => $sei.': '.$r['fullName']];
				$folder['sei'] = $r['shipardEmailId'];
			}

			$list [$r['ndx']] = $folder;

			if ($r['parentFolder'])
			{
				$list [$r['parentFolder']]['subFolders'][] = $r['ndx'];
			}
		}

		// -- save to file
		$cfg ['wkf']['docs']['folders']['all'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.docs.folders.json', utils::json_lint (json_encode ($cfg)));

		// -- shipard emails
		if (count($shipardEmails))
		{
			$cfgShipardEmails ['wkf']['shipardEmails'] = $shipardEmails;
			file_put_contents(__APP_DIR__ . '/config/_wkf.docs.folders.shipardEmails.json', utils::json_lint (json_encode ($cfgShipardEmails)));
		}
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

	function usersFolders ($wantedUserNdx = -1, $wantedUsersGroups = NULL)
	{
		$folders = ['all' => [], 'top' => []];

		$userNdx = ($wantedUserNdx === -1) ? $this->app()->userNdx() : $wantedUserNdx;
		$userGroups = ($wantedUsersGroups === NULL) ? $this->app()->userGroups() : $wantedUsersGroups;

		$allFolders = $this->app()->cfgItem ('wkf.docs.folders.all', NULL);
		if ($allFolders === NULL)
			return $folders;

		foreach ($allFolders as &$f)
		{
			$f['isAdmin'] = 0;
			$rf = $f;
			if (isset($f['parentFolder']) && $f['parentFolder'] && $f['subFolderRightsType'] === 0 && isset($allFolders[$f['parentFolder']]))
				$rf = $allFolders[$f['parentFolder']];

			$enabled = 0;
			if (isset($rf['allowAllUsers']) && $rf['allowAllUsers']) $enabled = 1;
			elseif (isset($rf['members']) && in_array($userNdx, $rf['members'])) $enabled = 1;
			elseif (isset($rf['membersGroups']) && count($userGroups) && count(array_intersect($userGroups, $rf['membersGroups'])) !== 0) $enabled = 1;
			elseif (isset($rf['admins']) && in_array($userNdx, $rf['admins']))
			{
				$enabled = 1;
				$f['isAdmin'] = 1;
			}
			if (isset($rf['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $rf['adminsGroups'])) !== 0)
			{
				$enabled = 1;
				$f['isAdmin'] = 1;
			}

			if (!$enabled)
				continue;

			$folders['all'][$f['ndx']] = $f;
			if (!$f['parentFolder'])
				$folders['top'][$f['ndx']] = $f;

			if ($f['parentFolder'])
			{
				if (!isset($folders['top'][$f['parentFolder']]['esf']))
					$folders['top'][$f['parentFolder']]['esf'] = [];
				$folders['top'][$f['parentFolder']]['esf'][] = $f['ndx'];
			}
		}

		return $folders;
	}

	function userAccessToFolder ($folderNdx)
	{
		$userNdx = $this->app()->userNdx();
		$userGroups = $this->app()->userGroups();

		$allFolders = $this->app()->cfgItem ('wkf.docs.folders.all', NULL);
		if ($allFolders === NULL)
			return 0;
		if (!isset($allFolders[$folderNdx]))
			return 0;

		$f = $allFolders[$folderNdx];
		if ($f['parentFolder'] && $f['subFolderRightsType'] === 0)
		{
			if (!isset($allFolders[$f['parentFolder']]))
				return 0;
			$f = $allFolders[$f['parentFolder']];
		}

		$enabled = 0;

		if ($f['allowAllUsers'])
			$enabled = 1;
		elseif (isset($f['members']) && in_array($userNdx, $f['members']))
			$enabled = 1;
		elseif (isset($f['membersGroups']) && count($userGroups) && count(array_intersect($userGroups, $f['membersGroups'])) !== 0)
			$enabled = 1;

		if (isset($f['admins']) && in_array($userNdx, $f['admins']))
			$enabled = 2;
		elseif (isset($f['adminsGroups']) && count($userGroups) && count(array_intersect($userGroups, $f['adminsGroups'])) !== 0)
			$enabled = 2;

		return $enabled;
	}

	public function folderInfo ($folderNdx, &$info, $widgetId, $viewerId)
	{
		$allFolders = $this->app()->cfgItem ('wkf.docs.folders.all', NULL);
		if ($allFolders === NULL)
			return;
		if (!isset($allFolders[$folderNdx]))
			return;

		$f = $allFolders[$folderNdx];
		$thisFolder = $f;
		if ($f['parentFolder'] && $f['subFolderRightsType'] === 0)
		{
			if (!isset($allFolders[$f['parentFolder']]))
				return;
			$f = $allFolders[$f['parentFolder']];
		}

		$info[] = ['text' => $thisFolder['fn'], 'class' => 'e10-bold'];

/*
		$info[] = [
			'type' => 'action', 'action' => 'addwizard', 'data-class' => 'wkf.core.forms.SectionUserOptions', 'table' => 'wkf.core.sections',
			'text' => '', 'title' => 'Nastavení sekce', 'icon' => 'icon-cog', 'btnClass' => '', 'actionClass' => 'pull-right e10-off', 'element' => 'span', 'class' => '',
			'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $widgetId, 'data-form-element-id' => $viewerId,
		];
*/
		$info[] = ['text' => '', 'class' => 'block'];

		if (isset($thisFolder['desciption']))
			$info[] = ['code' => $thisFolder['description']];

		$shipardEmailId = isset($thisFolder['sei']) ? $thisFolder['sei'] : '';
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
		$lp->setSource('wkf.docs.folders', $f['ndx']);
		$lp->setFlags(LinkedPersons::lpfNicknames|LinkedPersons::lpfExpandGroups);
		$lp->load();

		if (!count($lp->lp))
			return;

		$lp = $lp->lp[$f['ndx']];

		if (isset($lp['wkf-doc-folders-admins']))
		{
			$info[] = ['text' => 'Správci'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['wkf-doc-folders-admins']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
		if (isset($lp['wkf-doc-folders-members']))
		{
			$info[] = ['text' => 'Členové'.': ', 'class' => 'break e10-bold'];
			foreach ($lp['wkf-doc-folders-members']['labels'] as $l)
			{
				$info[] = $l;
			}
		}
	}
}


/**
 * Class ViewFolders
 * @package wkf\docs
 */
class ViewFolders extends TableView
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
		$this->table->checkFolder($item);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = $item['treeId'];
		$listItem ['icon'] = $item['icon'];

		if ($item['parentFolder'])
			$listItem ['level'] = 1;

		$props = [];

		if ($item['shipardEmailId'] !== '')
			$props [] = ['icon' => 'icon-at', 'text' => $item ['shipardEmailId'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => ''];

		if (count($props))
			$listItem ['i2'] = $props;

		if ($item['subFolderRightsType'] === 0 && $item['parentFolder'])
		{
			$listItem['t2'] = ['icon' => 'system/actionLogIn', 'text' => 'Přístupová práva se přebírají z nadřazené složky', 'class' => 'label label-default'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [wkf_docs_folders]';
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

		$this->linkedPersons = UtilsBase::linkedPersons ($this->table->app(), $this->table, $this->pks, 'label label-default');
	}

	function decorateRow (&$item)
	{
		if (isset ($this->linkedPersons [$item ['pk']]))
			$item ['t2'] = $this->linkedPersons [$item ['pk']];
	}
}


/**
 * Class FormFolder
 * @package wkf\docs
 */
class FormFolder extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			if ($this->recData['enabledDocsKinds'] == 1)
				$tabs ['tabs'][] = ['text' => 'Druhy dokumentů', 'icon' => 'formIssueKinds'];
			$tabs ['tabs'][] = ['text' => 'Pozn.', 'icon' => 'system/formNote'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('shipardEmailId');

					$this->addColumnInput('enabledDocsKinds');

					if ($this->recData['parentFolder'])
						$this->addColumnInput ('subFolderRightsType');
					if (!$this->recData['parentFolder'] || $this->recData['subFolderRightsType'])
						$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
					$this->closeTab();
					$this->openTab ();
					$this->addColumnInput ('parentFolder');
					$this->addSeparator(self::coH2);
					$this->addColumnInput ('analyzeAttachments');
				$this->closeTab();
				if ($this->recData['enabledDocsKinds'] == 1)
				{
					$this->openTab(TableForm::ltNone);
						$this->addList('docsKinds');
					$this->closeTab();
				}
				$this->openTab (self::ltNone);
					$this->addInputMemo ('description', NULL, self::coFullSizeY);
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
				' AND [docState] != %i', 9800)->fetch();
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

			// -- docs folders
			$exist = $this->app()->db()->query('SELECT [ndx], [fullName] FROM [wkf_docs_folders] WHERE [shipardEmailId] = %s', $saveData['recData']['shipardEmailId'],
				' AND [ndx] != %i', isset($saveData['recData']['ndx']) ? $saveData['recData']['ndx'] : 0, ' AND [docState] != %i', 9800)->fetch();
			if ($exist)
			{
				$this->setColumnState('shipardEmailId', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('shipardEmailId'), 0)."' ".' už existuje ve složce '.$exist['fullName']));
				return FALSE;
			}
		}

		return parent::validNewDocumentState($newDocState, $saveData);
	}
}

