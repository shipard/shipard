<?php

namespace e10pro\hosting\server;

use e10\utils, e10\json;


/**
 * Class DocumentCardDataSource
 * @package e10pro\hosting\server
 */
class DocumentCardDataSource extends \e10\DocumentCard
{
	var $tableServers;
	var $serverRecData = NULL;

	var $tablePartners;
	var $partnerRecData = NULL;

	var $tablePersons;
	var $ownerRecData = NULL;

	var $tableDataSourcesStats;
	var $statsRecData;

	function loadData()
	{
		$this->tableServers = $this->app()->table ('e10pro.hosting.server.servers');
		if ($this->recData['server'])
		{
			$this->serverRecData = $this->tableServers->loadItem ($this->recData['server']);
		}

		$this->tablePartners = $this->app()->table ('e10pro.hosting.server.partners');
		if ($this->recData['partner'])
		{
			$this->partnerRecData = $this->tablePartners->loadItem ($this->recData['partner']);
		}

		$this->tablePersons = $this->app()->table ('e10.persons.persons');
		if ($this->recData['owner'])
		{
			$this->ownerRecData = $this->tablePersons->loadItem ($this->recData['owner']);
		}


		$this->tableDataSourcesStats = $this->app()->table ('e10pro.hosting.server.datasourcesStats');
		if ($this->recData['owner'])
		{
			$this->statsRecData = $this->db()->query('SELECT * FROM [e10pro_hosting_server_datasourcesStats] WHERE [datasource] = %i', $this->recData['ndx'])->fetch();
			if ($this->statsRecData)
			{
				$this->statsRecData = $this->statsRecData->toArray();
				$this->statsRecData['data'] = json::decode ($this->statsRecData['data']);
			}
			else
				$this->statsRecData = NULL;
		}
	}


	public function createContentHeader ()
	{
		$title = ['icon' => $this->table->tableIcon ($this->recData), 'text' => $this->recData ['fullName']];
		$this->addContent('title', ['type' => 'line', 'line' => $title]);
		$this->addContent('subTitle', ['type' => 'line', 'line' => '#'.$this->recData ['id']]);
	}

