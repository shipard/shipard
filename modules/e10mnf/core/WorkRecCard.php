<?php

namespace e10mnf\core;

use e10\utils;


/**
 * Class WorkRecCard
 * @package e10mnf\core
 */
class WorkRecCard extends \e10\DocumentCard
{
	var $dk;
	var $useRows;
	var $askPerson;
	var $askWorkOrder;
	var $askDate;
	var $askTime;

	public function createContentHeader ()
	{
		$this->header = [];
		$this->header['icon'] = $this->table->tableIcon($this->recData);

		$title = [];
		if ($this->recData ['subject'] !== '')
			$title[] = ['text' => $this->recData ['subject']];

		if ($this->recData['person'] !== 0)
		{
			$person = $this->app()->loadItem($this->recData['person'], 'e10.persons.persons');
			if ($person)
				$title[] = ['text' => $person['fullName']];
		}

		$title[] = ['text' => '#'.$this->recData ['docNumber'], 'class' => 'pull-right id'];

		$this->header['info'][] = ['class' => 'title', 'value' => $title];
	}

	public function createContentBody ()
	{
		$this->createContentInfo();

		if ($this->recData['text'] && $this->recData['text'] !== '')
			$this->addContent('body', ['type' => 'text', 'subtype' => 'auto', 'text' => $this->recData['text']]);

		$this->addContentAttachments ($this->recData['ndx']);
	}

	public function createContentInfo($tileMode = FALSE)
	{
		// -- header
		$hh = ['t' => 'Text', 'v' => 'Hodnota'];
		$ht = [];

		if ($this->askPerson === 0)
		{
			$tablePersons = $this->app()->table('e10.persons.persons');
			$personRecData = $tablePersons->loadItem ($this->recData['person']);
			$ht[] = ['t' => 'Pracovník', 'v' => $personRecData['fullName']];
		}

		if ($this->askTime === 0 && !$this->useRows)
		{
			$ht[] = ['t' => 'Od - do', 'v' => ['text' => utils::dateFromTo ($this->recData['beginDateTime'], $this->recData['endDateTime'], NULL)]];
		}

		if (!$this->useRows)
		{
			$allMinutes = utils::minutesToTime($this->recData['timeLen'] / 60);
			$timeLen = [
				'text' => $allMinutes,
				'suffix' => utils::nf($this->recData['timeLen'] / 60 / 60, 2) . ' hod',
			];

			$ht[] = ['t' => 'Doba', 'v' => $timeLen];

			if ($this->recData['subject'] !== '')
				$ht[] = ['t' => 'Popis', 'v' => $this->recData['subject']];
		}

		if ($this->askWorkOrder == 1)
		{
			$tableWorkOrders = $this->app()->table('e10mnf.core.workOrders');
			$woRecData = $tableWorkOrders->loadItem ($this->recData['workOrder']);
			$ht[] = ['t' => 'Zakázka', 'v' => ['text' => $woRecData['docNumber'], 'suffix' => $woRecData['title']]];
		}

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-top', 'type' => 'table', 'header' => $hh, 'table' => $ht,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'dcInfo fullWidth']]);


		if (!$this->useRows)
			return;

		// -- rows
		$q[] = 'SELECT [rows].*, ';
		array_push ($q,' workOrders.docNumber AS woDocNumber, workOrders.title AS woTitle, ');
		array_push ($q,' persons.fullName AS personName');
		array_push ($q,' FROM [e10mnf_core_workRecsRows] AS [rows]');
		array_push ($q,' LEFT JOIN [e10mnf_core_workOrders] AS workOrders ON [rows].workOrder = workOrders.ndx');
		array_push ($q,' LEFT JOIN [e10_persons_persons] AS persons ON [rows].person = persons.ndx');

		array_push ($q, ' WHERE [workRec] = %i', $this->recData['ndx']);

		$rh = [
			'rid' => ' #', 'dateWork' => ' Datum', 'person' => 'Osoba',
			'workOrder' => 'Zakázka',
			'be' => ' Od - do', 'timeLen' => ' Doba',
			'subject' => 'Text'
		];

		if ($this->askDate !== 2)
			unset($rh['dateWork']);

		$rt = [];

		$rows = $this->db()->query ($q);
		$rid = 1;
		$cntWorkOrders = 0;
		$cntBeginEnd = 0;
		foreach ($rows as $r)
		{
			$item = ['rid' => $rid, 'subject' => $r['subject']];

			if ($r['woDocNumber'])
			{
				$item['workOrder'] = [
					'text' => $r['woDocNumber'], 'title' => $r['woTitle'],
					'docAction' => 'edit', 'table' => 'e10mnf.core.workOrders', 'pk' => $r['workOrder']
				];
				$cntWorkOrders++;
			}

			$be = utils::dateFromTo ($r['beginDateTime'], $r['endDateTime'], NULL);
			if ($be)
			{
				$item['be'] = $be;
				$cntBeginEnd++;
			}

			if ($r['personName'])
				$item['person'] = $r['personName'];

			$item['timeLen'] = utils::minutesToTime($r['timeLen'] / 60);

			$rt[] = $item;
			$rid++;
		}

		if (!$cntWorkOrders)
			unset ($rh['workOrder']);
		if (!$cntBeginEnd)
			unset ($rh['be']);
		if ($this->askPerson === 0)
			unset ($rh['person']);

		$this->addContent('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $rh, 'table' => $rt]);
	}

	public function createContent ()
	{
		$this->newMode = 1;

		$this->dk = $this->app()->cfgItem ('e10pro.wkf.wrKinds.'.$this->recData['docKind'], FALSE);
		$this->useRows = (isset($this->dk['useRows']) && $this->dk['useRows']) ? 1 : 0;
		$this->askPerson = isset($this->dk['askPerson']) ? $this->dk['askPerson'] : 0;
		$this->askWorkOrder = isset($this->dk['askWorkOrder']) ? $this->dk['askWorkOrder'] : 0;
		$this->askDate = isset($this->dk['askDate']) ? $this->dk['askDate'] : 0;
		$this->askTime = isset($this->dk['askTime']) ? $this->dk['askTime'] : 0;

		/*
			$askProject = isset($dk['askProject']) ? $dk['askProject'] : 0;
			$askItem = isset($dk['askItem']) ? $dk['askItem'] : 0;
			$askPrice = isset($dk['askPrice']) ? $dk['askPrice'] : 0;
		*/

		$this->createContentHeader ();
		$this->createContentBody ();
	}
}
