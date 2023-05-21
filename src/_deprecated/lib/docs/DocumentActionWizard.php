<?php

namespace lib\docs;
use \e10doc\core\libs\E10Utils, \E10\TableForm, \E10\Wizard;
use \Shipard\Utils\Utils;


/**
 * Class DocumentActionWizard
 * @package lib\docs
 */
class DocumentActionWizard extends Wizard
{
	protected $actionClass = NULL;
	protected $actionObject = NULL;
	protected $tableTasks;

	protected function init ()
	{
		$this->actionObject = $this->app()->createObject($this->actionClass);
		$this->tableTasks = $this->app()->table('e10.base.tasks');
	}

	public function doStep ()
	{
		if ($this->pageNumber === 1)
		{
			$this->doAction();
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function addParams ()
	{
		$this->recData['actionTable'] = $this->app->testGetParam('table');
		$this->addInput('actionTable', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		$this->recData['actionPK'] = $this->app->testGetParam('pk');
		$this->addInput('actionPK', '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);

		foreach ($_GET as $param => $value)
		{
			if (substr($param, 0, 11) !== 'data-param-')
				continue;
			$this->recData[$param] = $value;
			$this->addInput($param, '', self::INPUT_STYLE_STRING, TableForm::coHidden, 120);
		}

		$actionParams = $this->actionObject->actionParams();
		if ($actionParams === FALSE)
			return;

		foreach ($actionParams as $p)
		{
			if ($p['type'] === 'checkbox')
			{
				$checked = isset($p['checked']) ? $p['checked'] : 0;
				$this->recData[$p['id']] = $checked;
				$this->addCheckBox($p['id'], $p['name'], '1', 0, $checked);
			}
			if($p['type'] === 'vatPeriod')
			{
				$this->addInputIntRef ('vatPeriod', 'e10doc.base.taxperiods', 'Přiznání DPH');
			}
			if($p['type'] === 'fiscalYear')
			{
				$this->addInputEnum2 ($p['id'], 'Účetní období', E10Utils::fiscalYearEnum ($this->app()), self::INPUT_STYLE_OPTION);
			}
			if($p['type'] === 'calendarYear')
			{
				$years = [];
				$todayYear = intval(Utils::today('Y'));
				for ($i = 0; $i < 4; $i++)
					$years[$todayYear - $i] = strval($todayYear - $i);
				$this->addInputEnum2 ($p['id'], 'Rok', $years, self::INPUT_STYLE_OPTION);
			}
			if($p['type'] === 'calendarMonth')
			{
				$months = [];
				for ($i = 1; $i <= 12; $i++)
					$months[$i] = strval($i);
				$this->addInputEnum2 ($p['id'], 'Měsíc', $months, self::INPUT_STYLE_OPTION);
			}

			if (isset($p['defaultValue']) && !isset($this->recData[$p['id']]))
				$this->recData[$p['id']] = $p['defaultValue'];
		}
	}

	public function renderFormWelcome ()
	{
		$this->init();
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addParams();
		$this->closeForm ();
	}

	public function doAction ()
	{
		$this->init();
		$this->stepResult ['close'] = 1;

		$taskRec = [
			'title' => $this->actionObject->actionName(),
			'classId' => $this->actionClass,
			'tableId' => intval($this->recData['actionTable']), 'recId' => intval($this->recData['actionPK']),
			'params' => json_encode($this->recData),
			'timeCreate' => new \DateTime(),
			'docState' => 1000, 'docStateMain' => 0
		];

		$this->tableTasks->addTask($taskRec);
	}
}

