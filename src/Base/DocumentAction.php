<?php

namespace Shipard\Base;

abstract class DocumentAction extends \Shipard\Base\Utility
{
	protected $params = NULL;

	public function actionParams()
	{
		return FALSE;
	}

	public function init ()
	{

	}

	public function setParams ($params)
	{
		$this->params = $params;
	}

	public function run ()
	{

	}
}
