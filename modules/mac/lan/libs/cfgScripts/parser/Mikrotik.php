<?php

namespace mac\lan\libs\cfgScripts\parser;

use e10\Utility;


/**
 * Class Mikrotik
 * @package mac\lan\libs\cfgScripts\parser
 */
class Mikrotik extends \mac\lan\libs\cfgScripts\parser\CoreCfgScriptParser
{
	protected function parseNextRow()
	{
		$row = array_shift($this->srcScriptRows);
		if ($row === NULL)
			return FALSE;
		if ($row === '' || $row[0] === '#')
			return TRUE;

		$this->parseRow($row);



		return TRUE;
	}

	function parseRow($row)
	{
		$params = preg_split("/ (?=[^\"]*(\"[^\"]*\"[^\"]*)*$)/", $row);
		$cmd = ['type' => '?', 'params' => []];

		$cmdRoot = '';
		$cmdRootChunks = [];

		while(1)
		{
			$prm = array_shift($params);
			if ($prm === NULL)
				break;

			if ($prm === 'add' || $prm === 'set')
			{
				$cmd['type'] = $prm;
				$cmdRoot = implode(' ', $cmdRootChunks);
				break;
			}
			$cmdRootChunks[] = $prm;
		}

		while(1)
		{
			$prm = array_shift($params);
			if ($prm === NULL)
				break;

			$this->parseCmd($prm, $cmd['params']);

		}

		$this->parsedData[$cmdRoot][] = $cmd;
	}

	function parseCmd($prm, &$addTo)
	{
		$assignmentMark = strstr($prm, '=');
		if ($assignmentMark === FALSE)
		{
			$addTo[$prm] = NULL;
			return;
		}

		$k = strstr($prm, '=', TRUE);
		$v = substr($assignmentMark, 1);
		if ($v[0] === '"')
			$v = substr($v, 1, -1);

		$addTo[$k] = $v;
	}

	protected function postParse()
	{
		if (!isset($this->parsedData['/system note']) || !isset($this->parsedData['/system note'][0]['params']))
			return;
		$verInfo = $this->parsedData['/system note'][0]['params']['note'] ?? 'none';
		$parts = explode(':', $verInfo);
		if (count($parts) != 2 || $parts[0] !== 'shipard-cfg-version')
			return;
		$this->inDevShipardCfgVer = trim($parts[1]);
	}
}
