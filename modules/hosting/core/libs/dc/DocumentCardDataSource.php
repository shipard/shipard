<?php

namespace hosting\core\libs\dc;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * Class DocumentCardDataSource
 */
class DocumentCardDataSource extends \Shipard\Base\DocumentCard
{
	var $tableServers;
	var $serverRecData = NULL;

	var $tablePartners;
	var $partnerRecData = NULL;

	var $tablePersons;
	var $ownerRecData = NULL;
	var $adminRecData = NULL;

	var $tableDataSourcesStats;
	var $statsRecData;

	function loadData()
	{
		$this->tableServers = $this->app()->table ('hosting.core.servers');
		if ($this->recData['server'])
		{
			$this->serverRecData = $this->tableServers->loadItem ($this->recData['server']);
		}

		$this->tablePartners = $this->app()->table ('hosting.core.partners');
		if ($this->recData['partner'])
		{
			$this->partnerRecData = $this->tablePartners->loadItem ($this->recData['partner']);
		}

		$this->tablePersons = $this->app()->table ('e10.persons.persons');
		if ($this->recData['owner'])
		{
			$this->ownerRecData = $this->tablePersons->loadItem ($this->recData['owner']);
		}
		if ($this->recData['admin'])
		{
			$this->adminRecData = $this->tablePersons->loadItem ($this->recData['admin']);
		}


		$this->tableDataSourcesStats = $this->app()->table ('hosting.core.dsStats');
		$this->statsRecData = $this->db()->query('SELECT * FROM [hosting_core_dsStats] WHERE [dataSource] = %i', $this->recData['ndx'])->fetch();
		if ($this->statsRecData)
		{
			$this->statsRecData = $this->statsRecData->toArray();
			$this->statsRecData['data'] = Json::decode ($this->statsRecData['data']);
		}
		else
			$this->statsRecData = NULL;
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
		if ($this->adminRecData)
			$info[] = ['p1' => 'Správce', 't1' => ['text' => $this->adminRecData['fullName'], 'icon' => $this->tablePersons->tableIcon($this->adminRecData)]];
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
						['text' => Utils::snf($this->statsRecData['usageTotal']), 'icon' => 'quantityTypeDataAmount', 'class' => '', 'title' => 'Celková velikost'],
						['text' => Utils::snf($this->statsRecData['usageDb']), 'icon' => 'system/iconDatabase', 'class' => '', 'title' => 'Databáze'],
						['text' => Utils::snf($this->statsRecData['usageFiles']), 'icon' => 'system/formAttachments', 'class' => '', 'title' => 'Přílohy'],

						['text' => Utils::datef($this->statsRecData['dateUpdate'], '%d, %t'), 'icon' => 'system/iconClock', 'class' => 'id pull-right', 'title' => 'Poslední aktualizace'],
					]
				];

				$info[] = [
					'p1' => 'Uživatelé',
					't1' => [
						['text' => Utils::snf($this->statsRecData['cntUsersActive1m']), 'icon' => 'system/iconUser', 'class' => '', 'title' => 'Počet aktivních uživatelů za poslední měsíc'],
						['text' => Utils::snf($this->statsRecData['cntUsersAll1m']), 'icon' => 'system/iconUser', 'class' => '', 'title' => 'Počet všech uživatelů za poslední měsíc'],
					]
				];

				$info[] = [
					'p1' => 'Doklady',
					't1' => [
						['text' => Utils::snf($this->statsRecData['cntDocumentsAll']), 'icon' => 'dataTypesTextContent', 'class' => '', 'title' => 'Celkový počet dokladů'],
						['text' => Utils::snf($this->statsRecData['cntDocuments12m']), 'icon' => 'system/iconCalendar', 'class' => '', 'title' => 'Počet dokladů za poslední rok'],
					]
				];

				$flags = [];
				$flagsInfo = [
					'vat' => ['icon' => 'report/VatReturnReport', 'text' => 'DPH', 'class' => 'label label-info'],
					'ros' => ['icon' => 'tables/e10doc.ros.journal', 'text' => 'EET', 'class' => 'label label-info'],
					'debs' => ['icon' => 'homeAccounting', 'text' => 'Podvojné účetnictví', 'class' => 'label label-info'],
					'sebs' => ['icon' => 'homeAccounting', 'text' => 'Daňová evidence', 'class' => 'label label-info'],
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
									'suffix' => Utils::nf($em['lan']['countDevices']['ALL']).' zařízení',
									'icon' => 'system/iconSitemap', 'class' => 'label label-info'
								];
							}
							if (isset($em['lan']) && isset($em['lan']['countDevices']['10']))
							{
								$extModulesLabels[] = [
									'text' => 'Kamerový systém',
									'suffix' => Utils::nf($em['lan']['countDevices']['10']).' kamer',
									'icon' => 'tables/mac.iot.cams', 'class' => 'label label-info'
								];
							}
							if (isset($em['iot']) && isset($em['iot']['countDevices']['ALL']))
							{
								$sfx =  Utils::nf($em['iot']['countDevices']['ALL']).' zařízení (';
								$sfx .= 'zigbee: '.Utils::nf($em['iot']['countDevices']['zigbee'] ?? 0);
								$sfx .= ', shipard: '.Utils::nf($em['iot']['countDevices']['shipard'] ?? 0);
								$sfx .= ')';
								$extModulesLabels[] = [
									'text' => 'IoT',
									'suffix' => $sfx,
									'icon' => 'tables/mac.iot.devices', 'class' => 'label label-info'
								];
							}
						}

						if ($emId === 'zus')
						{
							if (isset($em['studies']) && isset($em['studies']['count']['ALL']))
							{
								$extModulesLabels[] = [
									'text' => 'ZUŠ',
									'suffix' => Utils::nf($em['studies']['count']['ALL']).' studií',
									'icon' => 'tables/e10pro.zus.predmety', 'class' => 'label label-info'
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

		$this->addCreateRequest($info);

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);


		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
			'line' => ['code' => '<pre>'.Json::lint($this->statsRecData['data']).'</pre>']
		]);
	}

	protected function addCreateRequest(&$destTable)
	{
		if (!$this->recData['createRequest'] || $this->recData['createRequest'] === '')
			return;

		$destTable[] = ['p1' => 'Požadavek na vytvoření', '_options' => ['class' => 'e10-bg-t6 bb1']];

		$createRequest = json_decode($this->recData['createRequest'], TRUE);
		if (!$createRequest)
		{
			return;
		}

		foreach ($createRequest as $key => $value)
		{
			$destTable[] = ['p1' => $key, 't1' => $value];
		}
	}

	public function createContent ()
	{
		$this->loadData();
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
