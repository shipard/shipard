<?php

namespace wkf\core;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable, \lib\persons\LinkedPersons;


/**
 * Class TableFilters
 * @package wkf\core
 */
class TableFilters extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.core.filters', 'wkf_core_filters', 'Filtry zpráv');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		//$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewFilters
 * @package wkf\core
 */
class ViewFilters extends TableView
{
	var $issueDocStates;
	var $priorities;
	var $lp;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$this->issueDocStates = $this->app()->cfgItem ('wkf.issues.docStates.default');
		$this->priorities = $this->table->columnInfoEnum ('actionSetPriorityValue');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];

		$qryProps = [];
		$this->renderRow_AddQryLabel($qryProps, $item['qrySubjectType'], $item['qrySubjectValue'], 'icon-filter', 'Předmět');
		$this->renderRow_AddQryLabel($qryProps, $item['qryEmailFromType'], $item['qryEmailFromValue'], 'icon-at', 'Od');
		$this->renderRow_AddQryLabel($qryProps, $item['qryEmailToType'], $item['qryEmailToValue'], 'icon-at', 'Pro');
		$this->renderRow_AddQryLabel($qryProps, $item['qryTextType'], $item['qryTextValue'], 'icon-filter', 'Text');
		$this->renderRow_AddQryLabel($qryProps, $item['qrySectionType'], $item['qrySectionFullName'], 'icon-columns', 'Sekce');

		if (count($qryProps))
			$listItem ['t2'] = $qryProps;

		$setProps = [];
		$this->renderRow_AddSetLabel($setProps, $item['actionSetSection'], $item['setSectionFullName'], 'icon-columns', 'Sekce');
		$this->renderRow_AddSetLabel($setProps, $item['actionSetIssueKind'], $item['ikFullName'], $item['ikIcon'], 'Druh');
		$this->renderRow_AddSetLabel($setProps, $item['actionSetDocState'], $this->issueDocStates[$item['actionSetDocStateValue']]['stateName'], $this->issueDocStates[$item['actionSetDocStateValue']]['icon'], 'Stav');
		$this->renderRow_AddSetLabel($setProps, $item['actionSetPriority'], $this->priorities[$item['actionSetPriorityValue']], 'icon-signal', 'Důležitost');

		if ($item['stopAfterApply'])
			$setProps[] = ['text' => 'STOP', 'icon' => 'icon-stop', 'class' => 'label label-danger'];

		if (count($setProps))
			$listItem ['t3'] = $setProps;

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->lp->lp[$item['pk']]))
		{
			$item['t3'][] = ['text' => '', 'class' => 'break'];
			foreach ($this->lp->lp[$item['pk']] as $p)
			{
				$item['t3'][] = ['text' => '', 'suffix' => $p['name'], 'icon' => $p['icon'], 'class' => 'label label-success'];
				$item['t3'] = array_merge($item['t3'], $p['labels']);
			}
		}
	}

	function renderRow_AddQryLabel(&$dst, $qryType, $settingsValue, $icon, $prefix)
	{
		if ($qryType === 0)
			return;

		$txt = '';
		if ($qryType === 4 || $qryType === 2)
			$txt .= '*';
		$txt .= $settingsValue;
		if ($qryType === 3 || $qryType === 2)
			$txt .= '*';

		$l = ['icon' => $icon, 'text' => $txt, 'class' => 'label label-info'];
		if ($prefix !== '')
			$l['prefix'] = $prefix;

		$dst[] = $l;
	}

	function renderRow_AddSetLabel(&$dst, $doIt, $setValue, $icon, $prefix)
	{
		if (!$doIt)
			return;

		$txt = '';
		$txt .= $setValue;

		$l = ['icon' => $icon, 'text' => $txt, 'class' => 'label label-success'];
		if ($prefix !== '')
			$l['prefix'] = $prefix;

		$dst[] = $l;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT filters.*,';
		array_push ($q, ' qrySections.fullName AS qrySectionFullName,');
		array_push ($q, ' setSections.fullName AS setSectionFullName,');
		array_push ($q, ' issuesKinds.fullName AS ikFullName, issuesKinds.icon AS ikIcon');
		array_push ($q, ' FROM [wkf_core_filters] AS [filters]');
		array_push ($q, ' LEFT JOIN [wkf_base_sections] AS [qrySections] ON filters.qrySectionValue = qrySections.ndx');
		array_push ($q, ' LEFT JOIN [wkf_base_sections] AS [setSections] ON filters.actionSetSectionValue = setSections.ndx');
		array_push ($q, ' LEFT JOIN [wkf_base_issuesKinds] AS [issuesKinds] ON filters.actionSetIssueKindValue = issuesKinds.ndx');

		array_push ($q, ' WHERE 1');
		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' filters.[fullName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[filters].', ['[order]', '[fullName]', '[stopAfterApply] DESC', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->lp = new LinkedPersons($this->app());
		$this->lp->setSource('wkf.core.filters', $this->pks);
		$this->lp->load();
	}
}


