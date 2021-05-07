<?php

namespace services\subjects;

use \e10\utils, \e10\json, \e10\Utility, \e10\Response;


/**
 * Class SourcesPackageFeed
 * @package services\subjects
 *
 * https://services.shipard.com/feed/sources-package/eyJjb21tb2RpdGllcyI6WzI1XSwicmVnaW9uMiI6WzkxXX0=
 */
class SourcesPackageFeed extends Utility
{
	var $object = [];
	var $queryDefinition;

	public function init()
	{
		$paramsStr = base64_decode($this->app()->requestPath(2));
		$this->queryDefinition = json_decode($paramsStr, TRUE);
	}

	public function createPackage()
	{
		$this->object['success'] = 0;
		$this->object['subjects'] = [];

		$sp = new \services\subjects\SourcesPackager ($this->app());
		$sp->setQueryDefinition($this->queryDefinition);
		$sp->run();

		$this->object['files'] = $sp->files;
	}

	public function loadSubject ($ndx)
	{
		// -- load header
		$q = [];
		array_push($q, 'SELECT * FROM [services_subjects_subjects]');
		array_push($q, ' WHERE [ndx] = %i', $ndx);
		$s = $this->db()->query($q)->fetch();

		$subject = [];
		$subject ['head'] = $s->toArray();
		json::polish($subject ['head']);

		// -- addresses
		$subject ['addresses'] = [];

		$q = [];
		array_push($q, 'SELECT * FROM [e10_persons_address]');
		array_push($q, ' WHERE [tableid] = %s', 'services.subjects.subjects', ' AND [recid] = %i', $ndx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$a = $r->toArray();
			unset($a['tableid'], $a['recid']);
			json::polish($a);
			$subject ['addresses'][] = $a;
		}

		// -- properties
		$subject ['properties'] = [];

		$q = [];
		array_push($q, 'SELECT * FROM [e10_base_properties]');
		array_push($q, ' WHERE [tableid] = %s', 'services.subjects.subjects',
				' AND [group] IN %in', ['e10srv-subj-id'],
				' AND recid = %i', $ndx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$p = $r->toArray();
			unset($p['tableid'], $p['recid'], $p['created']);
			json::polish($p);
			$subject ['properties'][] = $p;
		}


		return $subject;
	}

	public function run ()
	{
		$this->createPackage();

		$response = new Response ($this->app);
		$data = json::lint ($this->object);
		$response->setRawData($data);
		return $response;
	}
}
