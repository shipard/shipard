<?php

namespace mac\iot\libs;

use e10\Utility, \e10\utils, \e10\json, mac\iot\TableSensors, \mac\iot\TableControls;


/**
 * Class Control
 * @package mac\iot\libs
 */
class Control extends Utility
{
	var $controlNdx = 0;
	var $controlRecData = NULL;

	/** @var \mac\iot\TableControls */
	var $tableControls;

	function load()
	{
		$this->tableControls = $this->app()->table('mac.iot.controls');

		$this->controlRecData = $this->tableControls->loadItem($this->controlNdx);
	}

	public function setControl($controlNdx)
	{
		$this->controlNdx = $controlNdx;
		$this->load();
	}

	public function controlCode()
	{
		$controlButton = [
			'text' => $this->controlRecData['shortName'],

			'action' => 'inline-action',
			'class' => 'pl1',

			'icon' => 'system/iconCheck',
			'btnClass' => 'btn-primary',
			'actionClass' => 'df2-action-trigger',

			'data-object-class-id' => 'mac.iot.libs.IotAction',
			'data-action-param-control' => $this->controlRecData['uid']
		];

		if ($this->controlRecData['controlType'] === 'setDeviceProperty')
		{
			$controlButton['data-action-param-action-type'] = 'set-device-property';
		}
		elseif ($this->controlRecData['controlType'] === 'sendSetupRequest')
		{
			$controlButton['data-action-param-action-type'] = 'send-setup-request';
			$controlButton['data-action-param-setup'] = $this->controlRecData['iotSetup'];
			$controlButton['data-action-param-setup-request'] = $this->controlRecData['iotSetupRequest'];
		}
		elseif ($this->controlRecData['controlType'] === 'sendMqttMsg')
		{
			$controlButton['data-action-param-action-type'] = 'send-mqtt-msg';
		}

		$c = $this->app()->ui()->renderTextLine($controlButton);
		return $c;
	}
}
