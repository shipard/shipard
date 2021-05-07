<?php

namespace services\subjects\libs\tools;

use e10\utils, e10\json, e10\str, e10\Utility;


class UpdateFromRes extends Utility
{
	var $nomencNACENdx = 0;
	var $nomencTOBENdx = 0;
	var $nomencNutsNdx = 0;
	var $nomencZujNdx = 0;

	public function init ()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nace')->fetch();
		if ($nomencType)
			$this->nomencNACENdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-tobe')->fetch();
		if ($nomencType)
			$this->nomencTOBENdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-zuj')->fetch();
		if ($nomencType)
			$this->nomencZujNdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();
		if ($nomencType)
			$this->nomencNutsNdx = $nomencType['ndx'];
	}

	public function run()
	{
		$this->run2();
		$this->run3();
	}

	public function run2()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$this->init();

		$rowNumber = 0;
		$progressFlag = '.';

		$q = [];
		array_push($q, 'SELECT * FROM [services_subjregs_cz_res_plus_rzpSubj]');

		$this->db()->begin();
		$rows = $this->db()->query($q);
		foreach ($rows as $srcSubject)
		{
			$subjectNdx = 0;
			$subjectId = $srcSubject['ico'];
			$subjectIdShort = ltrim ($srcSubject['ico'], '0');

			$qid = [];
			array_push($qid, 'SELECT * FROM [e10_base_properties]');
			array_push($qid, ' WHERE [tableid] = %s', 'services.subjects.subjects',
				' AND [group] = %s', 'e10srv-subj-id', ' AND [property] = %s', 'e10srv-subj-id-oid',
				' AND valueString = %s', $subjectIdShort);
			$subjectIdRec = $this->db()->query($qid)->fetch();
			if ($subjectIdRec)
				$subjectNdx = $subjectIdRec['recid'];

			$dstSubject = NULL;
			if ($subjectNdx)
			{
				$dstSubject = $this->db()->query ('SELECT * FROM [services_subjects_subjects] WHERE [ndx] = %i', $subjectNdx)->fetch();
			}
			else
			{
				$dstSubject = [
					'lastName' => $srcSubject['nazev'], 'fullName' => $srcSubject['nazev'],
					'country' => 'cz', 'company' => 1,
				];

				$this->db()->query('INSERT INTO [services_subjects_subjects] ', $dstSubject);
				$subjectNdx = intval ($this->db()->getInsertId ());

				$p = [
					'property' => 'e10srv-subj-id-oid', 'group' => 'e10srv-subj-id',
					'tableid' => 'services.subjects.subjects', 'recid' => $subjectNdx,
					'valueString' => $subjectIdShort, 'created' => new \DateTime ()
				];
				$this->db()->query('INSERT INTO [e10_base_properties]', $p);
				$progressFlag = '*';
				//echo "### ERR: subj #$subjectId has no ndx ".json_encode($srcSubject)."\n";
			}

			// -- load address
			$existedAddress = $this->db()->query ('SELECT * FROM [e10_persons_address] WHERE tableid = %s', 'services.subjects.subjects',
				' AND recid = %i', $subjectNdx)->fetch();
			if (!$existedAddress)
				$existedAddress = FALSE;

			$updatedSubject = [];
			$updatedAddress = [];

			if ($dstSubject['fullName'] !== $srcSubject['nazev'])
			{
				$updatedSubject['fullName'] = $srcSubject['nazev'];
				$updatedSubject['lastName'] = $srcSubject['nazev'];
				$progressFlag = '*';
				//echo "###NAME1: {$srcSubject['nazev']} x {$dstSubject['fullName']}\n";
			}

			if (!$existedAddress || $existedAddress['street'] !== $srcSubject['ulice'])
				$updatedAddress['street'] = $srcSubject['ulice'];
			if (!$existedAddress || $existedAddress['city'] !== $srcSubject['obec'])
				$updatedAddress['city'] = $srcSubject['obec'];
			if (!$existedAddress || $existedAddress['zipcode'] !== $srcSubject['psc'])
				$updatedAddress['zipcode'] = $srcSubject['psc'];

			if (count($updatedSubject))
			{
				$this->db()->query('UPDATE [services_subjects_subjects] SET ', $updatedSubject, ' WHERE [ndx] = ', $subjectNdx);
				$progressFlag = '*';
			}

			if (count($updatedAddress))
			{
				if (!$existedAddress)
				{
					$updatedAddress['tableid'] = 'services.subjects.subjects';
					$updatedAddress['recid'] = $subjectNdx;

					$this->db()->query('INSERT INTO [e10_persons_address] ', $updatedAddress);
					$progressFlag = '*';
					//echo "NEW addr !{$srcSubject['nazev']}!: ".json_encode($updatedAddress)."\n";
				}
				else
				{
					$updatedAddress['locHash'] = '';
					$updatedAddress['locState'] = 0;

					$this->db()->query('UPDATE [e10_persons_address] SET ', $updatedAddress, ' WHERE [ndx] = %i', $existedAddress['ndx']);
					$progressFlag = '*';
					//echo "UPDATED addr: ".json_encode($updatedAddress)."\n";
				}
			}

			if ($rowNumber % 2500 === 0)
				echo $progressFlag;

			if ($rowNumber % 100 === 0)
			{
				$this->db()->commit();
				$this->db()->begin();
				$progressFlag = '.';
			}

			$rowNumber++;
		}
		$this->db()->commit();

		echo "\n";

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins, ".$rowNumber." subjects\n";
	}


	public function run3()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$this->init();

		$rowNumber = 0;
		$progressFlag = '.';

		$q = [];
		array_push($q, 'SELECT * FROM [services_subjregs_cz_res_plus_res] ORDER BY ndx');

		$this->db()->begin();
		$rows = $this->db()->query($q);
		foreach ($rows as $srcSubject)
		{
			$subjectNdx = 0;
			$subjectId = $srcSubject['ico'];
			$subjectIdShort = ltrim ($srcSubject['ico'], '0');

			$qid = [];
			array_push($qid, 'SELECT * FROM [e10_base_properties]');
			array_push($qid, ' WHERE [tableid] = %s', 'services.subjects.subjects',
				' AND [group] = %s', 'e10srv-subj-id', ' AND [property] = %s', 'e10srv-subj-id-oid',
				' AND valueString = %s', $subjectIdShort);
			$subjectIdRec = $this->db()->query($qid)->fetch();
			if ($subjectIdRec)
				$subjectNdx = $subjectIdRec['recid'];

			$dstSubject = NULL;
			if ($subjectNdx)
			{
				$dstSubject = $this->db()->query ('SELECT * FROM [services_subjects_subjects] WHERE [ndx] = %i', $subjectNdx)->fetch();
			}
			else
			{
				$dstSubject = [
					'lastName' => $srcSubject['nazev'], 'fullName' => $srcSubject['nazev'],
					'country' => 'cz', 'company' => 1,
					'validFrom' => $srcSubject['datvzn'], 'validTo' => $srcSubject['datzan'],
				];

				$this->db()->query('INSERT INTO [services_subjects_subjects] ', $dstSubject);
				$subjectNdx = intval ($this->db()->getInsertId ());

				$p = [
					'property' => 'e10srv-subj-id-oid', 'group' => 'e10srv-subj-id',
					'tableid' => 'services.subjects.subjects', 'recid' => $subjectNdx,
					'valueString' => $subjectIdShort, 'created' => new \DateTime ()
				];
				$this->db()->query('INSERT INTO [e10_base_properties]', $p);
				$progressFlag = '*';

				//echo "---NEW: ".json_encode($dstSubject)." \n";
			}

			// -- load address
			$existedAddress = $this->db()->query ('SELECT * FROM [e10_persons_address] WHERE tableid = %s', 'services.subjects.subjects',
				' AND recid = %i', $subjectNdx)->fetch();
			if (!$existedAddress)
				$existedAddress = FALSE;

			$updatedSubject = [];
			$updatedAddress = [];

			// -- name
			$shortNameLen = str::strlen($srcSubject['nazev']);
			if (str::substr($dstSubject['fullName'], 0, $shortNameLen) !== $srcSubject['nazev'])
			{
				$updatedSubject['fullName'] = $srcSubject['nazev'];
				$updatedSubject['lastName'] = $srcSubject['nazev'];
				//echo "###NAME1: {$srcSubject['nazev']} x {$dstSubject['fullName']}\n";
			}

			// -- date
			$dateSrcFrom = utils::datef($srcSubject['datvzn']);
			$dateDstFrom = utils::datef($dstSubject['validFrom']);
			if ($dateSrcFrom !== $dateDstFrom)
				$updatedSubject['validFrom'] = $srcSubject['datvzn'];

			$dateSrcTo = utils::datef($srcSubject['datzan']);
			$dateDstTo = utils::datef($dstSubject['validTo']);
			if ($dateSrcTo !== $dateDstTo)
				$updatedSubject['validTo'] = $srcSubject['datzan'];

			// -- NACE
			if ($srcSubject['okec6a'] != '')
			{
				$existedNace = $this->db()->query('SELECT * FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.subjects',
					' AND [nomencType] = %i', $this->nomencNACENdx, ' AND [recId] = %i', $subjectNdx)->fetch();
				$naceNdx = $this->searchNACE($srcSubject['okec6a']);
				if ($naceNdx)
				{
					if (!$existedNace)
					{
						$n = [
							'nomencType' => $this->nomencNACENdx, 'nomencItem' => $naceNdx,
							'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
						];
						$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
						//echo "---new-nace {$srcSubject['nazev']} \n";
					}
					else
					{
						if ($naceNdx !== $existedNace['nomencItem'])
						{
							$this->db()->query('UPDATE [e10_base_nomenc] SET nomencItem = %i', $naceNdx, ' WHERE [ndx] = %i', $existedNace['ndx']);
							//echo "---updated-nace {$srcSubject['nazev']} \n";
						}
					}
				}
			}

			// -- CZ-TOBE
			if ($srcSubject['forma'] != '')
			{
				$existedTobe = $this->db()->query('SELECT * FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.subjects',
					' AND [nomencType] = %i', $this->nomencTOBENdx, ' AND [recId] = %i', $subjectNdx)->fetch();
				$tobeNdx = $this->searchTOBE($srcSubject['forma']);
				if ($tobeNdx)
				{
					if (!$existedTobe)
					{
						$n = [
							'nomencType' => $this->nomencTOBENdx, 'nomencItem' => $tobeNdx,
							'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
						];
						$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
						//echo "---new-tobe {$srcSubject['nazev']} \n";
					}
					else
					{
						if ($tobeNdx !== $existedTobe['nomencItem'])
						{
							$this->db()->query('UPDATE [e10_base_nomenc] SET nomencItem = %i', $tobeNdx, ' WHERE [ndx] = %i', $existedTobe['ndx']);
							//echo "---updated-tobe {$srcSubject['nazev']} \n";
						}
					}
				}
			}

			// -- address
			if (!$existedAddress || $existedAddress['street'] !== $srcSubject['ulice'])
				$updatedAddress['street'] = $srcSubject['ulice'];
			if (!$existedAddress || $existedAddress['city'] !== $srcSubject['obec'])
				$updatedAddress['city'] = $srcSubject['obec'];
			if (!$existedAddress || $existedAddress['zipcode'] !== $srcSubject['psc'])
				$updatedAddress['zipcode'] = $srcSubject['psc'];

			// -- ZUJ
			$this->checkZUJ ($srcSubject['zuj'], $subjectNdx, $dstSubject, $updatedSubject);

			// -- state
			$updatedSubject['revalidate'] = 90;
			if (!utils::dateIsBlank($srcSubject['datzan']))
			{
				$updatedSubject['docState'] = 9000;
				$updatedSubject['docStateMain'] = 5;
			}


			if (count($updatedSubject))
			{
				$this->db()->query('UPDATE [services_subjects_subjects] SET ', $updatedSubject, ' WHERE [ndx] = ', $subjectNdx);
				//echo "---UPDATE {$srcSubject['nazev']}: ".json_encode($updatedSubject)." \n";
				$progressFlag = '*';
			}

			if (count($updatedAddress))
			{
				if (!$existedAddress)
				{
					$updatedAddress['tableid'] = 'services.subjects.subjects';
					$updatedAddress['recid'] = $subjectNdx;

					$this->db()->query('INSERT INTO [e10_persons_address] ', $updatedAddress);
					$progressFlag = '*';
					//echo "NEW addr !{$srcSubject['nazev']}!: ".json_encode($updatedAddress)."\n";
				}
				else
				{
					$updatedAddress['locHash'] = '';
					$updatedAddress['locState'] = 0;

					$this->db()->query('UPDATE [e10_persons_address] SET ', $updatedAddress, ' WHERE [ndx] = %i', $existedAddress['ndx']);
					$progressFlag = '*';
					//echo "UPDATED addr: ".json_encode($updatedAddress)."\n";
				}
			}

			if ($rowNumber % 2500 === 0)
				echo $progressFlag;

			if ($rowNumber % 100 === 0)
			{
				$this->db()->commit();
				$this->db()->begin();
				$progressFlag = '.';
			}

			$rowNumber++;
		}
		$this->db()->commit();

		echo "\n";

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins, ".$rowNumber." subjects\n";
	}



	function searchNACE ($naceId)
	{
		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [itemId] = %s', $naceId,
			' AND [nomencType] = %i', $this->nomencNACENdx, ' AND [docStateMain] = 2')->fetch();

		if (!$exist)
		{
			//echo "!!! NACE '$naceId' not found\n";
			return 0;
		}

		return $exist['ndx'];
	}

	function searchTOBE ($tobeId)
	{
		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [itemId] = %s', $tobeId,
			' AND [nomencType] = %i', $this->nomencTOBENdx, ' AND [docStateMain] = 2',
			' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			//echo "!!! TOBE '$tobeId' not found\n";
			return 0;
		}

		return $exist['ndx'];
	}

	function checkZUJ ($zujId, $subjectNdx, $dstSubject, &$updatedSubject)
	{
		$zujRec = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [itemId] = %s', $zujId,
			' AND [nomencType] = %i', $this->nomencZujNdx, ' AND [docStateMain] = 2',
			' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$zujRec)
			return;

		$zujNuts4Rec = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $zujRec['ownerItem'])->fetch();
		if (!$zujNuts4Rec)
			return;

		$zujNuts3Rec = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $zujNuts4Rec['ownerItem'])->fetch();
		if (!$zujNuts3Rec)
			return;

		//echo "nuts for '$zujId' founds... \n";


		// -- load current
		$existed = [];
		$existedRows = $this->db()->query('SELECT * FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.subjects',
			' AND [nomencType] = %i', $this->nomencNutsNdx, ' AND [recId] = %i', $subjectNdx, ' ORDER BY ndx');
		foreach ($existedRows as $er)
			$existed[] = $er->toArray();

		$replace = FALSE;
		if (count($existed) !== 2)
			$replace = TRUE;

		//enumIntNdx
		if (isset($existed[0]) && $existed[0]['nomencItem'] !== $zujNuts3Rec['ndx'])
			$replace = TRUE;
		if (isset($existed[1]) && $existed[1]['nomencItem'] !== $zujNuts4Rec['ndx'])
			$replace = TRUE;

		if (!$replace)
			return;
		//echo "  do replace\n";

		$updatedSubject['region1'] = $zujNuts3Rec['enumIntNdx'];
		$updatedSubject['region2'] = $zujNuts4Rec['enumIntNdx'];

		if (isset($existed[0]))
		{
			$this->db()->query ('UPDATE [e10_base_nomenc] SET [nomencItem] = %i', $zujNuts3Rec['ndx'], ' WHERE [ndx] = %i', $existed[0]['ndx']);
			//echo "UPDATE R1\n";
		}
		else
		{
			$n = [
				'nomencType' => $this->nomencNutsNdx, 'nomencItem' => $zujNuts3Rec['ndx'],
				'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
			];
			$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			//echo "INSERT R1\n";
		}

		if (isset($existed[1]))
		{
			$this->db()->query ('UPDATE [e10_base_nomenc] SET [nomencItem] = %i', $zujNuts4Rec['ndx'], ' WHERE [ndx] = %i', $existed[1]['ndx']);
			//echo "UPDATE R2\n";
		}
		else
		{
			$n = [
				'nomencType' => $this->nomencNutsNdx, 'nomencItem' => $zujNuts4Rec['ndx'],
				'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
			];
			$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			//echo "INSERT R2\n";
		}
	}
}
