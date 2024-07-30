<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;
use function e10\sortByOneKey;

/**
 * class ViewWOEvents
 */
class ViewWOEvents extends \e10mnf\core\ViewWorkOrders
{
	var $allUsersPeriods = NULL;

	public function init ()
	{
		parent::init();

		$this->useLinkedPersons = 1;
	}

	protected function createMainQueries()
	{
		$allUsersPeriods = $this->app()->cfgItem('e10.usersPeriods', NULL);

		$today = Utils::today();
		$todayMonth = intval($today->format('m'));

		if ($allUsersPeriods)
		{
			if ($todayMonth < 7)
				$this->allUsersPeriods = \e10\sortByOneKey ($allUsersPeriods, 'dateBegin', TRUE, TRUE);
			else
				$this->allUsersPeriods = \e10\sortByOneKey ($allUsersPeriods, 'dateBegin', TRUE, FALSE);
		}

		if (!$this->allUsersPeriods)
		{
			$mq [] = ['id' => 'active', 'title' => 'Živé', 'side' => 'left'];
			$mq [] = ['id' => 'done', 'title' => 'Hotové', 'side' => 'left'];

			$mq [] = ['id' => 'all', 'title' => 'Vše'];
			$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		}
		else
		{
			$cnt = 0;
			foreach ($this->allUsersPeriods as $upId => $up)
			{
				if ($up['done'] ?? 0)
					continue;

				$mq [] = ['id' => 'UP_'.$upId, 'title' => $up['sn'], 'side' => 'left'];
				$cnt++;
				if ($cnt > 1)
					break;
			}

			$mq [] = ['id' => 'done', 'title' => 'Hotové', 'side' => 'left'];
			$mq [] = ['id' => 'all', 'title' => 'Vše'];
			$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		}

		$this->setMainQueries ($mq);
	}

	protected function qryMainQuery(&$q, $fts, $mainQuery)
	{
		if (substr($mainQuery, 0, 3) === 'UP_')
		{
			$userPeriodId = substr($mainQuery, 3);
			array_push ($q, ' AND workOrders.[usersPeriod] = %s', $userPeriodId);
			if ($fts != '')
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1, 2)');
			else
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1)');
		}

		if ($mainQuery === 'active' || $mainQuery == '')
		{
			if ($fts != '')
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1, 2)');
			else
				array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1)');
		}

		if ($mainQuery === 'done')
			array_push ($q, ' AND workOrders.[docStateMain] = 2');

		if ($mainQuery === 'discarded')
			array_push ($q, ' AND workOrders.[docStateMain] = 5');
		if ($mainQuery === 'trash')
			array_push ($q, ' AND workOrders.[docStateMain] = 4');
	}

	protected function qryOrder(&$q)
	{
    array_push($q, ' ORDER BY [workOrders].[docStateMain], workOrders.[title], workOrders.[docNumber]');
	}
}
