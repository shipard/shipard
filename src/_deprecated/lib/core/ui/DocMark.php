<?php

namespace lib\core\ui;

use e10\E10ApiObject, e10\utils, \Shipard\Utils\TableRenderer;


/**
 * Class SumTable
 * @package lib\core\ui
 */
class DocMark extends E10ApiObject
{
	//var $data = [];
	var $code = '';

	var $userNdx = 0;
	var $markCfg;
	var $markSubType = '';

	/** @var \e10\DbTable */
	var $storeTable;
	/** @var \e10\DbTable */
	var $markTable;
	var $markRecData;
	var $markNewState = 0;

	var $paramsError = 1;

	public function init ()
	{
		$this->userNdx = $this->app()->userNdx();
	}

	public function renderCode()
	{
		if ($this->paramsError)
			return;

		$c = '';

		if ($this->markCfg['type'] === 'check')
		{
			$iconClass = '';
			if ($this->markCfg['states'][$this->markNewState]['classOn'] !== '')
				$iconClass = $this->markCfg['states'][$this->markNewState]['classOn'];
			$c .= $this->app()->ui()->icon($this->markCfg['states'][$this->markNewState]['icon'], $iconClass);
		}

		$this->code .= $c;
	}

	function loadData()
	{
		if (isset($this->requestParams['mark-st']))
			$this->markSubType = $this->requestParams['mark-st'];

		$this->paramsError = 2;

		$this->markCfg = $this->app()->cfgItem('docMarks.'.$this->requestParams['mark'], NULL);
		if (!$this->markCfg)
			return;

		$this->storeTable = $this->app()->table($this->markCfg['storeTable']);
		if (!$this->storeTable)
			return;

		$this->markTable = $this->app()->table($this->requestParams['table']);
		if (!$this->markTable)
			return;

		$this->markRecData = NULL;

		$row = $this->db()->query ('SELECT * FROM ['.$this->storeTable->sqlName().'] WHERE ',
			'[user] = %i', $this->userNdx, ' AND [table] = %i', $this->markTable->ndx,
			' AND [mark] = %i', $this->requestParams['mark'], ' AND [rec] = %i', intval($this->requestParams['pk']))->fetch();

		if ($row)
			$this->markRecData = $row->toArray();

		if ($this->markCfg['type'] === 'check')
		{
			$this->markNewState = key($this->markCfg['states']);
			if ($this->markRecData)
				$this->markNewState = $this->markRecData['state'];
			if (!isset($this->markCfg['states'][$this->markNewState]))
				$this->markNewState = 0;

			$cnt = 0;
			$ms = $this->markCfg['states'][$this->markNewState];
			while(1)
			{
				$cnt++;
				if ($cnt > 100)
					break;

				$this->markNewState = $ms['n'];
				$ms = $this->markCfg['states'][$this->markNewState];
				if (isset($ms['st']) && !in_array($this->markSubType, $ms['st']))
					continue;

				break;
			}
		}


		$this->paramsError = 0;

		// -- save
		if ($this->markRecData)
		{
			$this->db()->query('UPDATE ['.$this->storeTable->sqlName().'] SET [state] = %i', $this->markNewState,
				' WHERE [ndx] = %i', $this->markRecData['ndx']);
		}
		else
		{
			$newItem = [
				'user' => $this->userNdx, 'table' => $this->markTable->ndx,
				'mark' => intval($this->requestParams['mark']), 'rec' => intval($this->requestParams['pk']),
				'state' => $this->markNewState
			];
			$this->db()->query('INSERT INTO ['.$this->storeTable->sqlName().'] ', $newItem);
		}
	}

	public function createResponseContent($response)
	{
		$this->init();
		$this->loadData();
		$this->renderCode();

		$response->add ('success', 1);
		$response->add ('rowsHtmlCode', $this->code);
		$response->add ('markTitle', $this->markCfg['states'][$this->markNewState]['name']);
	}
}
