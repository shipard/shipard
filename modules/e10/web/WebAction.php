<?php

namespace e10\web;

use e10\utils, e10\Utility;


/**
 * Class WebAction
 * @package e10\web
 */
class WebAction extends Utility
{
	var $error = 0;
	var $params = NULL;
	var $result = ['success' => 0];

	public function setParams($params)
	{
		$this->params = $params;
	}

	function setSuccess()
	{
		$this->error = 0;
		$this->result['success'] = 1;
	}

	public function run()
	{

	}
}