	public function createContentBody ()
	{
		$i = $this->recData;

		$info = [];

		// -- state
		$stateLabels = $this->table->dsStateLabels($this->recData);
		$info[] = ['p1' => 'Stav', 't1' => $stateLabels];

		// -- ids
		$idLabels = [['text' => '#'.strval($i['gid']), 'class' => '']];
		if ($i['dsId1'])
			$idLabels[] = ['text' => '@'.$i['dsId1'], 'class' => ''];
		if ($i['dsId2'])
			$idLabels[] = ['text' => '@'.$i['dsId2'], 'class' => 'e10-off'];
		$info[] = ['p1' => 'ID', 't1' => $idLabels];

		// -- core info
		$info[] = ['p1' => 'Název', 't1' => $i['name']];
		$info[] = ['p1' => 'Zkrácený název', 't1' => $i['shortName']];
		$info[] = ['p1' => 'URL', 't1' => $i['urlApp']];

		// -- persons
		if ($this->partnerRecData)
		{
			$info[] = ['p1' => 'Partner', 't1' => ['text' => $this->partnerRecData['name'], 'icon' => $this->tablePartners->tableIcon($this->partnerRecData)]];
		}
		if ($this->ownerRecData)
			$info[] = ['p1' => 'Majitel', 't1' => ['text' => $this->ownerRecData['fullName'], 'icon' => $this->tablePersons->tableIcon($this->ownerRecData)]];


		// -- server & stats
		if ($this->serverRecData)
		{
			$info[] = ['p1' => 'Server', 't1' => ['text' => $this->serverRecData['name'], 'icon' => $this->tableServers->tableIcon($this->serverRecData)]];

			if ($this->statsRecData)
			{
				$info[] = [
					'p1' => 'Velikost',
					't1' => [
						['text' => utils::snf($this->statsRecData['usageTotal']), 'icon' => 'icon-hdd-o', 'class' => '', 'title' => 'Celková velikost'],
						['text' => utils::snf($this->statsRecData['usageDb']), 'icon' => 'icon-database', 'class' => '', 'title' => 'Databáze'],
						['text' => utils::snf($this->statsRecData['usageFiles']), 'icon' => 'icon-paperclip', 'class' => '', 'title' => 'Přílohy'],
					]
				];

				$info[] = [
					'p1' => 'Uživatelé',
					't1' => [
						['text' => utils::snf($this->statsRecData['cntUsersActive1m']), 'icon' => 'icon-user', 'class' => '', 'title' => 'Počet aktivních uživatelů za poslední měsíc'],
						['text' => utils::snf($this->statsRecData['cntUsersAll1m']), 'icon' => 'icon-user-o', 'class' => '', 'title' => 'Počet všech uživatelů za poslední měsíc'],
					]
				];

				$info[] = [
					'p1' => 'Doklady',
					't1' => [
						['text' => utils::snf($this->statsRecData['cntDocumentsAll']), 'icon' => 'icon-file-text-o', 'class' => '', 'title' => 'Celkový počet dokladů'],
						['text' => utils::snf($this->statsRecData['cntDocuments12m']), 'icon' => 'icon-calendar-o', 'class' => '', 'title' => 'Počet dokladů za poslední rok'],
					]
				];

				$flags = [];
				$flagsInfo = [
					'vat' => ['icon' => 'icon-shield', 'text' => 'DPH', 'class' => 'label label-info'],
					'ros' => ['icon' => 'icon-microchip', 'text' => 'EET', 'class' => 'label label-info'],
					'debs' => ['icon' => 'icon-calculator', 'text' => 'Podvojné účetnictví', 'class' => 'label label-info'],
					'sebs' => ['icon' => 'icon-calculator', 'text' => 'Daňová evidence', 'class' => 'label label-info'],
				];
				if (isset($this->statsRecData['data']['flags']))
				{
					foreach ($this->statsRecData['data']['flags'] as $key => $flag)
					{
						if (isset($flagsInfo[$key]))
							$flags[] = $flagsInfo[$key];
						else
							$flags[] = ['text' => $key, 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
					}
				}
				if (isset($this->statsRecData['data']['accMethods']))
				{
					foreach ($this->statsRecData['data']['accMethods'] as $key => $flag)
					{
						if (isset($flagsInfo[$key]))
							$flags[] = $flagsInfo[$key];
						else
							$flags[] = ['text' => $key, 'icon' => 'system/iconWarning', 'class' => 'label label-danger'];
					}
				}
				if (count($flags))
					$info[] = ['p1' => 'Vlastnosti', 't1' => $flags];

				if (isset($this->statsRecData['data']['webDomains']['primary']))
				{
					$wdLabels = [];
					foreach ($this->statsRecData['data']['webDomains']['primary'] as $wd)
						$wdLabels[] = ['text' => $wd, 'icon' => 'icon-globe', 'class' => ''];
					$info[] = ['p1' => 'Web', 't1' => $wdLabels];
				}

				if (isset($this->statsRecData['data']['extModules']))
				{
					$extModulesLabels = [];
					foreach ($this->statsRecData['data']['extModules'] as $emId => $em)
					{
						if ($emId === 'mac')
						{
							if (isset($em['lan']) && isset($em['lan']['countDevices']['ALL']))
							{
								$extModulesLabels[] = [
									'text' => 'Počítačová síť',
									'suffix' => utils::nf($em['lan']['countDevices']['ALL']).' zařízení',
									'icon' => 'icon-sitemap', 'class' => 'label label-info'
								];
							}
							if (isset($em['lan']) && isset($em['lan']['countDevices']['10']))
							{
								$extModulesLabels[] = [
									'text' => 'Kamerový systém',
									'suffix' => utils::nf($em['lan']['countDevices']['10']).' kamer',
									'icon' => 'icon-video-camera', 'class' => 'label label-info'
								];
							}
						}
					}

					$info[] = ['p1' => 'Rozšíření', 't1' => $extModulesLabels];
				}

				if (isset($this->statsRecData['data']['modules']))
				{
					$mLabels = [];
					foreach ($this->statsRecData['data']['modules'] as $m)
						$mLabels[] = ['text' => $m, 'icon' => 'icon-puzzle-piece', 'class' => 'label label-default'];
					$info[] = ['p1' => 'Moduly', 't1' => $mLabels];
				}
			}
		}

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);
	}

	public function createContent ()
	{
		$this->loadData();
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
