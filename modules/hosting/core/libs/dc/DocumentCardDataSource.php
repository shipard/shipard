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
		$info[] = ['p1' => 'N??zev', 't1' => $i['name']];
		$info[] = ['p1' => 'Zkr??cen?? n??zev', 't1' => $i['shortName']];
		$info[] = ['p1' => 'URL', 't1' => $i['urlApp']];

		// -- persons
		if ($this->adminRecData)
			$info[] = ['p1' => 'Spr??vce', 't1' => ['text' => $this->adminRecData['fullName'], 'icon' => $this->tablePersons->tableIcon($this->adminRecData)]];
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
						['text' => Utils::snf($this->statsRecData['usageTotal']), 'icon' => 'quantityTypeDataAmount', 'class' => '', 'title' => 'Celkov?? velikost'],
						['text' => Utils::snf($this->statsRecData['usageDb']), 'icon' => 'system/iconDatabase', 'class' => '', 'title' => 'Datab??ze'],
						['text' => Utils::snf($this->statsRecData['usageFiles']), 'icon' => 'system/formAttachments', 'class' => '', 'title' => 'P????lohy'],

						['text' => Utils::datef($this->statsRecData['dateUpdate'], '%d, %t'), 'icon' => 'system/iconClock', 'class' => 'id pull-right', 'title' => 'Posledn?? aktualizace'],
					]
				];

				$info[] = [
					'p1' => 'U??ivatel??',
					't1' => [
						['text' => Utils::snf($this->statsRecData['cntUsersActive1m']), 'icon' => 'system/iconUser', 'class' => '', 'title' => 'Po??et aktivn??ch u??ivatel?? za posledn?? m??s??c'],
						['text' => Utils::snf($this->statsRecData['cntUsersAll1m']), 'icon' => 'system/iconUser', 'class' => '', 'title' => 'Po??et v??ech u??ivatel?? za posledn?? m??s??c'],
					]
				];

				$info[] = [
					'p1' => 'Doklady',
					't1' => [
						['text' => Utils::snf($this->statsRecData['cntDocumentsAll']), 'icon' => 'dataTypesTextContent', 'class' => '', 'title' => 'Celkov?? po??et doklad??'],
						['text' => Utils::snf($this->statsRecData['cntDocuments12m']), 'icon' => 'system/iconCalendar', 'class' => '', 'title' => 'Po??et doklad?? za posledn?? rok'],
					]
				];

				$flags = [];
				$flagsInfo = [
					'vat' => ['icon' => 'icon-shield', 'text' => 'DPH', 'class' => 'label label-info'],
					'ros' => ['icon' => 'icon-microchip', 'text' => 'EET', 'class' => 'label label-info'],
					'debs' => ['icon' => 'icon-calculator', 'text' => 'Podvojn?? ????etnictv??', 'class' => 'label label-info'],
					'sebs' => ['icon' => 'icon-calculator', 'text' => 'Da??ov?? evidence', 'class' => 'label label-info'],
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
									'text' => 'Po????ta??ov?? s????',
									'suffix' => Utils::nf($em['lan']['countDevices']['ALL']).' za????zen??',
									'icon' => 'system/iconSitemap', 'class' => 'label label-info'
								];
							}
							if (isset($em['lan']) && isset($em['lan']['countDevices']['10']))
							{
								$extModulesLabels[] = [
									'text' => 'Kamerov?? syst??m',
									'suffix' => Utils::nf($em['lan']['countDevices']['10']).' kamer',
									'icon' => 'icon-video-camera', 'class' => 'label label-info'
								];
							}
						}
					}

					$info[] = ['p1' => 'Roz??????en??', 't1' => $extModulesLabels];
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
	}

	protected function addCreateRequest(&$destTable)
	{
		if (!$this->recData['createRequest'] || $this->recData['createRequest'] === '')
			return;
		
		$destTable[] = ['p1' => 'Po??adavek na vytvo??en??', '_options' => ['class' => 'e10-bg-t6 bb1']];

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
