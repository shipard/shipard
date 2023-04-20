<?php

namespace wkf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableIssuesKinds
 * @package wkf\base
 */
class TableIssuesKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.base.issuesKinds', 'wkf_base_issuesKinds', 'Druhy Zpráv', 1243);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		return $recData;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['icon']))
		{
			if ($recData['icon'] !== '')
				return $recData['icon'];

			$systemKind = $this->app->cfgItem ('wkf.issues.systemKinds.'.$recData['systemKind'], NULL);
			if ($systemKind)
				return $systemKind['icon'];

			$dt = $this->app()->cfgItem ('wkf.issues.types.'.$recData['issueType'], FALSE);
			if ($dt)
				return $dt['icon'];
		}
		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$msgTypes = $this->app()->cfgItem ('wkf.issues.types');
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [wkf_base_issuesKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$icon = $r ['icon'] ?? '';
			if ($icon === '')
			{
				$systemKind = $this->app->cfgItem ('wkf.issues.systemKinds.'.$r['systemKind'], NULL);
				if ($systemKind)
					$icon = $systemKind['icon'];
				else
				{
					$dt = $this->app()->cfgItem ('wkf.issues.types.'.$r['issueType'], FALSE);
					if ($dt)
						$icon = $dt['icon'];
				}
			}

			$item = [
				'ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'],
				'issueType' => $r ['issueType'], 'systemKind' => $r ['systemKind'],
				'icon' => $icon,

				'askWorkOrder' => $r['askWorkOrder'],
				'askDocColumns' => $r['askDocColumns'],
				'askDocAnalytics' => $r['askDocAnalytics'],
				'askDeadline' => $r['askDeadline'],
				'enableConnectedIssues' => $r['enableConnectedIssues'],
				'enableProjects' => $r['enableProjects'], 'enableTargets' => $r['enableTargets'],
				'enableEmailForward' => $r['enableEmailForward'], 'emailForwardOnFirstConfirm' => $r['emailForwardOnFirstConfirm'],
				'emailForwardSubjectPrefix' => $r['emailForwardSubjectPrefix'], 'emailForwardBody' => $r['emailForwardBody'],
				'addOrder' => $msgTypes[$r ['issueType']]['addOrder'].sprintf('%07d', $r['order']),
				'vds' => $r['vds'],
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg['wkf']['issues']['kinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_wkf.issues.kinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewIssuesKinds
 * @package wkf\base
 */
class ViewIssuesKinds extends TableView
{
	var $issuesTypes;
	var $activeIssueType = FALSE;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->issuesTypes = \e10\sortByOneKey($this->app->cfgItem ('wkf.issues.types'), 'order', TRUE);

		$bt = [];
		$bt[] = ['id' => 'ALL', 'title' => 'Vše', 'active' => 1];

		forEach ($this->issuesTypes as $mtId => $mt)
		{
			$addParams = ['issueType' => $mtId];
			$bt [] = ['id' => $mtId, 'title' => $mt['name'], 'active' => 0, 'addParams' => $addParams];
		}
		$this->setBottomTabs ($bt);


		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($item['fullName'] === $item['shortName'])
		{
			$listItem ['t1'] = $item['fullName'];
		}
		else
		{
			$listItem ['t1'] = ['text' => $item['fullName'], 'suffix' => $item['shortName']];
		}

		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		if (count($props))
			$listItem ['i2'] = $props;


		if ($this->activeIssueType === FALSE)
		{
			$this->activeIssueType = $this->bottomTabId ();
			if ($this->activeIssueType === 'ALL')
				$this->activeIssueType = '';
		}

		if ($this->activeIssueType === '')
		{
			$mt = $this->issuesTypes[$item['issueType']];
			$listItem ['i1'] = ['text' => $mt['name'], 'icon' => $mt['icon'], 'class' => 'id'];
		}

		/*
		$propsInput = [['text' => 'Zadávat:', 'class' => '']];
		if ($item['askPrice'] !== 2)
			$propsInput[] = ['text' => '', 'title' => 'Cenu', 'icon' => 'icon-money', 'class' => ''];
		if ($item['askProject'] !== 2)
			$propsInput[] = ['text' => '', 'title' => 'Projekt', 'icon' => 'icon-lightbulb-o', 'class' => ''];
		if ($item['askPersons'] !== 2)
			$propsInput[] = ['text' => '', 'title' => 'Osoby', 'icon' => 'system/iconUser', 'class' => ''];
		if (count($propsInput) > 1)
			$listItem ['t2'][] = $propsInput;

		*/

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$bottomTabId = $this->bottomTabId ();


		$q [] = 'SELECT issuesKinds.* ';
		array_push ($q, ' FROM [wkf_base_issuesKinds] AS [issuesKinds]');
		array_push ($q, ' WHERE 1');

		if ($bottomTabId !== 'ALL')
			array_push ($q, ' AND [issueType] = %i', $bottomTabId);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' issuesKinds.[fullName] LIKE %s', '%'.$fts.'%',
				' OR issuesKinds.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[issuesKinds].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormIssueKind
 * @package wkf\base
 */
class FormIssueKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
		$tabs ['tabs'][] = ['text' => 'Přeposílání', 'icon' => 'user/arrowCircleRight'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('issueType');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('askDocColumns');
					$this->addColumnInput ('askDocAnalytics');
					$this->addColumnInput ('askWorkOrder');
					$this->addColumnInput ('askDeadline');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('enableProjects');
					$this->addColumnInput ('enableTargets');
					$this->addColumnInput ('enableConnectedIssues');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('vds');
					$this->addSeparator(self::coH4);
					$this->addColumnInput ('systemKind');
				$this->closeTab ();
				$this->openTab ();
					$this->addColumnInput ('enableEmailForward');
					$this->addColumnInput ('emailForwardOnFirstConfirm');
					$this->addColumnInput ('emailForwardSubjectPrefix');
					$this->addColumnInput ('emailForwardBody');
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
