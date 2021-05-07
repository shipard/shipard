<?php

namespace mac\access\dc;

use e10\utils, e10\json;


/**
 * Class AccessPerson
 * @package mac\access\dc
 */
class AccessPerson extends \e10\DocumentCard
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;
	var $recDataPerson = NULL;

	var $assignmentHistory = [];
	var $personAccessLevels = [];

	function loadAssignment()
	{
		$now = new \DateTime();

		$q = [];
		array_push ($q, 'SELECT assignments.*,');
		array_push ($q, ' tags.keyValue AS keyValue, tags.tagType AS keyType');
		array_push ($q, ' FROM [mac_access_tagsAssignments] AS assignments');
		array_push ($q, ' LEFT JOIN mac_access_tags AS tags ON assignments.tag = tags.ndx');
		//array_push ($q, ' ');

		array_push ($q, ' WHERE assignments.[person] = %i', $this->recData['person']);
		array_push ($q, ' AND assignments.[docState] = %i', 4000);
		array_push ($q, ' ORDER BY assignments.validFrom DESC');
		//array_push ($q, '');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$tagTypeCfg = $this->app()->cfgItem('mac.access.tagTypes.'.$r['keyType'], NULL);
			$item = [
				'validFrom' => utils::datef($r['validFrom'], '%d %T'),
				'validTo' => utils::datef($r['validTo'], '%d %T'),
			];
			$item['key'] = ['text' => $r['keyValue'], 'icon' => $tagTypeCfg['icon']];
			$item['keyType'] = $tagTypeCfg['sc'];
			$item['validNow'] = 0;

			if ((utils::dateIsBlank($r['validFrom']) || $r['validFrom'] <= $now) &&
				(utils::dateIsBlank($r['validTo']) || $r['validTo'] >= $now))
				$item['validNow'] = 1;


			$this->assignmentHistory[] = $item;
		}
	}

	function loadPersonAccessLevels()
	{
		$q= [];
		$q[] = 'SELECT pal.*, [al].fullName FROM [mac_access_personsAccessLevels] AS [pal]';
		array_push($q, ' LEFT JOIN [mac_access_levels] AS [al] ON [pal].[accessLevel] = [al].ndx');
		array_push($q, ' WHERE [pal].[ndx] = %i', $this->recData['person']);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['text' => $r['fullName'], 'icon' => 'icon-empire', 'class' => 'label label-default'];
			$this->personAccessLevels[] = $item;
		}
	}

	function loadPerson()
	{
		$this->recDataPerson = $this->tablePersons->loadItem($this->recData['person']);
	}

	public function createContentBody ()
	{
		$this->loadPerson();
		$this->loadAssignment();
		$this->loadPersonAccessLevels();

		// -- core info
		$info = [];
		$info[] = ['p1' => 'Osoba', 't1' => $this->recDataPerson['fullName']];

		if (count($this->personAccessLevels))
			$info[] = ['p1' => 'Oprávnění', 't1' => $this->personAccessLevels];

		if (count($this->assignmentHistory))
		{
			foreach ($this->assignmentHistory as $ahItem)
			{
				if (!$ahItem['validNow'])
					continue;

				$info[] = ['p1' => $ahItem['keyType'], 't1' => $ahItem['key']];
			}
		}

		$info[0]['_options']['cellClasses']['p1'] = 'width30';
		$h = ['p1' => ' ', 't1' => ''];

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'header' => $h, 'table' => $info, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']
		]);



		// -- assignment history
		if (count($this->assignmentHistory))
		{
			$ah = ['#' => '#', 'key' => 'Klíč', 'validFrom' => 'Od', 'validTo' => 'Do'];

			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'paneTitle' => ['text' => 'Historie', 'class' => 'h2'],
				'header' => $ah, 'table' => $this->assignmentHistory, 'params' => []
			]);

		}
	}

	public function createContent ()
	{
		$this->tablePersons = $this->app()->table('e10.persons.persons');

		$this->createContentBody ();
	}
}
