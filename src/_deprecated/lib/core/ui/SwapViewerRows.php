<?php

namespace lib\core\ui;

use e10\E10ApiObject;


/**
 * Class SwapViewerRows
 * @package lib\core\ui
 */
class SwapViewerRows extends E10ApiObject
{
	var $code = '';

	/** @var \e10\DbTable */
	var $table = NULL;
	var $firstRowNdx = 0;
	var $firstRowRecData = NULL;
	var $secondRowNdx = 0;
	var $secondRowRecData = NULL;
	var $paramsError = 1;

	public function init ()
	{
		$tableId = $this->requestParam ('table');
		if (!$tableId)
			return;

		$this->table = $this->app()->table($tableId);
		if (!$this->table)
			return;

		$this->firstRowNdx = $this->requestParam ('firstPK', 0);
		$this->secondRowNdx = $this->requestParam ('secondPK', 0);

		if (!$this->firstRowNdx || !$this->secondRowNdx)
			return;
		
		$this->firstRowRecData = $this->db()->query ('SELECT ndx, rowOrder FROM ['.$this->table->sqlName().'] WHERE [ndx] = %i',
			$this->firstRowNdx)->fetch();
		if (!$this->firstRowRecData)
			return;

		$this->secondRowRecData = $this->db()->query ('SELECT ndx, rowOrder FROM ['.$this->table->sqlName().'] WHERE [ndx] = %i',
			$this->secondRowNdx)->fetch();
		if (!$this->secondRowRecData)
			return;

		$this->paramsError = 0;
	}

	public function renderCode()
	{
		if ($this->paramsError)
			return;

		$c = '';

		$this->code .= $c;
	}

	function doIt()
	{
		if ($this->paramsError)
			return;

		$this->db()->query('UPDATE ['.$this->table->sqlName().'] SET [rowOrder] = %i', $this->secondRowRecData['rowOrder'], ' WHERE [ndx] = %i', $this->firstRowNdx);
		$this->db()->query('UPDATE ['.$this->table->sqlName().'] SET [rowOrder] = %i', $this->firstRowRecData['rowOrder'], ' WHERE [ndx] = %i', $this->secondRowNdx);
	}

	public function createResponseContent($response)
	{
		$this->init();
		$this->doIt();
		$this->renderCode();

		$response->add ('success', 1);
		$response->add ('rowsHtmlCode', $this->code);
	}
}
