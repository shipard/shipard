<?php

namespace hosting\core;

use E10\json;
use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \Shipard\Viewer\TableViewPanel, \E10\DbTable, \Shipard\Form\TableFormShow;
use \e10\base\libs\UtilsBase;

/**
 * Class TableDataSources
 */
 class TableDataSources extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('hosting.core.dataSources', 'hosting_core_dataSources', 'Zdroje dat');
	}

	public function checkBeforeSave(&$recData, $ownerData = NULL)
	{
		if (isset($recData['gid']) && $recData['gid'] === '')
			$recData ['gid'] = Utils::createToken(4).'-'.Utils::createToken(4).'-'.Utils::createToken(4).'-'.Utils::createToken(4);

		if (isset($recData ['ndx']) && $recData ['ndx'])
		{
			// -- ds image
			$image = UtilsBase::getAttachmentDefaultImage($this->app(), 'hosting.core.dataSources', $recData ['ndx']);
			if (isset($image['fileName']))
			{
				$recData['imageUrl'] = $this->app()->cfgItem('hostingServerUrl') . 'imgs/-w256/att/' . $image['fileName'];
				$recData['dsIconServerUrl'] = $this->app()->cfgItem('hostingServerUrl');
				$recData['dsIconFileName'] = 'att/' . $image['fileName'];
			}
			else
			{
				$recData['imageUrl'] = '';
				$recData['dsIconServerUrl'] = '';
				$recData['dsIconFileName'] = '';
			}
		}

		if ($recData['dsType'] != 0)
			$recData['dsDemo'] = 0;

		parent::checkBeforeSave($recData, $ownerData);
	}

	public function createHeader($recData, $options)
	{
		$hdr = parent::createHeader($recData, $options);
		$topInfo = [['text' => '#' . $recData ['gid']]];

		if ($recData['server'])
		{
			$serverRec = $this->app()->loadItem($recData['server'], 'hosting.core.servers');
			$topInfo[] = ['icon' => 'x-server', 'text' => $serverRec['id']];
		}

		$hdr ['info'][] = ['class' => 'info', 'value' => $topInfo];


		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['name']];

		$info = [];
		$ownerFullName = '';

		if ($recData['owner'])
		{
			$ownerRec = $this->app()->loadItem($recData['owner'], 'e10.persons.persons');
			$ownerFullName = $ownerRec['fullName'];
			$info [] = ['icon' => 'system/iconBuilding', 'text' => $ownerFullName];
		}
		if ($recData['admin'])
		{
			$adminRec = $this->app()->loadItem($recData['admin'], 'e10.persons.persons');
			if ($adminRec['fullName'] != $ownerFullName)
				$info [] = ['icon' => 'system/actionSettings', 'text' => $adminRec['fullName']];
		}
		if ($recData['payer'])
		{
			$payerRec = $this->app()->loadItem($recData['admin'], 'e10.persons.persons');
			if ($payerRec['fullName'] != $ownerFullName)
				$info [] = ['icon' => 'icon-money', 'text' => $payerRec['fullName']];
		}

		$hdr ['info'][] = ['class' => 'info', 'value' => $info];

		$image = UtilsBase::getAttachmentDefaultImage($this->app(), $this->tableId(), $recData ['ndx']);
		if (isset($image ['smallImage']))
		{
			$hdr ['image'] = $image ['smallImage'];
			unset ($hdr ['icon']);
		}

		return $hdr;
	}

	public function dsStateLabels($recData)
	{
		$labels = [];

		$dsTypes = $this->app()->cfgItem('hosting.core.dsTypes');
		$dsType = $dsTypes[$recData['dsType']] ?? ['sn' => 'invalid dsType `'.$recData['dsType'].'`', 'icon' => 'system/iconWarning', 'labelClass' => 'label-danger'];
		$labels['dsType'] = ['text' => $dsType['sn'], 'class' => 'label ' . $dsType['labelClass'], 'icon' => $dsType['icon']];

		$dsConditions = $this->app()->cfgItem('hosting.core.dsConditions');
		$dsCondition = $dsConditions[$recData['condition']] ?? ['sn' => 'invalid condition `'.$recData['condition'].'`', 'icon' => 'system/iconWarning', 'labelClass' => 'label-danger'];


		$today = utils::today();
		if ($recData['condition'] === 1)
		{
			$lc = [
				'text' => $dsCondition['sn'], 'class' => 'label ' . $dsCondition['labelClass'],
				'icon' => $dsCondition['icon'], 'suffix' => utils::datef($recData['dateTrialEnd'], '%S'),
			];

			if (utils::dateIsBlank($recData['dateTrialEnd']))
			{
				$lc['suffix'] = 'Vadné datum';
				$lc['class'] = 'label label-danger';
				$lc['icon'] = 'system/iconWarning';
			}
			elseif (!utils::dateIsBlank($recData['dateTrialEnd']) && $recData['dateTrialEnd'] < $today)
			{
				$lc['class'] = 'label label-danger';
			}
			$labels['condition'][] = $lc;
		}
		else
		{
			$labels['condition'][] = [
				'text' => $dsCondition['sn'], 'class' => 'label ' . $dsCondition['labelClass'],
				'icon' => $dsCondition['icon']
			];
		}

		if ($recData['dsDemo'] !== 0)
		{
			$labels['condition'][] = ['text' => 'Demo', 'class' => 'label label-primary', 'icon' => 'user/paintBrush'];
		}

		$installModule = $this->app()->cfgItem('hosting.core.installModules.'.$recData['installModule'], NULL);
		if ($installModule)
		{
			//$labels['condition'][] = ['text' => $installModule['sn'], 'class' => 'label label-info', 'icon' => 'tables/e10.install.modules'];
		}
		else
			$labels['condition'][] = ['text' => 'Vadný modul', 'class' => 'label label-warning', 'icon' => 'system/iconWarning'];

		if ($recData['shpGeneration'] !== 0)
		{
			$generations = $this->columnInfoEnum('shpGeneration', 'cfgText');
			$labels['generation'][] = ['text' => $generations[$recData['shpGeneration']], 'class' => 'label label-info'];
		}

		$itTypes = $this->app()->cfgItem('hosting.core.invoicingTo');
		$itType = $itTypes[$recData['invoicingTo']] ?? ['sn' => 'invalid invoicing `'.$recData['invoicingTo'].'`', 'icon' => 'system/iconWarning', 'labelClass' => 'label-danger'];
		$labels['invoicing'][] = ['text' => $itType['sn'], 'class' => 'label label-default', 'icon' => 'system/iconMoney'];

		return $labels;
	}

	public function tableIcon($recData, $options = NULL)
	{
		$iconSet = ['system/iconCheck', 'icon-flask', 'icon-eye', 'icon-user-secret', 'icon-question'];
		return $iconSet[$recData['dsType']];
	}

	public function getPlan($data)
	{
		$plans = $this->app()->cfgItem('hosting.core.pricePlans');
		$priceModules = $this->app()->cfgItem('hosting.core.priceModules');
		$statsData = json::decode ($data['data']);
		if (!$statsData)
			$statsData = [];

		$totalSize = $data['usageTotal'] ?? 0;
		$totalSizeGB = round($data['usageTotal'] / (1024 * 1024 * 1024), 1);
		$cntDocs12m = $data['cntDocuments12m'] ?? 0;
		$cntCashRegs12m = $data['cntCashRegs12m'] ?? 0;
		$cntDocs = $cntDocs12m - $cntCashRegs12m + intval($cntCashRegs12m / 100);

		$plan = NULL;
		$planPriceCam = 0;
		$planPriceLANDevice = 0;
		$planPriceCam = 0;
		$planPriceZUSStudium = 0;

		foreach ($plans as $p)
		{
			$plan = $p;
			if (isset($plan['priceCam']))
				$planPriceCam = $plan['priceCam'];
			if (isset($plan['priceLANDevice']))
				$planPriceLANDevice = $plan['priceLANDevice'];

			if (isset($plan['priceLANDevice']))
				$planPriceZUSStudium = $plan['priceZUSStudium'];

			if ($cntDocs > $p['maxDocs'])
				continue;

			if ($totalSizeGB > $p['maxSpaceUsage'] && ($p['allowExtraUsage'] ?? 1) === 0)
				continue;

			if ($data['invoicingTo'] == 1 && isset($p['b2bOnly']))
				continue;

			break;
		}

		$plan['extModulesPoints'] = 0;
		$plan['extraCharges'] = ['total' => 0, 'charges' => []];
		$plan['priceUsage'] = 0;


		if (isset($statsData['modules']))
		{
			foreach ($statsData['modules'] as $moduleId)
			{
				if (!isset($priceModules[$moduleId]))
					continue;
				$extraPrice = $priceModules[$moduleId]['price'];
				$plan['extraCharges']['charges'][] = [
					'price' => $extraPrice,
					'legend' => 'Modul '.$priceModules[$moduleId]['title'],
					'icon' => 'tables/e10.install.modules',
				];

				$plan['extraCharges']['total'] += $extraPrice;
			}
		}

		if (isset($statsData['extModules']))
		{
			//$extModulesLabels = [];
			foreach ($statsData['extModules'] as $emId => $em)
			{
				if ($emId === 'mac')
				{
					$cntCams = intval($em['lan']['countDevices']['10'] ?? 0);
					$cntLANDevices = intval($em['lan']['countDevices']['ALL'] ?? 0) - $cntCams;

					if (isset($em['lan']) && isset($em['lan']['countDevices']['ALL']))
					{
						/*
						$extModulesLabels[] = [
							'text' => 'Počítačová síť',
							'suffix' => utils::nf($cntLANDevices).' zařízení',
							'icon' => 'system/iconSitemap', 'class' => 'label label-info'
						];
						*/
						$plan['extModulesPoints'] += intval($em['lan']['countDevices']['ALL']);

						if ($cntLANDevices)
						{
							$oneDevicePrice = $planPriceLANDevice;
							$extraPrice = $cntLANDevices * $oneDevicePrice;
							$plan['extraCharges']['charges'][] = [
								'price' => $extraPrice,
								'legend' => $cntLANDevices.' zařízení × '.$oneDevicePrice,
								'icon' => 'system/iconSitemap',
							];
						}

						$plan['extraCharges']['total'] += $extraPrice;
					}
					if (isset($em['lan']) && isset($em['lan']['countDevices']['10']))
					{
						/*
						$extModulesLabels[] = [
							'text' => 'Kamerový systém',
							'suffix' => utils::nf($cntCams).' kamer',
							'icon' => 'user/videoCamera', 'class' => 'label label-info'
						];
						*/
						$oneCamPrice = $planPriceCam;
						$extraPrice = $cntCams * $oneCamPrice;
						$plan['extraCharges']['charges'][] = [
							'price' => $extraPrice,
							'legend' => $cntCams.' kamer × '.$oneCamPrice,
							'icon' => 'user/videoCamera',
						];

						$plan['extraCharges']['total'] += $extraPrice;
					}

					/*
					if (isset($em['iot']) && isset($em['iot']['countDevices']['ALL']))
					{
						$extModulesLabels[] = [
							'text' => 'Počítačová síť',
							'suffix' => utils::nf($cntLANDevices).' zařízení',
							'icon' => 'system/iconSitemap', 'class' => 'label label-info'
						];
						$plan['extModulesPoints'] += intval($em['iot']['countDevices']['ALL']);

						if ($cntLANDevices)
						{
							$oneDevicePrice = $planPriceLANDevice;
							$extraPrice = $cntLANDevices * $oneDevicePrice;
							$plan['extraCharges']['charges'][] = [
								'price' => $extraPrice,
								'legend' => $cntLANDevices.' zařízení × '.$oneDevicePrice,
								'icon' => 'system/iconSitemap',
							];
						}

						$plan['extraCharges']['total'] += $extraPrice;
					}
					*/
				}

				if ($emId === 'zus')
				{
					if (isset($em['studies']) && isset($em['studies']['count']['ALL']))
					{
						/*
						$extModulesLabels[] = [
							'text' => 'ZUŠ',
							'suffix' => Utils::nf($em['studies']['count']['ALL']).' studií',
							'icon' => 'tables/e10pro.zus.predmety', 'class' => 'label label-info'
						];
						*/

						$cntStudies = intval($em['studies']['count']['ALL']);
						$extraPrice = $cntStudies * $planPriceZUSStudium;
						$plan['extraCharges']['charges'][] = [
							'price' => $extraPrice,
							'legend' => $cntStudies.' studií × '.$planPriceZUSStudium,
							'icon' => 'tables/e10pro.zus.predmety',
						];

						$plan['extraCharges']['total'] += $extraPrice;
					}
				}
			}
		}

		$plan['numberUserdDocs'] = $cntDocs;
		$plan['priceDocs'] = $plan['price'];
		$usageLimit = $plan['maxSpaceUsage'];
		$usageBlockPrice = $plan['extraSpaceBlockPrice'] ?? 0;
		$usageBlockSize = $plan['extraSpaceBlockSize'] ?? 0;
		$usageNow = round($data['usageTotal'] / (1024 * 1024 * 1024), 1);
		if ($usageNow > $usageLimit)
		{
			$usageBlocksToPay = intval(($usageNow - $usageLimit) / $usageBlockSize + 1);
			$plan['priceUsage'] += $usageBlockPrice * $usageBlocksToPay;
			$plan['priceUsageLegend'] = $usageBlocksToPay.' × '.$usageBlockPrice.' Kč / '.$usageBlockSize.' GB';

			$plan['extraCharges']['charges'][] = [
				'price' => $plan['priceUsage'],
				'legend' => $usageBlocksToPay.' × '.$usageBlockPrice.' Kč / '.$usageBlockSize.' GB',
				'icon' => 'system/iconDatabase',
			];

			$plan['extraCharges']['total'] += $plan['priceUsage'];
		}

		$plan['priceUsage'] = $plan['extraCharges']['total'];
		$plan['extModulesPrice'] = $plan['extModulesPoints'] * 10;
		$plan['priceTotal'] = $plan['priceDocs'] + $plan['priceUsage'] /*+ $plan['extModulesPrice']*/;

		return $plan;
	}

	public function getPlansLegend()
	{
		$t = [];

		$plans = $this->app()->cfgItem('hosting.core.pricePlans');

		$prevPlan = NULL;
		foreach ($plans as $p)
		{
			$prevMaxDocs = isset($prevPlan['maxDocs']) ? $prevPlan['maxDocs'] + 1 : 0;
			$docs = utils::nf($prevMaxDocs).' až '.utils::nf($p['maxDocs']);

			$item = [
				'title' => $p['title'],
				'maxDocs' => $docs, //$p['docs'],
				'price' => $p['price'],
				'maxUsage' => $p['maxSpaceUsage'],
				'notes' => [],
			];

			if (isset($p['b2bOnly']))
				$item['notes'][] = ['text' => 'Neveřejný tarif pouze pro partnery', 'class' => 'break'];

			if (isset($p['extraSpaceBlockPrice']))
				$item['notes'][] = ['text' => 'Příplatek '.$p['extraSpaceBlockPrice'].' Kč / každých '.$p['extraSpaceBlockSize'].' GB', 'class' => 'break'];
			else
				$item['notes'][] = ['text' => 'Úložiště nelze navýšit', 'class' => 'break'];


			$t[] = $item;

			$prevPlan = $p;
		}

		$h = [
			'title' => 'Tarif',
			'maxDocs' => ' Počet dokladů / rok',
			'maxUsage' => ' Max. velikost v GB',
			'price' => ' Cena / měsíc',
			'notes' => 'Poznámky',
		];

		return ['table' => $t, 'header' => $h];
	}

	public function getUsersHelpdeskDataSources()
	{
		$dataSources = [];

		// -- partners persons
		$partnersNdxs = [];
		$qpp = [];
		array_push($qpp, 'SELECT * FROM [hosting_core_partnersPersons]');
		array_push($qpp, ' WHERE [person] = %i', $this->app()->userNdx());
		array_push($qpp, ' AND [docState] = %i', 4000);
		array_push($qpp, ' AND [isSupport] = %i', 1);
		$rows = $this->db()->query($qpp);
		foreach ($rows as $r)
		{
			if (!in_array($r['partner'], $partnersNdxs))
				$partnersNdxs[] = $r['partner'];
		}

		// partners datasources
		$qpds = [];
		array_push($qpds, 'SELECT * FROM [hosting_core_dataSources]');
		array_push($qpds, ' WHERE [partner] IN %in', $partnersNdxs);
		array_push($qpds, ' AND [docState] = %i', 4000);
		$rows = $this->db()->query($qpds);
		foreach ($rows as $r)
		{
			$dataSources[$r['ndx']] = 2;
		}

		// persons datasources
		$qpds = [];
		array_push($qpds, 'SELECT * FROM [hosting_core_dsPersons]');
		array_push($qpds, ' WHERE [person] = %i', $this->app()->userNdx());
		array_push($qpds, ' AND [docState] = %i', 4000);
		array_push($qpds, ' AND [isSupport] = %i', 1);
		$rows = $this->db()->query($qpds);
		foreach ($rows as $r)
		{
			$dataSources[$r['dataSource']] = 2;
		}

		return $dataSources;
	}

	public function getHelpdeskDataSourcePersons($dsNdx)
	{
		$persons = [];

		$dataSourceRecData = $this->app()->loadItem($dsNdx, 'hosting.core.dataSources');
		if (!$dataSourceRecData)
			return $persons;

		// -- dataSource partner persons
		$qpp = [];
		array_push($qpp, 'SELECT * FROM [hosting_core_partnersPersons]');
		array_push($qpp, ' WHERE [partner] = %i', $dataSourceRecData['partner']);
		array_push($qpp, ' AND [docState] = %i', 4000);
		array_push($qpp, ' AND [isSupport] = %i', 1);
		$rows = $this->db()->query($qpp);
		foreach ($rows as $r)
		{
			if (!in_array($r['person'], $persons))
				$persons[] = $r['person'];
		}

		// -- dataSource persons
		$qpds = [];
		array_push($qpds, 'SELECT * FROM [hosting_core_dsPersons]');
		array_push($qpds, ' WHERE [dataSource] = %i', $dsNdx);
		array_push($qpds, ' AND [docState] = %i', 4000);
		array_push($qpds, ' AND [isSupport] = %i', 1);
		$rows = $this->db()->query($qpds);
		foreach ($rows as $r)
		{
			if (!in_array($r['person'], $persons))
				$persons[] = $r['person'];
		}

		return $persons;
	}
}


