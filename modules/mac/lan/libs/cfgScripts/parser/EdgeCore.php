<?php

namespace mac\lan\libs\cfgScripts\parser;

use e10\Utility;


/**
 * Class EdgeCore
 * @package mac\lan\libs\cfgScripts\parser
 */
class EdgeCore extends \mac\lan\libs\cfgScripts\parser\CoreCfgScriptParser
{
	var $roots = [
		'vlan database' => 'vlansList',
		'interface vlan' => 'vlansSettings',
		'interface ethernet' => 'ports',
	];

	var $flags = [
		'ntp client', 'ntp server', 'prompt', 'hostname'
	];

	protected function parseNextRow()
	{
		$row = array_shift($this->srcScriptRows);
		if ($row === NULL)
			return FALSE;
		if ($row === '' || $row[0] === '!')
			return TRUE;

		if ($this->checkRoot($row))
			return TRUE;
		if ($this->checkFlag($row))
			return TRUE;

		return TRUE;
	}

	protected function checkFlag($row)
	{
		$isFlag = $this->isFlag($row);
		if ($isFlag === FALSE)
			return FALSE;

		$this->parsedData['flags'][] = trim($row);
		return TRUE;
	}

	protected function checkRoot($row)
	{
		$cmdRoot = $this->isRoot($row);
		if ($cmdRoot === FALSE)
			return FALSE;

		$cmd = ['id' => $row, 'params' => []];

		$lastKey = FALSE;
		while(1)
		{
			$prm = array_shift($this->srcScriptRows);
			if ($prm === NULL || $prm === '' || $prm[0] === '!')
				break;

			if (substr($prm, 0, 3) === 'ip ') // 'ip dhcp snooping ...' is without leading space
				$prm = ' '.$prm;

			if ($prm[0] !== ' ' && $lastKey !== FALSE)
			{
				if ($lastKey !== NULL)
					$cmd['params'][$lastKey] .= trim($prm);
				else
					$cmd['params'][count($cmd['params']) - 1] .= trim($prm);

				continue;
			}

			$prmParts = explode(' ', trim($prm));
			$key = NULL;
			switch ($cmdRoot)
			{
				case 'vlansList': $key = $prmParts[1]; break;
			}

			if ($key === NULL)
				$cmd['params'][] = trim($prm);
			else
				$cmd['params'][$key] = trim($prm);

			$lastKey = $key;
		}

		$key = NULL;
		$rowParts = explode(' ', $row);
		switch ($cmdRoot)
		{
			case 'ports': $key = $row; break;
			case 'vlansSettings': $key = $row; break;
		}

		if ($key === NULL)
			$this->parsedData[$cmdRoot][] = $cmd;
		else
		{
			if (isset($this->parsedData[$cmdRoot][$key]))
				$this->parsedData[$cmdRoot][$key]['params'] += $cmd['params'];
			else
				$this->parsedData[$cmdRoot][$key] = $cmd;
		}

		return TRUE;
	}

	protected function isFlag($row)
	{
		foreach ($this->flags as $flagText)
		{
			$l = strlen ($flagText);
			if (substr($row, 0, $l) === $flagText)
				return TRUE;
		}

		return FALSE;
	}

	protected function isRoot($row)
	{
		foreach ($this->roots as $rootText => $root)
		{
			$l = strlen ($rootText);
			if (substr($row, 0, $l) === $rootText)
				return $root;
		}

		return FALSE;
	}
}
