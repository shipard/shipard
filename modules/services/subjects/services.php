<?php

namespace services\subjects;


/**
 * Class ModuleServices
 * @package services\subjects
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function resetStatsSubjectsCounts ()
	{
		// -- e10-services-subjects-r1
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-services-subjects-r1');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, i1, cnt, updated) ',
				'SELECT %s', 'e10-services-subjects-r1', ', [region1], count(*), NOW() FROM [services_subjects_subjects] WHERE [docStateMain] != 4 GROUP BY [region1]'
		);

		// -- e10-services-subjects-r2
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-services-subjects-r2');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, i1, cnt, updated) ',
				'SELECT %s', 'e10-services-subjects-r2', ', [region2], count(*), NOW() FROM [services_subjects_subjects] WHERE [docStateMain] != 4 GROUP BY [region2]'
		);

		// -- e10-services-subjects-sizes
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-services-subjects-sizes');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, i1, cnt, updated) ',
				'SELECT %s', 'e10-services-subjects-sizes', ', [size], count(*), NOW() FROM [services_subjects_subjects] WHERE [docStateMain] != 4 GROUP BY [size]'
		);

		// -- e10-services-subjects-kinds
		$this->app->db()->query ('DELETE FROM [e10_base_statsCounters] WHERE [id] = %s', 'e10-services-subjects-kinds');
		$this->app->db()->query (
				'INSERT INTO e10_base_statsCounters (id, i1, cnt, updated) ',
				'SELECT %s', 'e10-services-subjects-kinds', ', [kind], count(*), NOW() FROM [services_subjects_subjects] WHERE [docStateMain] != 4 GROUP BY [kind]'
		);

		// -- services_subjects_subjectsCounters
		$this->app->db()->query ('DELETE FROM [services_subjects_subjectsCounters]');
		$this->app->db()->query (
				'INSERT INTO [services_subjects_subjectsCounters] ([branch], [activity], [commodity], [size], [kind], [region1], [region2], [cnt]) ',
				'SELECT b.[branch], b.[activity], b.[commodity], s.[size], s.[kind], s.[region1], s.[region2], COUNT(*) as cnt ',
				'FROM [services_subjects_subjectsBranches] AS b ',
				'LEFT JOIN [services_subjects_subjects] AS s ON b.[subject] = s.[ndx] ',
				'GROUP BY 1, 2, 3, 4, 5, 6, 7'
		);
		// unused in services_subjects_subjectsCounters
		/*
		$this->app->db()->begin();
		$rows = $this->app->db()->query (
				'SELECT s.[size], s.[kind], s.[region1], s.[region2], COUNT(*) as cnt ',
				'FROM [services_subjects_subjects] AS s ',
				'WHERE NOT EXISTS (SELECT x.ndx FROM services_subjects_subjectsBranches AS x WHERE s.ndx = x.subject) ',
				'GROUP BY 1, 2, 3, 4'
		);
		foreach ($rows as $r)
		{
			$newRec = ['size' => $r['size'], 'kind' => $r['kind'], 'region1' => $r['region1'], 'region2' => $r['region2'], 'cnt' => $r['cnt']];
			$this->app->db()->query ('INSERT INTO [services_subjects_subjectsCounters] ', $newRec);
		}
		$this->app->db()->commit();
		*/
	}

	public function onStats()
	{
		$this->resetStatsSubjectsCounts();
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
