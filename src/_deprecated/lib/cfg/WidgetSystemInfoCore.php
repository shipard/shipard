<?php

namespace lib\cfg;
use E10\utils;

/**
 * Class WidgetSystemInfoCore
 * @package lib\cfg
 */
class WidgetSystemInfoCore extends \Shipard\UI\Core\WidgetPane
{
	/** @var \lib\cfg\DataSourceStatsEngine */
	var $dsStatsEngine;

	public function createContentHeader ()
	{
		$dsInfo = utils::loadCfgFile('config/dataSourceInfo.json');
		if ($dsInfo === FALSE)
			return;

		// -- data source name/type
		$ds = [];
		$ds[] = ['text' => $dsInfo['name'], 'icon' => 'system/iconDatabase', 'class' => 'e10-widget-big-text'];

		if ($dsInfo['condition'] === 0)
		{
			$ds[] = ['text' => 'Tato databáze je ve zkušební lhůtě', 'class' => 'block padd5'];

			$ds[] = [
					'type' => 'action', 'action' => 'addwizard', 'text' => 'Znovu zinicializovat', 'icon' => 'system/actionRecycle',
					'class' => 'padd5 pull-left', 'actionClass' => 'btn-sm btn-danger',
					'data-table' => 'e10.persons.persons', 'data-class' => 'lib.cfg.DatabaseResetWizard'
			];
			$ds[] = ['text' => 'Smazání obsahu databáze a nové úvodní nastavení', 'class' => 'info'];
			$ds[] = ['text' => 'POZOR: tato volba smaže všechna stávající data!', 'class' => 'block info'];
		}
		else
		if ($dsInfo['condition'] === 2)
		{
			$ds[] = ['text' => 'Tato databáze je v ostrém provozu', 'class' => 'block padd5'];
		}
		else
		if ($dsInfo['condition'] === 2)
		{
			$ds[] = ['text' => 'Toto je testovací databáze', 'class' => 'block padd5'];
		}

		$dbTitlePane = ['info' => [], 'class' => 'info'];
		$dbTitlePane['info'][] = ['class' => 'info', 'value' => $ds];

		$dbInfoCols = 6;
		$cntInfoCols = 3;
		// -- used disk space
		$diskSpacePane = ['info' => [], 'class' => 'info'];
		$date = utils::createDateTime($dsInfo['dsStats']['dateUpdate'], TRUE);
		$diskSpace = [];
		$diskSpace[] = ['text' => utils::memf($dsInfo['dsStats']['usageTotal']), 'icon' => 'quantityTypeDataAmount', 'class' => 'e10-widget-big-number', 'title' => 'Celková velikost databáze včetně příloh: '.utils::datef($date, '%D, %T')];
		if (isset($dsInfo['dsStats']))
		{
			$diskSpace[] = ['text' => 'Data: ' . utils::memf($dsInfo['dsStats']['usageDb']), 'icon' => 'system/iconDatabase', 'class' => 'break padd5 e10-small'];
			$diskSpace[] = ['text' => 'Přílohy: ' . utils::memf($dsInfo['dsStats']['usageFiles']), 'icon' => 'icon-paperclip', 'class' => 'break padd5 e10-small'];
		}
		else
			$diskSpace[] = ['text' => 'Informace zatím nejsou k dispozici', 'class' => 'break padd5 e10-small'];
		$diskSpacePane['info'][] = ['class' => 'info', 'value' => $diskSpace];

		// -- users
		$usersPane = ['info' => [], 'class' => 'info'];
		$users = [];
		$users[] = ['text' => utils::nf($dsInfo['dsStats']['cntUsersAll1m']), 'icon' => 'system/iconUser', 'class' => 'e10-widget-big-number', 'title' => 'Celkový počet uživatelů, kteří se přihlásili za poslední měsíc'];
		if (isset($dsInfo['dsStats']))
		{
			$users[] = ['text' => 'Aktivní: '.utils::nf($dsInfo['dsStats']['cntUsersActive1m']), 'icon' => 'system/iconPencil', 'class' => 'break padd5 e10-small'];
			if ($dsInfo['dsStats']['cntUsersDocs1m'])
				$users[] = ['text' => 'Doklady: ' . utils::nf($dsInfo['dsStats']['cntUsersDocs1m']), 'icon' => 'dataTypesTextContent', 'class' => 'break padd5 e10-small'];
		}
		else
			$users[] = ['text' => 'Informace zatím nejsou k dispozici', 'class' => 'break padd5 e10-small'];
		$usersPane['info'][] = ['class' => 'info', 'value' => $users];

		// -- documents
		$usersDocs = ['info' => [], 'class' => 'info'];
		if (isset($dsInfo['dsStats']) && $dsInfo['dsStats']['cntDocumentsAll'])
		{
			$docs = [];
			$docs[] = ['text' => utils::nf($dsInfo['dsStats']['cntDocumentsAll']), 'icon' => 'dataTypesTextContent', 'class' => 'e10-widget-big-number', 'title' => 'Celkem pořízeno dokladů'];
			$docs[] = ['text' => 'Za 12 měsíců: '.utils::nf($dsInfo['dsStats']['cntDocuments12m']), 'icon' => 'icon-calendar-o', 'class' => 'break padd5 e10-small'];
			$usersDocs['info'][] = ['class' => 'info', 'value' => $docs];
			$cntInfoCols = 2;
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => $dbInfoCols]);
				$this->addContent(['type' => 'tiles', 'tiles' => [$dbTitlePane], 'class' => 'panes']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => $cntInfoCols]);
				$this->addContent(['type' => 'tiles', 'tiles' => [$diskSpacePane], 'class' => 'panes']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => $cntInfoCols]);
				$this->addContent(['type' => 'tiles', 'tiles' => [$usersPane], 'class' => 'panes']);
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
			if ($dsInfo['dsStats']['cntDocumentsAll'])
			{
				$this->addContent(['type' => 'grid', 'cmd' => 'colOpen', 'width' => $cntInfoCols]);
					$this->addContent(['type' => 'tiles', 'tiles' => [$usersDocs], 'class' => 'panes']);
				$this->addContent(['type' => 'grid', 'cmd' => 'colClose']);
			}
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
	}

	function createContentMessages ()
	{

	}

	function createContentCountsSummary()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);

		foreach ($this->dsStatsEngine->countsData['12m'] as $id => $content)
		{
			$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 6]);
				$gp = $content['graphPie'];
				$gp['pane'] = 'e10-pane e10-pane-table';
				$this->addContent($gp);
			$this->addContent (['type' => 'grid', 'cmd' => 'colClose']);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
	}

	function createContentCountsOne($type)
	{
		$periodTypes = ['12m' => 'Poslední rok', 'all' => 'Všechno'];
		$tabs = [];

		foreach ($periodTypes as $pt => $ptTitle)
		{
			$table = $this->dsStatsEngine->countsData[$pt][$type]['tableData'];
			$header = $this->dsStatsEngine->countsData[$pt][$type]['tableHeader'];

			$graphBars = $this->dsStatsEngine->countsData[$pt][$type]['graphBars'];
			$graphBars['pane'] = 'padd5';
			unset($graphBars['title']);

			$graphSpline = $this->dsStatsEngine->countsData[$pt][$type]['graphSpline'];
			$graphSpline['pane'] = 'padd5';
			unset($graphSpline['title']);

			$content = [
						['type' => 'table', 'table' => $table, 'header' => $header],
						$graphSpline,
						$graphBars,
			];

			$tabs[] = ['title' => $ptTitle, 'content' => $content];
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
		$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 12]);
		$this->addContent(['pane' => 'e10-pane e10-pane-table', 'tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
		$this->addContent(['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);
	}

	function createContentCalc()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'rowOpen']);
		$this->addContent (['type' => 'grid', 'cmd' => 'colOpen', 'width' => 12]);
			$this->addContent([
					'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
					'table' => $this->dsStatsEngine->calcData['table'], 'header' => $this->dsStatsEngine->calcData['header'],
			]);
		$this->addContent(['type' => 'grid', 'cmd' => 'colClose']);
		$this->addContent (['type' => 'grid', 'cmd' => 'rowClose']);

	}

	public function createContent ()
	{
		$this->dsStatsEngine = new \lib\cfg\DataSourceStatsEngine($this->app);
		$this->dsStatsEngine->run();

		$this->createContentHeader();

		switch ($this->definition['panel'])
		{
			case 'main': $this->createContentCountsSummary(); break;
			case 'calc': $this->createContentCalc(); break;
			default: $this->createContentCountsOne($this->definition['panel']);
		}

	}

	public function title()
	{
		return FALSE;
	}
}
