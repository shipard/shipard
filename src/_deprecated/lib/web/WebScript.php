<?php


namespace lib\web;

use \e10\Utility;


/**
 * Class WebScript
 * @package lib\web
 */
class WebScript extends Utility
{
	var $scriptId = '';
	var $scriptRecData = NULL;
	var $resultCode = '';

	function setScriptId ($id)
	{
		if (is_array($id))
		{

		}
		else
			$this->scriptId = $id;
	}

	function loadScript()
	{
		$rec = $this->db()->query('SELECT * FROM [e10_web_scripts] WHERE [id] = %s', $this->scriptId)->fetch();
		if (!$rec)
		{
			$this->err ("* script `{$this->scriptId}` not found");
			return;
		}

		$this->scriptRecData = $rec->toArray();
	}

	function runScript($data = NULL, $removeArrayKeys = TRUE)
	{
		$this->loadScript();
		if (!$this->scriptRecData)
			return;

		$t = new \e10\TemplateCore($this->app());
		foreach ($data as $k => $v)
		{
			if ($removeArrayKeys && is_array($v))
				$t->data[$k] = array_values($v);
			else
				$t->data[$k] = $v;
		}
		$this->resultCode = $t->render($this->scriptRecData['code']);
	}
}