/**
 * Class ViewDataSources
 */
class ViewDataSources extends TableView
{
	var $modules;
	var $dsStats = [];

	var $partner = 0;

	public function init ()
	{
		parent::init();

		if (!$this->partner)
			$this->setPanels (TableView::sptQuery|TableView::sptReview);

		$mq [] = ['id' => 'valid', 'title' => 'Platné', 'icon' => 'system/iconDatabase'];
		$mq [] = ['id' => 'active', 'title' => 'Aktivní', 'side' => 'left', 'icon' => 'icon-check-square'];
		$mq [] = ['id' => 'online', 'title' => 'Online', 'side' => 'left', 'icon' => 'icon-bolt'];

		$mq [] = ['id' => 'nonactive', 'title' => 'Neaktivní', 'icon' => 'icon-ban'];
		$mq [] = ['id' => 'archive', 'title' => 'Archív', 'icon' => 'system/filterArchive'];
		$mq [] = ['id' => 'all', 'title' => 'Vše', 'icon' => 'system/filterAll'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš', 'icon' => 'system/filterTrash'];

		$this->setMainQueries ($mq);

		$this->modules = $this->table->app()->cfgItem ('e10pro.hosting.modules');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		if ($item['imageUrl'] !== '')
		{
			$listItem ['svgIcon'] = $item['imageUrl'];
		}
		elseif ($item['dsEmoji'] !== '')
		{
			$listItem ['emoji'] = $item['dsEmoji'];
		}
		elseif ($item['dsIcon'] !== '')
		{
			$listItem ['icon'] = $item['dsIcon'];
		}
		else
		{
			$listItem ['icon'] = 'system/iconDatabase';
		}

		$listItem ['t1'] = $item['name'];
		$listItem ['i1'] = ['text' => '#'.$item['gid'], 'class' => 'id'];

		if ($item['appWarning'] != 0)
			$listItem ['class'] = 'e10-row-minus';

		$props3 = [];

		$props = $this->table->dsStateLabels($item);

		$listItem ['i2'] = [];

		//if ($item['partnerName'])
		//	$listItem ['i2'][] = ['icon' => 'tables/hosting.core.partners', 'text' => $item['partnerName'], 'class' => ''];

		/*
		if ($item['serverName'])
			$listItem ['i2'][] = ['icon' => 'tables/hosting.core.servers', 'text' => $item['serverName'], 'class' => ''];
		else
			$listItem ['i2'][] = ['icon' => 'tables/hosting.core.servers', 'text' => '---', 'class' => 'e10-warning2'];
		*/

		if ($item['dsId1'] !== '')
		{
			$dsId = ['text' => '@'.$item['dsId1'], 'class' => ''];

			if (($item['dsId2'] !== ''))
				$dsId['suffix'] = $item['dsId2'];
			$listItem ['i2'][] = $dsId;
		}

		if (count($props))
			$listItem ['t2'] = $props;
		if (count($props3))
			$listItem ['t3'] = $props3;

		if ($item['inProgress'])
			$listItem ['class'] = 'e10-row-this';

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset($this->dsStats[$item ['pk']]))
		{
			//$plan = $this->table->getPlan($this->dsStats[$item ['pk']]);
			//$item ['t3'][] = ['text' => $plan['title'], 'class' => 'label label-info pull-right', 'icon' => 'icon-money'];
			//$item ['t3'][] = ['text' => utils::memf($this->dsStats[$item ['pk']]['usageTotal']), 'class' => 'label label-primary pull-right', 'icon' => 'icon-hdd-o'];
		}
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT ds.*, owners.fullName as ownerFullName, admins.fullName as adminFullName, partners.name as partnerName,';
		array_push ($q, ' payers.fullName as payerFullName, servers.id as serverId, servers.name as serverName');
		array_push ($q, ' FROM [hosting_core_dataSources] AS ds');
		array_push ($q, ' LEFT JOIN e10_persons_persons as owners ON ds.owner = owners.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons as admins ON ds.admin = admins.ndx');
		array_push ($q, ' LEFT JOIN e10_persons_persons as payers ON ds.payer = payers.ndx');
		array_push ($q, ' LEFT JOIN hosting_core_servers AS servers ON ds.server = servers.ndx');
		array_push ($q, ' LEFT JOIN hosting_core_partners AS partners ON ds.partner = partners.ndx');
		array_push ($q, ' WHERE 1');

		if ($this->partner)
			array_push ($q, ' AND ds.[partner] = %i', $this->partner);

		// -- fulltext
		if ($fts != '')
		{
			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;

 			array_push ($q, ' AND (');
			array_push ($q, ' ds.[name] LIKE %s OR ds.[gid] LIKE %s', '%'.$fts.'%', $fts.'%');
			array_push ($q, ' OR servers.id LIKE %s', $fts.'%');
			if ($ascii)
			{
				array_push($q, ' OR ds.dsId1 LIKE %s', '%' . $fts . '%');
				array_push($q, ' OR ds.dsId2 LIKE %s', '%' . $fts . '%');
			}
			array_push ($q, ')');
		}

    // -- aktuální
		if ($mainQuery === 'valid' || $mainQuery === '' || $mainQuery === 'active' || $mainQuery === 'online'|| $mainQuery === 'nonactive' )
			array_push ($q, " AND ds.[docStateMain] < 4");

		$today = new \DateTime();
		if ($mainQuery === 'active')
		{
			$today->sub (new \DateInterval('P3M'));
			//array_push ($q, ' AND ds.lastLogin > %t', $today);
		}
		if ($mainQuery === 'online')
		{
			$today->sub (new \DateInterval('PT30M'));
		//	array_push ($q, ' AND ds.lastLogin > %t', $today);
		}
		if ($mainQuery === 'nonactive')
		{
			$today->sub (new \DateInterval('P3M'));
			//array_push ($q, ' AND ds.lastLogin < %t', $today);
		}

		// archive
		if ($mainQuery == 'archive')
      array_push ($q, " AND ds.[docStateMain] = 5");

		// koš
		if ($mainQuery == 'trash')
      array_push ($q, " AND ds.[docStateMain] = 4");

		// -- special queries
		$qv = $this->queryValues ();

		if (isset($qv['partners']))
			array_push ($q, ' AND [partner] IN %in', array_keys($qv['partners']));
		if (isset($qv['invoicingGroups']))
			array_push ($q, ' AND [invoicingGroup] IN %in', array_keys($qv['invoicingGroups']));
		if (isset($qv['servers']))
			array_push ($q, ' AND ds.[server] IN %in', array_keys($qv['servers']));
		if (isset($qv['conditions']))
			array_push ($q, ' AND [condition] IN %in', array_keys($qv['conditions']));
		if (isset($qv['pricePlanKind']))
			array_push ($q, ' AND [pricePlanKind] IN %in', array_keys($qv['pricePlanKind']));
		if (isset($qv['installModule']))
			array_push ($q, ' AND [installModule] IN %in', array_keys($qv['installModule']));
		if (isset($qv['invoicingTo']))
			array_push ($q, ' AND [invoicingTo] IN %in', array_keys($qv['invoicingTo']));
		if (isset($qv['dsTypes']))
			array_push ($q, ' AND [dsType] IN %in', array_keys($qv['dsTypes']));
		if (isset($qv['installModules']))
			array_push ($q, ' AND [installModule] IN %in', array_keys($qv['installModules']));


		$dsDemo = isset ($qv['others']['dsDemo']);
		if ($dsDemo)
			array_push($q, ' AND [dsDemo] = %i', 1);

		$toBeExpired = isset ($qv['others']['toBeExpired']);
		if ($toBeExpired)
			array_push($q, ' AND [condition] = %i', 1, ' AND (dateTrialEnd IS NOT NULL AND dateTrialEnd < %d)', $today);

		$badExpiredData = isset ($qv['others']['badExpiredData']);
		if ($badExpiredData)
			array_push($q, ' AND [condition] = %i', 1, ' AND dateTrialEnd IS NULL');

		$badCondition = isset ($qv['others']['badCondition']);
		if ($badCondition)
			array_push($q, ' AND [condition] > %i', 5);

		$badInstallModule = isset ($qv['others']['badInstallModule']);
		if ($badInstallModule)
		{
			$installModules = $this->app()->cfgItem('hosting.core.installModules');
			array_push($q, ' AND [installModule] NOT IN %in', array_keys($installModules));
		}

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY ds.[name]' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY ds.[docStateMain], ds.[name]' . $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		// -- data source stats
		$dsStats = $this->db()->query ('SELECT * FROM hosting_core_dsStats WHERE dataSource IN %in', $this->pks);
		foreach ($dsStats as $r)
			$this->dsStats[$r['dataSource']] = $r->toArray();
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- dsTypes
		$dsTypes = $this->table->columnInfoEnum('dsType');
		$this->qryPanelAddCheckBoxes($panel, $qry, $dsTypes, 'dsTypes', 'Typ');

		// -- condition
		$conditions = $this->table->columnInfoEnum('condition');
		$this->qryPanelAddCheckBoxes($panel, $qry, $conditions, 'conditions', 'Stav');

		// -- pricePlanKind
		$conditions = $this->table->columnInfoEnum('pricePlanKind');
		$this->qryPanelAddCheckBoxes($panel, $qry, $conditions, 'pricePlanKind', 'Druh tarifu');

		// -- invoicingTo
		$conditions = $this->table->columnInfoEnum('invoicingTo');
		$this->qryPanelAddCheckBoxes($panel, $qry, $conditions, 'invoicingTo', 'Fakturovat');

		// -- partners
		$partners = $this->db()->query ('SELECT ndx, name FROM hosting_core_partners WHERE docStateMain <= 2 ORDER BY name')->fetchPairs ('ndx', 'name');
		$partners[0] = 'bez partnera';
		$this->qryPanelAddCheckBoxes($panel, $qry, $partners, 'partners', 'Partneři');

		// -- invoicingGroups
		$invoicingGroups = $this->db()->query ('SELECT ndx, name FROM hosting_core_invoicingGroups WHERE docStateMain <= 2 ORDER BY name')->fetchPairs ('ndx', 'name');
		//$invoicingGroups[0] = 'bez skupiny';
		$this->qryPanelAddCheckBoxes($panel, $qry, $invoicingGroups, 'invoicingGroups', 'Fakturační skupiny');

		// -- servers
		$servers = $this->db()->query ('SELECT ndx, name FROM hosting_core_servers WHERE docStateMain <= 2 ORDER BY name')->fetchPairs ('ndx', 'name');
		$this->qryPanelAddCheckBoxes($panel, $qry, $servers, 'servers', 'Servery');

		// -- installModules
		$installModules = $this->table->columnInfoEnum('installModule');
		$this->qryPanelAddCheckBoxes($panel, $qry, $installModules, 'installModule', 'Instalační moduly');

		// -- others
		$chbxOthers = [
			'dsDemo' => ['title' => 'Demo', 'id' => 'dsDemo'],
			'toBeExpired' => ['title' => 'K expiraci', 'id' => 'toBeExpired'],
			'badExpiredData' => ['title' => 'Chybný stav expirace', 'id' => 'badExpiredData'],
			'badCondition' => ['title' => 'Vadný stav', 'id' => 'badCondition'],
			'badInstallModule' => ['title' => 'Chybný instalační modul', 'id' => 'badInstallModule'],
		];
		$paramsOthers = new \Shipard\UI\Core\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'others', 'style' => 'params', 'title' => ['text' => 'Ostatní', 'icon' => 'system/iconCogs'], 'params' => $paramsOthers];


		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createPanelContentReview (TableViewPanel $panel)
	{
		/** @var \hosting\core\libs\HostingReviewDataSources $o */
		$o = new \hosting\core\libs\HostingReviewDataSources($this->app());//$this->table->app()->createObject($classId);
		$o->partner = $this->partner;
		$o->create();
		foreach ($o->content['body'] as $cp)
			$panel->addContent($cp);
	}

