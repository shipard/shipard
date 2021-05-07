<?php

namespace services\subjects;

use \e10\utils, \e10\json, \e10\Utility;


/**
 * Class SourcesPackager
 * @package services\subjects
 */
class SourcesPackager extends Utility
{
	var $queryDefinition;
	var $subjects = [];
	var $files = [];
	var $singleMode = FALSE;

	var $limitBlock = 100;
	var $limitFile = 1000;
	var $limitTotal = 1000000;

	public function setQueryDefinition ($queryDefinition)
	{
		$this->queryDefinition = $queryDefinition;
		if (isset($queryDefinition['ndx']))
			$this->singleMode = TRUE;
	}

	function doIt ()
	{
		$qv = $this->queryDefinition;

		$q [] = 'SELECT * FROM [services_subjects_subjects] AS [subjects]';
		array_push ($q, ' WHERE 1');

		if (isset($qv['ndx']))
			array_push ($q, ' AND subjects.ndx = %i', $qv['ndx']);

		if (isset($qv['kinds']))
			array_push ($q, ' AND subjects.kind IN %in', $qv['kinds']);

		if (isset($qv['sizes']))
			array_push ($q, ' AND subjects.size IN %in', $qv['sizes']);

		if (isset($qv['region1']))
			array_push ($q, ' AND subjects.region1 IN %in', $qv['region1']);

		if (isset($qv['region2']))
			array_push ($q, ' AND subjects.region2 IN %in', $qv['region2']);

		if (isset($qv['activities']))
			array_push ($q,
					' AND EXISTS (SELECT ndx FROM services_subjects_subjectsBranches WHERE subjects.ndx = subject ',
					' AND [activity] IN %in', $qv['activities'], ')'
			);

		if (isset($qv['commodities']))
			array_push ($q,
					' AND EXISTS (SELECT ndx FROM services_subjects_subjectsBranches WHERE subjects.ndx = subject ',
					' AND [commodity] IN %in', $qv['commodities'], ')'
			);

		$rows = $this->db()->query($q);

		$pks = [];
		$blockCnt = 0;
		$fileCnt = 0;
		$totalCnt = 0;
		foreach ($rows as $r)
		{
			$subject = [];
			$subject ['head'] = $r->toArray();
			$subject ['addresses'] = [];
			$subject ['properties'] = [];
			$subject ['activities'] = [];
			$subject ['commodities'] = [];
			json::polish($subject ['head']);

			$this->subjects[$r['ndx']] = $subject;
			$pks [] = $r['ndx'];

			$blockCnt++;
			$fileCnt++;
			$totalCnt++;

			if ($blockCnt > $this->limitBlock)
			{
				$this->loadSubjectsInfo($pks);
				$blockCnt = 0;
				$pks = [];

				if ($fileCnt > $this->limitFile)
				{
					$this->flushFile();
					$fileCnt = 0;
					$this->subjects = [];

					if ($totalCnt > $this->limitTotal)
						break;
				}
			}
		}

		if (count($pks))
			$this->loadSubjectsInfo($pks);

		if (!$this->singleMode)
		{
			if (count($this->subjects))
				$this->flushFile();
		}
	}

	function flushFile ()
	{
		$fileName = utils::tmpFileName('csp', 'crm-sources');
		$baseName = basename($fileName);

		file_put_contents($fileName, json_encode(array_values($this->subjects)));
		$this->files [] = [
				'fileName' => $baseName, 'cnt' => count($this->subjects), 'size' => filesize($fileName)
		];
	}

	public function loadSubjectsInfo ($pks)
	{
		$nationalCompanyId = '';

		// -- addresses
		$q = [];
		array_push($q, 'SELECT * FROM [e10_persons_address]');
		array_push($q, ' WHERE [tableid] = %s', 'services.subjects.subjects', ' AND [recid] IN %in', $pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ndx = $r['recid'];
			$a = $r->toArray();
			unset($a['tableid'], $a['recid']);
			json::polish($a);
			$this->subjects [$ndx]['addresses'][] = $a;
		}

		// -- properties
		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_properties]');
		array_push($q, ' WHERE [tableid] = %s', 'services.subjects.subjects',
				' AND [group] IN %in', ['e10srv-subj-id'],
				' AND recid IN %in', $pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ndx = $r['recid'];
			$p = $r->toArray();
			unset($p['tableid'], $p['recid'], $p['created']);
			json::polish($p);
			$this->subjects [$ndx]['properties'][] = $p;

			if ($r['property'] === 'e10srv-subj-id-oid')
				$nationalCompanyId = $r['valueString'];
		}

		// -- branches
		/*
		$q = [];
		array_push($q, 'SELECT * FROM [services_subjects_subjectsBranches]');
		array_push($q, ' WHERE subject IN %in', $pks);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ndx = $r['subject'];
			$this->subjects [$ndx]['activities'][] = $r['activity'];
			$this->subjects [$ndx]['commodities'][] = $r['commodity'];
		}
*/
		// --
		$q = [];
		array_push($q, 'SELECT * FROM [services_subjregs_cz_res_plus_rzpProvoz]');
		array_push($q, ' WHERE [ico] = %s', $nationalCompanyId);
		array_push($q, ' ORDER BY [obec]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'type' => 99,
        'specification' => strval($r['icp']),
        'street' => $r['ulice'],
        'city' => $r['obec'],
        'zipcode' => $r['psc'],
        'country' => 'cz'
			];

			$this->subjects [$ndx]['addresses'][] = $item;
		}
	}

	public function run()
	{
		$this->doIt();
	}
}