/**
 * Class FormFilter
 * @package wkf\core
 */
class FormFilter extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Akce', 'icon' => 'formActions'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');

					$this->addSeparator(self::coH2);
					$this->layoutOpen (TableForm::ltGrid);
						$this->openRow ();
							$this->addStatic('Předmět', TableForm::coColW3|TableForm::coRight|TableForm::coBold);
							$this->addColumnInput ('qrySubjectType', TableForm::coColW2);
							if ($this->recData['qrySubjectType'])
								$this->addColumnInput ('qrySubjectValue',TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addStatic('Email OD', TableForm::coColW3|TableForm::coRight|TableForm::coBold);
							$this->addColumnInput ('qryEmailFromType', TableForm::coColW2);
							if ($this->recData['qryEmailFromType'])
								$this->addColumnInput ('qryEmailFromValue',TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addStatic('Email PRO', TableForm::coColW3|TableForm::coRight|TableForm::coBold);
							$this->addColumnInput ('qryEmailToType', TableForm::coColW2);
							if ($this->recData['qryEmailToType'])
								$this->addColumnInput ('qryEmailToValue',TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addStatic('Text zprávy', TableForm::coColW3|TableForm::coRight|TableForm::coBold);
							$this->addColumnInput ('qryTextType', TableForm::coColW2);
							if ($this->recData['qryTextType'])
								$this->addColumnInput ('qryTextValue',TableForm::coColW7);
						$this->closeRow ();
						$this->openRow ();
							$this->addStatic('Sekce', TableForm::coColW3|TableForm::coRight|TableForm::coBold);
							$this->addColumnInput ('qrySectionType', TableForm::coColW2);
							if ($this->recData['qrySectionType'])
								$this->addColumnInput ('qrySectionValue',TableForm::coColW7);
						$this->closeRow ();
					$this->layoutClose();
				$this->closeTab();

				$this->openTab ();
					$this->layoutOpen (TableForm::ltGrid);
						$this->openRow ();
							$this->addColumnInput ('actionSetSection', TableForm::coColW4);
							if ($this->recData['actionSetSection'])
								$this->addColumnInput ('actionSetSectionValue',TableForm::coColW8);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('actionSetIssueKind', TableForm::coColW4);
							if ($this->recData['actionSetIssueKind'])
								$this->addColumnInput ('actionSetIssueKindValue',TableForm::coColW8);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('actionSetPriority', TableForm::coColW4);
							if ($this->recData['actionSetPriority'])
								$this->addColumnInput ('actionSetPriorityValue',TableForm::coColW8);
						$this->closeRow ();
						$this->openRow ();
							$this->addColumnInput ('actionSetDocState', TableForm::coColW4);
							if ($this->recData['actionSetDocState'])
								$this->addColumnInput ('actionSetDocStateValue',TableForm::coColW8);
						$this->closeRow ();
					$this->layoutClose();

					$this->addSeparator(self::coH2);
					$this->addStatic('Osoby', self::coH2);
					$this->addList('doclinks', '', TableForm::loAddToFormLayout);

					$this->addSeparator(self::coH2);
					$this->layoutOpen(self::ltHorizontal);
						$this->addColumnInput('stopAfterApply');
					$this->layoutClose();
				$this->closeTab();

				$this->openTab();
					$this->addColumnInput ('order');
				$this->closeTab();

			$this->closeTabs();
		$this->closeForm ();
	}
}
