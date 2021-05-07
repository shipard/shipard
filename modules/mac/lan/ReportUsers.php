<?php

namespace mac\lan;

use e10\utils;


/**
 * Class ReportDHCP
 * @package mac\lan
 */
class ReportUsers extends \mac\lan\Report
{
	var $data = [];
	var $files = [];
	var $lanNdx = 1;

	var $usersGroupNdx = 0;

	function init ()
	{
		parent::init();

		$groupsMap = $this->app->cfgItem ('e10.persons.groupsToSG', FALSE);
		if ($groupsMap && isset ($groupsMap ['e10pro-lan-it-users']))
			$this->usersGroupNdx = $groupsMap ['e10pro-lan-it-users'];
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview(); break;
			case 'synology': $this->createContent_Synology(); break;
		}

		$this->setInfo('title', 'Uživatelé');
	}

	function createContent_Overview ()
	{
		$h = ['#' => '#', 'login' => 'Přihlašovací jméno', 'email' => 'email', 'name' => 'Jméno'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data]);
	}

	function createContent_Synology ()
	{
		$this->addContent (['type' => 'text', 'subtype' => 'plain', 'text' => $this->createData_Synology()]);
	}

	public function loadData ()
	{
		$q[] = 'SELECT * FROM [e10_persons_persons] AS persons ';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND persons.docState = %i', 4000);
		array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person and [group] = %i)',
				$this->usersGroupNdx);
		array_push ($q, ' ORDER BY persons.lastName');
		$rows = $this->app->db()->query($q);

		$pks = [];
		foreach ($rows as $r)
		{
			$newItem = ['name' => $r['fullName']];
			$newItem['password'] = $this->generatePassword(8);
			$this->data[$r['ndx']]= $newItem;
			$pks[] = $r['ndx'];
		}

		// -- properties (login/email)
		$properties = $this->loadProperties($pks);
		foreach ($properties as $personNdx => $props)
		{
			if (isset ($props['email'][0]))
				$this->data[$personNdx]['email'] = $props['email'][0];
			if (isset ($props['e10-lan-it-user-login'][0]))
				$this->data[$personNdx]['login'] = $props['e10-lan-it-user-login'][0];
		}
	}

	public function loadProperties ($pks)
	{
		$properties = [];

		if (!count($pks))
			return $properties;

		$q = 'SELECT [recid], [group], [valueString], [valueDate], [property], [note] FROM [e10_base_properties] WHERE [tableid] = %s AND [recid] IN %in';
		$rows = $this->db()->query ($q, 'e10.persons.persons', $pks);
		forEach ($rows as $r)
		{
			if ($r ['valueString'] == '')
				continue;
			$properties [$r['recid']][$r ['property']][] = $r ['valueString'];
		}

		return $properties;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = ['text' => 'Synology DiskStation (.csv)', 'icon' => 'icon-file-text-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'synology'];
	}

	public function saveReportAs ()
	{
		$this->loadData();


		switch ($this->format)
		{
			case 'synology':
							$data = $this->createData_Synology();
				break;
		}

		$fileName = utils::tmpFileName('csv');
		file_put_contents($fileName, $data);

		$this->fullFileName = $fileName;
		$this->saveFileName = $this->saveAsFileName ($this->format);
		$this->mimeType = 'text/plain';
	}

	function createData_Synology ()
	{
		$h = ['login' => 'Přihlašovací jméno', 'password' => 'Heslo', 'name' => 'Jméno', 'email' => 'email'];
		$params = [];
		$data = utils::renderTableFromArrayCsv ($this->data, $h, $params);

		return $data;
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'icon-table', 'title' => 'Přehled'];
		$d[] = ['id' => 'synology', 'icon' => 'icon-hdd-o', 'title' => 'Synology'];

		return $d;
	}

	public function saveAsFileName ($type)
	{
		switch ($type)
		{
			case 'synology': return 'users.csv';
		}
	}

	function generatePassword ($len = 9)
	{
		$r = '';
		for($i = 0; $i < $len; $i++)
			$r .= chr (rand (0, 25) + ord('a'));
		return $r;
	}
}
