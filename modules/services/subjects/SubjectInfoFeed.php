<?php

namespace services\subjects;

use \e10\utils, \e10\json, \e10\Utility, \e10\Response;


/**
 * Class SubjectInfoFeed
 * @package services\subjects
 *
 * https://services.shipard.com/feed/subject-info/63478714
 */
class SubjectInfoFeed extends Utility
{
	var $object = [];

	public function init()
	{
	}

	public function searchSubject()
	{
		$companyId = $this->app()->requestPath(2);

		$this->object['success'] = 0;
		$this->object['subjects'] = [];

		$ndx = 0;

		// -- search subject
		if ($companyId[0] === '@')
		{ // by ndx
			$ndx = intval (substr($companyId, 1));
		}
		else
		{ // by company id
			$q = [];
			array_push($q, 'SELECT * FROM [e10_base_properties] as props');

			array_push($q, ' WHERE [tableid] = %s', 'services.subjects.subjects',
					' AND [group] = %s', 'e10srv-subj-id',
					' AND [property] = %s', 'e10srv-subj-id-oid');
			array_push($q, ' AND valueString = %s', $companyId);

			$exist = $this->db()->query($q);
			foreach ($exist as $r)
			{
				$qs = [];
				array_push($qs, 'SELECT * FROM [services_subjects_subjects]');
				array_push($qs, ' WHERE [docState] != %i', 9800, ' AND [ndx] = %i', $r['recid']);
				$s = $this->db()->query($qs)->fetch();
				if ($s)
				{
					$ndx = $s['ndx'];
					break;
				}
			}
		}

		if ($ndx)
		{
			$sp = new \services\subjects\SourcesPackager ($this->app());
			$sp->setQueryDefinition(['ndx' => $ndx]);
			$sp->run();

			$this->object['subjects'] = array_values($sp->subjects);
		}

		if (count($this->object['subjects']))
			$this->object['success'] = 1;
	}

	public function run ()
	{
		$this->searchSubject();

		$response = new Response ($this->app);
		$data = json::lint ($this->object);
		$response->setRawData($data);
		return $response;
	}
}
