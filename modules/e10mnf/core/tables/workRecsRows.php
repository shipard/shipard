<?php

namespace e10mnf\core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \e10\TableForm, \e10\DbTable, \e10\utils;


/**
 * Class TableWorkRecsRows
 * @package e10mnf\core
 */
class TableWorkRecsRows extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10mnf.core.workRecsRows', 'e10mnf_core_workRecsRows', 'Řádky pracovních záznamů');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);

		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$ownerData['docKind'], []);

		$askPerson = isset($dk['askPerson']) ? $dk['askPerson'] : 0;
		$askWorkOrder = isset($dk['askWorkOrder']) ? $dk['askWorkOrder'] : 0;
		$askItem = isset($dk['askItem']) ? $dk['askItem'] : 0;
		$askPrice = isset($dk['askPrice']) ? $dk['askPrice'] : 0;

		if ($askPerson === 0)
			$recData['person'] = $ownerData['person'];
		if ($askWorkOrder === 1)
			$recData['workOrder'] = $ownerData['workOrder'];

		$dtr = $dk['askDateTimeOnRows'];

		if ($dtr === TableWorkRecs::dtrDateFromToAndTimeFromTo)
		{

		}
		elseif ($dtr === TableWorkRecs::dtrDateAndTimeFromTo)
		{
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}
		elseif ($dtr === TableWorkRecs::dtrDateAndTimeLenInHours)
		{
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}
		elseif ($dtr === TableWorkRecs::dtrTimeFromTo)
		{
			$recData['beginDate'] = utils::createDateTime($ownerData['beginDate']);
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}
		elseif ($dtr === TableWorkRecs::dtrTimeLenHours)
		{
			$recData['beginDate'] = utils::createDateTime($ownerData['beginDate']);
			$recData['endDate'] = utils::createDateTime($recData['beginDate']);
		}

		$beginStr = '';
		if (!utils::dateIsBlank($recData['beginDate']))
		{
			$beginStr .= utils::createDateTime($recData['beginDate'])->format('Y-m-d');
			if ($recData['beginTime'] !== '')
				$beginStr .= ' ' . $recData['beginTime'] . ':00';
		}

		if ($beginStr === '')
			$recData['beginDateTime'] = NULL;
		else
			$recData['beginDateTime'] = new \DateTime($beginStr);

		$endStr = '';
		if (!utils::dateIsBlank($recData['endDate']))
		{
			$endStr .= utils::createDateTime($recData['endDate'])->format('Y-m-d');
			if ($recData['endTime'] !== '')
				$endStr .= ' ' . $recData['endTime'] . ':00';
		}
		if ($endStr === '')
			$recData['endDateTime'] = NULL;
		else
			$recData['endDateTime'] = new \DateTime($endStr);

		$recData['timeLen'] = 0;

		if ($dtr === TableWorkRecs::dtrTimeLenHours)
		{
			$recData['timeLen'] = intval($recData['timeLenHours'] * 60 * 60);
		}
		else
		{
			if (!utils::dateIsBlank($recData['beginDateTime']) && !utils::dateIsBlank($recData['endDateTime']))
				$recData['timeLen'] = utils::dateDiffSeconds(utils::createDateTime($recData['beginDateTime'], TRUE), utils::createDateTime($recData['endDateTime'], TRUE));
		}
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);
	}
}


/**
 * Class FormWorkRecRow
 * @package e10mnf\core
 */
class FormWorkRecRow extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');
		$dk = $this->app()->cfgItem ('e10mnf.workRecs.wrKinds.'.$ownerRecData['docKind'], []);
		$askPerson = isset($dk['askPerson']) ? $dk['askPerson'] : 0;
		$askWorkOrder = isset($dk['askWorkOrder']) ? $dk['askWorkOrder'] : 0;
		$askItem = isset($dk['askItem']) ? $dk['askItem'] : 0;
		$askPrice = isset($dk['askPrice']) ? $dk['askPrice'] : 0;

		$dtr = $dk['askDateTimeOnRows'];

		$this->openForm(TableForm::ltGrid);
			if ($dtr === TableWorkRecs::dtrDateFromToAndTimeFromTo)
			{
				$this->openRow();
					$this->addColumnInput('beginDate', TableForm::coColW3);
					$this->addColumnInput('beginTime', TableForm::coColW3);
					$this->addColumnInput('endDate', TableForm::coColW3);
					$this->addColumnInput('endTime', TableForm::coColW3);
				$this->closeRow();
			}
			elseif ($dtr === TableWorkRecs::dtrDateAndTimeFromTo)
			{
				$this->openRow();
					$this->addColumnInput('beginDate', TableForm::coColW3);
					$this->addColumnInput('beginTime', TableForm::coColW3);
					$this->addColumnInput('endTime', TableForm::coColW3);
				$this->closeRow();
			}
			elseif ($dtr === TableWorkRecs::dtrDateAndTimeLenInHours)
			{
				$this->openRow();
					$this->addColumnInput('beginDate');
					$this->addInput('timeLenHours', 'Čas celkem');
				$this->closeRow();
			}
			elseif ($dtr === TableWorkRecs::dtrTimeFromTo)
			{
				$this->openRow();
					$this->addColumnInput('beginTime', TableForm::coColW3);
					$this->addColumnInput('endTime', TableForm::coColW3);
				$this->closeRow();
			}
			elseif ($dtr === TableWorkRecs::dtrTimeLenHours)
			{
				$this->openRow();
					$this->addInput('timeLenHours', 'Čas celkem');
				$this->closeRow();
			}

			if ($askPerson === 1)
			{
				$this->openRow();
					$this->addColumnInput('person', TableForm::coColW12);
				$this->closeRow();
			}
			if ($askWorkOrder === 2)
			{
				$this->openRow();
					$this->addColumnInput('workOrder', TableForm::coColW12);
				$this->closeRow();
			}
			$this->addColumnInput('subject', TableForm::coColW12);
		$this->closeForm();
	}
}
