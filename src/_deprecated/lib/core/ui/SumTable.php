<?php

namespace lib\core\ui;

use e10\E10ApiObject, e10\utils, \Shipard\Utils\TableRenderer;


/**
 * Class SumTable
 * @package lib\core\ui
 */
class SumTable extends E10ApiObject
{
	var $data = [];
	var $header = [];
	var $level = 0;
	var $code = '';
	var $renderAll = 0;
	var $queryParams = [];
	var $options = [];
	var $bgLevels = [];
	var $colClasses = [];

	var $selectableRows = FALSE;
	var $formId = '';
	var $columnId = '';
	var $finalColumnName = '';
	var $hideHeader = 1;
	var $extraHeader = NULL;

	public function init ()
	{
		$this->bgLevels = [
			0 => 'e10-bg-white',
			1 => 'e10-bg-t8',
			2 => 'e10-bg-t5',
			3 => 'e10-bg-t9',
			4 => 'e10-bg-t6',
			5 => 'e10-bg-t3',
			6 => 'e10-bg-t7',
			7 => 'e10-bg-t1',
			8 => 'e10-bg-t2',
		];
	}

	public function setQueryParams($params)
	{
		foreach ($params as $key => $value)
			$this->queryParams[$key] = $value;
	}

	public function setOptions($options)
	{
		foreach ($options as $key => $value)
			$this->options[$key] = $value;
	}

	public function setColumnId($formId, $columnId, $finalColumnName)
	{
		$this->formId = $formId;
		$this->columnId = $columnId;
		$this->finalColumnName = $finalColumnName;
		$this->selectableRows = TRUE;
	}

	public function renderCode()
	{
		$c = '';

		$params = [];
		$params['tableClass'] = 'e10-pane e10-pane-table';
		if ($this->hideHeader)
			$params['hideHeader'] = 1;
		else
			$params['tableClass'] .= ' main';

		if ($this->extraHeader)
			$params['header'] = $this->extraHeader;
		if ($this->bgLevels !== NULL)
			$params['bgLevels'] = $this->bgLevels;
		if (count($this->colClasses))
			$params['colClasses'] = $this->colClasses;

		$tr = new TableRenderer($this->data, $this->header, $params, $this->app);

		if ($this->renderAll)
		{
			$widgetClass = 'e10-sum-table';
			if ($this->selectableRows)
				$widgetClass .= ' e10-tree-input';

			$c .= "<div class='$widgetClass' data-object-class-id='{$this->objectClassId}'";
			foreach ($this->queryParams as $key => $value)
				$c .= " data-query-$key= '".utils::es($value)."'";
			$c .= '>';

			if ($this->selectableRows)
			{
				$c .= "<input type='text' name='{$this->finalColumnName}' id='{$this->columnId}' data-fid='{$this->formId}' hidden='hidden' value=''/>";
			}

			$c .= $tr->render();
			$c .= '</div>';
		}
		else
		{
			$tr->init();
			$c .= $tr->renderAllRows();
		}

		$this->code .= $c;
	}

	function loadData()
	{
	}

	public function createResponseContent($response)
	{
		if (isset($this->requestParams['query-params']))
			$this->setQueryParams($this->requestParams['query-params']);

		if (isset($this->requestParams['level']))
			$this->level = intval($this->requestParams['level']);

		$this->init();
		$this->loadData();
		$this->renderCode();

		$response->add ('success', 1);
		$response->add ('rowsHtmlCode', $this->code);
	}
}
