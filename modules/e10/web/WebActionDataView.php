<?php

namespace e10\web;

use \e10\web\WebAction, \e10\utils;


/**
 * Class WebActionDataView
 * @package e10\web
 */
class WebActionDataView extends WebAction
{
	var $dataViewClassId = '';
	var $dataViewRequestParams = [];

	protected function doIt()
	{
		$o = $this->app()->createObject($this->dataViewClassId);
		if (!$o)
		{
			return FALSE;
		}

		$o->isRemoteRequest = 1;
		$o->setRequestParams($this->dataViewRequestParams);
		$o->run();

		$this->result['html'] = $o->data['html'];

		$this->setSuccess();
	}

	public function run ()
	{
		$this->dataViewClassId = isset($this->params['data-view-class-id']) ? $this->params['data-view-class-id'] : '';

		if (isset($this->params['data-view-request-params']))
		{
			$this->dataViewRequestParams = json_decode(base64_decode($this->params['data-view-request-params']), TRUE);
		}

		$this->doIt();
	}
}