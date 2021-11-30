<?php

namespace mac\iot\libs;

use \e10\utils, \e10\json, mac\iot\TableSensors, e10\E10ApiObject;


/**
 * Class IotAction
 * @package mac\iot\libs
 */
class IotAction extends E10ApiObject
{
	var $userNdx = 0;
	var $actionType = '';

	var $lanNdx = 0;
	var $lanRecData = NULL;
	var $lanControlURL = '';

	var $paramsError = 1;

	/** @var \mac\iot\TableControls */
	var $tableControls;
	/** @var \mac\iot\TableThings */
	var $tableThings;
	/** @var \mac\lan\TableLans */
	var $tableLans;
	/** @var \mac\lan\TableDevices */
	var $tableDevices;


	var $controlRecData = NULL;
	var $thingRecData = NULL;
	var $thingKindCfg = NULL;

	var $requestData = [];

	public function init ()
	{
		$this->userNdx = $this->app()->userNdx();
		$this->tableControls = $this->app()->table('mac.iot.controls');
		//$this->tableThings = $this->app()->table('mac.iot.things');
		$this->tableLans = $this->app()->table('mac.lan.lans');
		$this->tableDevices = $this->app()->table('mac.lan.devices');


		//error_log("@@@ IOT-ACTION: ".json_encode($this->requestParams));

		$this->actionType = $this->requestParam ('action-type', '');
		if ($this->actionType === '')
		{

			return;
		}

		/*
		if ($this->actionType === 'thing-action')
		{
			$this->controlRecData = $this->tableControls->loadRecData('@uid:'.$this->requestParam('control', '---'));
			if (!$this->controlRecData)
				return;

			$this->thingRecData = $this->tableThings->loadRecData('@uid:'.$this->requestParam('thing', '---'));
			if (!$this->thingRecData)
				return;

			$this->thingKindCfg = $this->app()->cfgItem('mac.iot.things.kinds.'.$this->thingRecData['thingKind'], NULL);
			if (!$this->thingKindCfg)
				return;

			$thingAction = $this->requestParam('thing-action');
			if ($thingAction == '')
				return;

			$this->lanNdx = $this->controlRecData['lan'];

			$this->requestData = [
				'actionType' => $this->actionType,
				'iotControl' => $this->controlRecData['uid'],
				'thing' => $this->thingRecData['id'],
				'thingAction' => $thingAction
			];
		}*/

		if ($this->actionType === 'set-scene')
		{
			$this->iotDevicesUtils = new \mac\iot\libs\IotDevicesUtils($this->app());
			$placeNdx = $this->requestParam('place', 0);
			$placeTopic = $this->iotDevicesUtils->placeTopic($placeNdx);
			if ($placeTopic === '')
				return;

			$sceneNdx = $this->requestParam('scene', 0);

			/*
			$sceneRecData = $this->app()->loadItem($sceneNdx, 'mac.iot.scenes');
			if (!$sceneRecData)
			{
				return;
			}
			*/

			$sceneTopic = $this->iotDevicesUtils->sceneTopic($sceneNdx);
			if ($placeTopic === '')
				return;
			$payloadData = ['scene' => $sceneTopic];

			$this->lanNdx = 1;//$this->controlRecData['lan'];
			$this->requestData = [
				'actionType' => 'mqtt-publish',
				'mqttTopic' => $placeTopic.'/set',
				'mqttPayload' => json_encode($payloadData),
			];
		}

		$this->loadLanIfo();

		$this->paramsError = 0;
	}

	function loadLanIfo()
	{
		if (!$this->lanNdx)
			return;

		$this->lanRecData = $this->tableLans->loadItem($this->lanNdx);
		if (!$this->lanRecData)
			return;

		$lanControllerNdx = $this->lanRecData['mainServerLanControl'];
		if (!$lanControllerNdx)
			return;

		$lanControllerRecData = $this->tableDevices->loadItem($lanControllerNdx);
		if (!$lanControllerRecData)
			return;

		$macDeviceCfg = json_decode($lanControllerRecData['macDeviceCfg'], TRUE);
		if (!$macDeviceCfg)
			return;


		if (!isset($macDeviceCfg['serverFQDN']) || $macDeviceCfg['serverFQDN'] === '')
			return;

		$httpsPort = (isset($macDeviceCfg['httpsPort']) && (intval($macDeviceCfg['httpsPort']))) ? intval($macDeviceCfg['httpsPort']) : 443;

		$this->lanControlURL = 'https://'.$macDeviceCfg['serverFQDN'];
		if ($httpsPort !== 443)
			$this->lanControlURL .= ':'.$httpsPort;
		$this->lanControlURL .= '/';
	}

	function runAction()
	{
		error_log("___IOT_ACTION___");
		if ($this->paramsError)
		{
			error_log("___PARAM_ERROR___");
			return FALSE;
		}	

		if ($this->lanControlURL === '')
		{
			error_log("__ERROR_lanControlURL__");
			return FALSE;
		}	
		$url = $this->lanControlURL.'control/';

		$result = utils::http_post($url, json_encode($this->requestData));
		error_log("@@@ GO `$url`: --".json_encode($this->requestData)."--".json_encode($result));
	}

	public function createResponseContent($response)
	{
		$this->init();
		$this->runAction();

		//$response->add ('reloadNotifications', 1);
		$response->add ('success', 1);
	}
}