	public function createToolbar()
	{
		$tlbr = [];

		if ($this->app()->hasRole('hstngdb'))
		{
			$addButton = [
				'text' => 'Nová databáze',
				'type' => 'action',
				'action' => 'addwizard', 'icon' => 'system/iconDatabase',
				'data-class' => 'hosting.core.libs.WizardNewDatasource',
				'btnClass' => 'btn btn-primary',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
			];
			$tlbr[] = $addButton;
		}

		$pb = parent::createToolbar();
		if (count($pb))
			$tlbr = array_merge($tlbr, $pb);

		return $tlbr;
	}
}


/**
 * Class ViewDetailDatasources
 * @package E10pro\Hosting\Server
 */
class ViewDetailDatasources extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('hosting.core.libs.dc.DocumentCardDataSource');
	}

	function createToolbar()
	{
		if (!$this->app()->hasRole('hstng'))
			return [];

		return parent::createToolbar();
	}
}


/**
 * Class ViewDetailDataSourceUsers
 * @package E10pro\Hosting\Server
 */
class ViewDetailDataSourceUsers extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('hosting.core.dsUsers', 'hosting.core.ViewDSUsers',
														 array ('dataSource' => $this->item ['ndx']));
	}
}


/**
 * class ViewDetailDataSourcePersons
 */
