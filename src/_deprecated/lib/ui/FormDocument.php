<?php

namespace lib\ui;

use \e10\DataModel;


/**
 * Class FormDocument
 * @package lib\ui
 */
class FormDocument extends Form
{
	/** @var  \E10\DbTable */
	var $table;
	var $pk = 0;
	var $recData;

	public function setTable ($tableId)
	{
		$this->table = $this->app->table($tableId);
	}

	public function addColumnInput ($columnId, $options = NULL)
	{
		$col = $this->table->column ($columnId);
		if (!$col)
			return;

		$input = ['wt' => Form::wtInput, 'id' => $columnId];
		if ($options)
			$input['options'] = $options;
		$input ['label'] = $this->columnInputLabel ($columnId, $col);

		switch ($col ['type'])
		{
			case DataModel::ctString: $input ['it'] = Form::itText;break;
			case DataModel::ctInt: $input ['it'] = Form::itInt;break;
			case DataModel::ctMemo: $input ['it'] = Form::itMemo;break;
			case DataModel::ctEnumInt: $input ['it'] = Form::itEnum;$input ['values'] = $this->columnInputEnum ($columnId);break;
			case DataModel::ctEnumString: $input ['it'] = Form::itEnum;$input ['values'] = $this->columnInputEnum ($columnId);break;
			/*case DataModel::ctMoney:
			case DataModel::ctNumber:
			case DataModel::ctDate:
			case DataModel::ctTimeStamp:
			case DataModel::ctTime:
			case DataModel::ctLogical:
			case DataModel::ctLong:*/
		}

		if ($options && isset($options['forceSelect']))
		{
			$input ['it'] = Form::itEnum;
			$input ['values'] = $this->columnInputEnum ($columnId);
		}

		$this->addWidget($input);
	}

	protected function columnInputEnum ($colId)
	{
		return $this->table->columnInfoEnum($colId);
	}

	protected function columnInputLabel ($colId, $colDef)
	{
		return isset ($colDef ['label']) ? $colDef ['label'] : $colDef ['name'];
	}

	public function doResponse ()
	{
		parent::doResponse();

		$formData = [];
		$formData['recData'] = (isset($this->recData)) ? $this->recData : [];

		$this->response->add ('formData', $formData);
		$this->response->add ('table', $this->table->tableId());
		$this->response->add ('pk', $this->pk);
	}

	public function getPostData ()
	{
		parent::getPostData();
		if ($this->postData)
		{
			$this->operation = $this->postData['operation'];
			if (isset($this->postData['pk']))
				$this->pk = intval($this->postData['pk']);
		}
	}

	protected function load ($pk = FALSE)
	{
		$ndx = ($pk === FALSE) ? $this->pk : $pk;
		if ($ndx)
		{
			$this->recData = $this->table->loadItem ($ndx);
		}
		else
		{
			$this->recData = [];
			$this->table->checkNewRec($this->recData);
		}
	}
}
