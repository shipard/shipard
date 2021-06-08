<?php

namespace e10mnf\base;

use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableWorkRecsKinds
 * @package e10mnf\base
 */
class TableWorkRecsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.base.workRecsKinds', 'e10mnf_base_workRecsKinds', 'Druhy pracovních záznamů');
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
			$dt = $this->app()->cfgItem ('e10mnf.workRecs.wrTypes.'.$recData['docType'], FALSE);
			if ($dt)
				return $dt['icon'];
		}
				
		return parent::tableIcon ($recData, $options);
	}

	public function saveConfig ()
	{
		$list = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10mnf_base_workRecsKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName]');

		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r ['ndx'], 'fn' => $r ['fullName'], 'sn' => $r ['shortName'], 'docType' => $r ['docType'], 'icon' => $r ['icon'],
				'askPerson' => $r['askPerson'],
				'askProject' => $r['askProject'],
				'askWorkOrder' => $r['askWorkOrder'],
				'askItem' => $r['askItem'],
				'askPrice' => $r['askPrice'],

				'askDateTimeOnHead' => $r['askDateTimeOnHead'], 'askDateTimeOnRows' => $r['askDateTimeOnRows'],

				'askSubject' => $r['askSubject'], 'askNote' => $r['askNote'],
				'useRows' => $r['useRows'],
				'startOnProject' => $r['startOnProject'], 'startOnDocument' => $r['startOnDocument'], 'startOnPerson' => $r['startOnPerson'],
				'startOnIssue' => $r['startOnIssue'],
				'startGlobal' => $r['startGlobal'],
				'enableStartStop' => $r['enableStartStop'],
				'defaultSubject' => $r['defaultSubject'],
			];

			$list [$r['ndx']] = $item;
		}

		// -- save to file
		$cfg['e10mnf']['workRecs']['wrKinds'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10mnf.workRecs.wrKinds.json', utils::json_lint (json_encode ($cfg)));
	}
}


/**
 * Class ViewWorkRecsKinds
 * @package e10mnf\base
 */
class ViewWorkRecsKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['shortName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		/*
		if ($item ['projectGroupName'])
			$props [] = ['icon' => 'icon-sticky-note-o', 'text' => $item ['projectGroupName'], 'class' => 'label label-default'];

		if ($item ['projectName'])
			$props [] = ['icon' => 'icon-lightbulb-o', 'text' => $item ['projectName'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'system/iconOrder', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;
*/
		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();


		$q [] = 'SELECT wrKinds.* ';
		array_push ($q, ' FROM [e10mnf_base_workRecsKinds] AS [wrKinds]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' wrKinds.[fullName] LIKE %s', '%'.$fts.'%',
				' OR wrKinds.[shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[wrKinds].', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormWorkRecsKind
 * @package e10mnf\base
 */
class FormWorkRecsKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('docType');
					$this->addColumnInput ('icon');
					$this->addColumnInput ('order');
				$this->closeTab();
				$this->openTab ();
					$this->addColumnInput ('useRows');

					$this->addColumnInput ('askDateTimeOnHead');
					$this->addColumnInput ('askDateTimeOnRows');

					$this->addColumnInput ('askPerson');
					$this->addColumnInput ('askProject');
					$this->addColumnInput ('askWorkOrder');
					$this->addColumnInput ('askItem');
					$this->addColumnInput ('askPrice');
					$this->addColumnInput ('askPersons');
					$this->addColumnInput ('askSubject');
					$this->addColumnInput ('askNote');

					/*
					$this->addColumnInput ('startOnProject');
					$this->addColumnInput ('startOnIssue');
					$this->addColumnInput ('startOnPerson');
					$this->addColumnInput ('startOnDocument');
					$this->addColumnInput ('startGlobal');
					*/

					$this->addColumnInput ('enableStartStop');
					//$this->addColumnInput ('defaultSubject');
					//$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}
