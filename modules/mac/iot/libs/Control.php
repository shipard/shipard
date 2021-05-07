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
	var $thingRecData = NULL;

	/** @var \mac\iot\TableControls */
	var $tableControls;
	/** @var \mac\iot\TableThings */
	var $tableThings;

	function load()
	{
		$this->tableControls = $this->app()->table('mac.iot.controls');
		$this->tableThings = $this->app()->table('mac.iot.things');

		$this->controlRecData = $this->tableControls->loadItem($this->controlNdx);

		if ($this->controlRecData['targetType'] === TableControls::cttIoTThingAction)
		{
			$this->thingRecData = $this->tableThings->loadItem($this->controlRecData['dstIoTThing']);
		}
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
			//'class' => 'pull-right',

			'icon' => 'icon-check',
			'btnClass' => 'btn-primary',
			'actionClass' => 'df2-action-trigger',

			'data-object-class-id' => 'mac.iot.libs.IotAction',
			'data-action-param-control' => $this->controlRecData['uid']
		];

		if ($this->controlRecData['targetType'] === TableControls::cttIoTThingAction)
		{
			$controlButton['data-action-param-action-type'] = 'thing-action';
			$controlButton['data-action-param-thing'] = $this->thingRecData['uid'];
			$controlButton['data-action-param-thing-action'] = $this->controlRecData['dstIoTThingAction'];
		}

		$c = $this->app()->ui()->renderTextLine($controlButton);
		return $c;
	}
}
