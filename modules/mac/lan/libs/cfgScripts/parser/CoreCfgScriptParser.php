<?php

namespace mac\lan\libs\cfgScripts\parser;

use e10\Utility;


/**
 * Class CoreCfgScriptParser
 * @package mac\lan\libs\cfgScripts\parser
 */
class CoreCfgScriptParser extends Utility
{
	var $srcScript = '';
	var $srcScriptRows = NULL;
	var $parsedData = [];

	var $inDevShipardCfgVer = '--none--';

	public function setSrcScript($srcScript)
	{
		$this->srcScript = $srcScript;
	}

	public function parse()
	{
		$this->srcScriptRows = preg_split("/\\r\\n|\\r|\\n/", $this->srcScript);

		while(1)
		{
			if (!$this->parseNextRow())
				break;
		}

		$this->postParse();
	}

	protected function parseNextRow()
	{
		$row = array_shift($this->srcScriptRows);
		if ($row === NULL)
			return FALSE;

		return TRUE;
	}

	protected function postParse()
	{
	}
}