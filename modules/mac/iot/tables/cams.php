<?php

namespace mac\iot;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;


/**
 * Class TableCams
 */
class TableCams extends DbTable
{
	CONST
		ctFrigate = 'frigate',
		ctGo2RTC = 'go2rtc',
		ctLanIP = '30',
		ctIBEsp32 = 'esp32cam';

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.cams', 'mac_iot_cams', 'Kamery');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		//$recData['uid'] = '';

		return $recData;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		//return $this->app()->cfgItem ('mac.control.controlsKinds.'.$recData['controlKind'].'.icon', 'x-cog');

		return parent::tableIcon($recData, $options);
	}

	public function camInfo($cameraNdx)
	{
		$camRecData = $this->loadItem($cameraNdx);
		if (!$camRecData)
		{
			error_log("!!!NO-CAMERA!!!");
			return NULL;
		}

    $lanNdx = $camRecData['lan'];
		$lanRecData = $this->app()->loadItem($lanNdx, 'mac.lan.lans');
		$camServerNdx = $lanRecData['mainServerCameras'];

		$camInfo = [
			'ndx' => $cameraNdx,
			'camRecData' => $camRecData,
			'camServerNdx' => $camServerNdx,
			'camType' => $camRecData['camType'],
		];

		if ($lanRecData['enableVehicleDetect'] ?? 0)
			$camInfo['enableVehicleDetect'] = intval($lanRecData['enableVehicleDetect']);

		$server = $this->app->cfgItem('mac.localServers.'.$camInfo['camServerNdx'], NULL);
		if (!$server)
		{
			error_log("!!!NO-SERVER!!!");
			return NULL;
		}

		$camInfo['serverInfo'] = [
			'camUrl' => $server['camerasURL'],
		];

		if ($camRecData['camType'] === TableCams::ctFrigate)
		{
			$streamUrl = 'https://'.$server['camFrigateFQDN'].':'.$server['camGo2RTCPort'].'/';
			$camInfo['streamUrl'] = $streamUrl;
			$camInfo['streamId'] = ($camRecData['streamId'] !== '') ? $camRecData['streamId'] : $camRecData['id'];
			$camInfo['streamType'] = 'go2rtc';

			//$camInfo['go2rtcUser'] = $server['camGo2RTCUser'] ?? '';
			//$camInfo['go2rtcPassword'] = $server['camGo2RTCPassword'] ?? '';

			//error_log("GO2RTC USER: ".$camInfo['go2rtcUser'].", PASSWORD: ".$camInfo['go2rtcPassword']);

			//$loginInfo = ['salt' => time(), 'user' => $camInfo['camGo2RTCUser'], 'password' => $camInfo['go2rtcPassword']];
			//$loginInfoStr = base64_encode(json_encode($loginInfo));
			$camInfo['go2rtcApiLoginInfo'] = $server['go2rtcApiLoginInfo'] ?? '';
		}

		$streams = $this->db()->query('SELECT * FROM [mac_iot_camsStreams] WHERE [iotCam] = %i', $cameraNdx, ' ORDER BY rowOrder, ndx');
		foreach ($streams as $s)
		{
			$camInfo['streams'][] = [
				'type' => $s['streamType'],
				'url' => $s['streamUrl'],
			];
		}

		return $camInfo;
	}
}


/**
 * Class ViewCams
 */
class ViewCams extends TableView
{
	/** @var \mac\lan\TableDevices */
	var $tableDevices;

	var $devicesKinds;

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->devicesKinds = $this->app()->cfgItem ('mac.lan.devices.kinds');
		$this->tableDevices = $this->app()->table('mac.lan.devices');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];
		if ($item['enableVehicleDetect'])
		{
			$vdt = $this->app()->cfgItem('mac.iot.cams.vdt.'.$item['enableVehicleDetect'], NULL);
			$props[] = ['text' => $vdt['fn'] ?? '!!!', 'icon' => 'user/truck', 'class' => 'label label-info'];
		}

		if ($item['camType'] == 30)
		{
			$props[] = [
				'text' => $item['lanDeviceFullName'] ?? '!!!',
				'icon' => 'tables/mac.lan.lans', 'class' => 'label label-info',
				'suffix' => '#'.$item['lanDevice'],
			];
		}

		$listItem['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [cams].*, ';
		array_push ($q, ' lanDevices.fullName AS [lanDeviceFullName]');
		array_push ($q, ' FROM [mac_iot_cams] AS [cams]');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS lanDevices ON cams.lanDevice = lanDevices.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [cams].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'cams.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormCam
 */
class FormCam  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Streamy', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		//$iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('camType');
					$this->addSeparator(self::coH2);
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('id');
					$this->addSeparator(self::coH4);

					if ($this->recData['camType'] === TableCams::ctLanIP)
					{
						$this->addColumnInput ('lanDevice');
						$this->addColumnInput ('lan');
						$this->addColumnInput ('enableVehicleDetect');
					}
					elseif ($this->recData['camType'] === TableCams::ctFrigate)
					{
						$this->addColumnInput ('streamId');
						$this->addColumnInput ('lanDevice');
						$this->addColumnInput ('lan');
					}
					elseif ($this->recData['camType'] === TableCams::ctGo2RTC)
					{
						$this->addColumnInput ('streamUrl');
						$this->addColumnInput ('streamId');
						$this->addColumnInput ('lanDevice');
						$this->addColumnInput ('lan');
					}
					elseif ($this->recData['camType'] === TableCams::ctIBEsp32)
					{
						$this->addColumnInput ('iotDevice');
					}
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addList('streams');
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailCam
 */
class ViewDetailCam extends TableViewDetail
{
}

/**
 * Class ViewDetailCamCfg
 */
class ViewDetailCamCfg extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('mac.iot.libs.dc.CamCfg');
	}
}
