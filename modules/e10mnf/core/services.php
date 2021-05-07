<?php

namespace e10mnf\core;


/**
 * Class ModuleServices
 * @package e10mnf\core
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function resetStatsWorkOrdersCounts ()
	{
		// -- e10-mnf-workOrders-all
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-mnf-workOrders-all');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, i1, cnt, updated) ',
				'SELECT %s', 'e10-mnf-workOrders-all', ', [dbCounter], count(*), NOW() FROM [e10mnf_core_workOrders] WHERE [docStateMain] != 4 GROUP BY [dbCounter]'
		);

		// -- e10-mnf-workOrders-monthly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-mnf-workOrders-monthly');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s1, i1, cnt, updated) ',
				'SELECT %s', 'e10-mnf-workOrders-monthly', ', DATE_FORMAT(dateIssue, %s', '%Y-%m', ') AS dateKey, [dbCounter], count(*), NOW() FROM [e10mnf_core_workOrders] WHERE [docStateMain] != 4 GROUP BY [dbCounter], [dateKey]'
		);

		// -- e10-mnf-workOrders-yearly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-mnf-workOrders-yearly');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s1, i1, cnt, updated) ',
				'SELECT %s', 'e10-mnf-workOrders-yearly', ', DATE_FORMAT(dateIssue, %s', '%Y', ') AS dateKey, [dbCounter], count(*), NOW() FROM [e10mnf_core_workOrders] WHERE [docStateMain] != 4 GROUP BY [dbCounter], [dateKey]'
		);

		// -- e10-mnf-workOrdersAdmins-yearly
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-mnf-workOrdersAdmins-yearly');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, s1, i1, cnt, updated) ',
				'SELECT %s', 'e10-mnf-workOrdersAdmins-yearly', ', DATE_FORMAT(dateIssue, %s', '%Y', ') AS dateKey, links.dstRecId, count(*), NOW() ',
				'FROM [e10mnf_core_workOrders] AS wo ',
				'LEFT JOIN e10_base_doclinks AS links ON wo.ndx = links.srcRecId AND links.linkId = %s', 'e10mnf-workRecs-admins',
				'WHERE wo.[docStateMain] != 4 ', ' AND wo.[dateIssue] IS NOT NULL', ' AND links.[dstRecId] IS NOT NULL',
				'GROUP BY 2, 3'
		);
	}

	public function onStats()
	{
		$this->resetStatsWorkOrdersCounts();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'stats': $this->onStats(); break;
		}
		return TRUE;
	}
}
