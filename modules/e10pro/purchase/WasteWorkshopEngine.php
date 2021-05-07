<?php

namespace e10pro\purchase;


use \e10\Utility;


/**
 * Class WasteWorkshopEngine
 * @package e10pro\purchase
 */
class WasteWorkshopEngine extends Utility
{
	/* var \e10\DbTable */
	var $tablePersons;
	var $personNdx = 0;
	var $personRecData = NULL;
	var $personCID = '';

	var $remoteWorkshops = [];

	public function setPerson ($personNdx)
	{
		$this->personNdx = $personNdx;
		$this->tablePersons = $this->app()->table('e10.persons.persons');
		$this->personRecData = $this->tablePersons->loadItem ($this->personNdx);


		$qid = [];
		array_push($qid, 'SELECT * FROM [e10_base_properties] as props');
		array_push($qid, ' WHERE [tableid] = %s', 'e10.persons.persons',
			' AND [group] = %s', 'ids', ' AND [property] = %s', 'oid');
		array_push($qid, ' AND recid = %i', $this->personNdx);

		$cidRec = $this->db()->query ($qid)->fetch();
		if (!$cidRec)
			return;
		$this->personCID = $cidRec['valueString'];
	}

	public function loadRemote ()
	{
		$personInfoStr = file_get_contents('https://services.shipard.com/feed/subject-info/'.$this->personCID);
		if (!$personInfoStr)
			return;

		$personInfo = json_decode($personInfoStr, TRUE);
		if (!$personInfo || !isset($personInfo['subjects'][0]))
			return;

		$pi = $personInfo['subjects'][0];
		if (!isset($pi['addresses']) || !count($pi['addresses']))
			return;

		$firstSuggestedNdx = 0;
		$secondSuggestedNdx = 0;
		$cnt = 0;
		forEach ($pi['addresses'] as $address)
		{
			if ($address['type'] != 99)
				continue;
			$workshopId = $address['specification'];
			$a = $address;
			$a['order'] = $cnt + 10;


			//$this->db()->query ('INSERT INTO [e10_persons_address]', $newAddress);
			//$newNdx = intval ($this->db()->getInsertId ());
			//if (!$firstAddNdx)
			//	$firstAddNdx = $newNdx;
			if (!$firstSuggestedNdx && $a['city'] === 'Zlín')
				$a['order'] = 1;
			if ($a['order'] !== 1 && substr($a['zipcode'], 0, 2) === '76')
				$a['order'] = 2;

			$this->remoteWorkshops[$workshopId] = $a;
			$cnt++;
		}
	}
}