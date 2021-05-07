#!/usr/bin/env php
<?php

define ("__APP_DIR__", getcwd());
require_once __APP_DIR__ . '/e10-modules/e10/server/php/e10-cli.php';

use \E10\CLI\Application;


/**
 * Class E10_Services
 */
class E10_Services extends Application
{
	public function import()
	{
		$engine = $this->createObject('services.subjects.libs.imports.czall.ImportCZAll');
		$engine->run();
	}

	public function importNuts()
	{
		$engine = $this->createObject('services.subjects.libs.imports.czall.ImportCZAllNuts');
		$engine->run();
	}

	public function importRegions()
	{
		$engine = $this->createObject('services.subjects.libs.imports.czall.ImportCZAllRegions');
		$engine->run();
	}

	public function setBranches()
	{
		$engine = $this->createObject('services.subjects.libs.tools.SetBranches');
		$engine->run();
	}

	public function setKinds()
	{
		$engine = $this->createObject('services.subjects.libs.tools.SetKinds');
		$engine->run();
	}

	public function setSizes()
	{
		$engine = $this->createObject('services.subjects.libs.tools.SetSizes');
		$engine->run();
	}

	public function updateFromRes()
	{
		$engine = $this->createObject('services.subjects.libs.tools.UpdateFromRes');
		$engine->run();
	}

	public function restart()
	{
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [linkId] = %s', 'services-subjects-branches-activities');
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [linkId] = %s', 'services-subjects-branches-commodities');
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [linkId] = %s', 'services-subjects-branches-nace');

		$this->db()->query ('DELETE FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.branches');

		$this->db()->query ('DROP TABLE [services_subjects_branches]');
		$this->db()->query ('DROP TABLE [services_subjects_commodities]');
		$this->db()->query ('DROP TABLE [services_subjects_activities]');
		$this->db()->query ('DROP TABLE [services_subjects_subjectsBranches]');
		$this->db()->query ('DROP TABLE [services_subjects_subjectsCounters]');
	}

	public function run ()
	{
		switch ($this->command ())
		{
			//case	'import-all-cz':	return $this->import();
			//case	'import-all-cz-nuts':	return $this->importNuts();
			case	'import-all-cz-regions':	return $this->importRegions();


			case	'set-branches':	return $this->setBranches();
			case	'set-kinds':	return $this->setKinds();
			case	'set-sizes':	return $this->setSizes();

			case	'update-from-res':	return $this->updateFromRes();

			case	'restart':	return $this->restart();
		}
		echo ("unknown or nothing param...\r\n");
	}
}

$myApp = new E10_Services ($argv);
$myApp->run ();