class ViewDetailDataSourcePersons extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('hosting.core.dsPersons', 'hosting.core.ViewDSPersons',
			['dataSource' => $this->item ['ndx']]);
	}
}


/**
 * Class FormDataSource
 */
class FormDataSource extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);

				$this->openTab ();
					$this->addColumnInput ('name');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('dsId1');
					$this->addSeparator(self::coH2);
					$this->addColumnInput ('installModule');
					$this->addColumnInput ('owner');
					$this->addColumnInput ('partner');
					$this->addColumnInput ('admin');
					$this->addSeparator(self::coH2);
					$this->addColumnInput ('dsType');
					if ($this->recData['dsType'] === 0)
					{
						$this->addColumnInput ('dsDemo');
						if ($this->recData['dsDemo'] == 1)
							$this->addColumnInput ('dsCreateDemoType');
					}
					$this->addColumnInput ('condition');
					$this->addColumnInput ('appWarning');
					$this->addSeparator(self::coH2);
					$this->addColumnInput ('helpdeskMode');
					$this->addColumnInput ('pricePlanKind');
					$this->addColumnInput ('invoicingTo');
					if ($this->recData['invoicingTo'] == 4)
						$this->addColumnInput ('invoicingGroup');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('dsId2');
					$this->addColumnInput ('server');
					$this->addColumnInput ('urlApp');
					$this->addColumnInput ('urlApp2');
					$this->addColumnInput ('gid');
					$this->addColumnInput ('dateStart');
					$this->addColumnInput ('dateTrialEnd');
					$this->addColumnInput ('dsIcon');
					$this->addColumnInput ('dsEmoji');
					$this->addColumnInput ('shpGeneration');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();

			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class FormDataSourceShow
 */
class FormDataSourceShow extends TableFormShow
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltNone);
			$this->addDocumentCard('e10pro.hosting.server.DocumentCardDataSource');
		$this->closeForm ();
	}
}
