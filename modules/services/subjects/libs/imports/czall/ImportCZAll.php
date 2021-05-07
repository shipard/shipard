<?php

namespace services\subjects\libs\imports\czall;

use e10\utils, e10\json, e10\str, e10\Utility;


/**
 * Class ImportAllCZ
 * @package services\subjects\libs
 *
 *  0: Název firmy,
 *  1: IČ,
 *  2: DIČ,
 *  3: Obec,
 *  4: Ulice,
 *  5: PSČ,
 *  6: Okres,
 *  7: Kraj,
 *  8: Telefon,
 *  9: Fax,
 * 10: Email,
 * 11: URL adresa,
 * 12: Kategorie ročního obratu,
 * 13: Počet zaměstnanců interval,
 * 14: Základní kapitál,
 * 15: Právní forma,
 * 16: Datum vzniku,
 * 17: NACE hlavní,
 * 18: Osoba,
 * 19: Funkce
*/
class ImportCZAll extends Utility
{
	var $importFile;

	var $nomencNACENdx = 0;
	var $foundedNace = [];
	var $unknownNace = [];

	var $nomencTOBENdx = 0;
	var $foundedTobe = [];
	var $unknownTobe = [];

	var $nomencEmpCntNdx = 0;
	var $foundedEmpCnt = [];
	var $unknownEmpCnt = [];

	var $nomencRevenueNdx = 0;
	var $foundedRevenue = [];
	var $unknownRevenue = [];

	var $nomencCorrections =[];
	var $nomencNdx =[];


	protected function deleteOld ()
	{
		$this->db()->query ('DELETE FROM [services_subjects_subjects]');
		$this->db()->query ('DELETE FROM [e10_persons_address] WHERE [tableid] = %s', 'services.subjects.subjects');
		$this->db()->query ('DELETE FROM [e10_base_properties] WHERE [tableid] = %s', 'services.subjects.subjects');
		$this->db()->query ('DELETE FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.subjects');
	}

	protected function deleteIndexes()
	{
		$this->db()->query ('DROP INDEX [s1] ON [services_subjects_subjects]');
		$this->db()->query ('DROP INDEX [s2] ON [services_subjects_subjects]');
		$this->db()->query ('DROP INDEX [fts] ON [services_subjects_subjects]');

		$this->db()->query ('DROP INDEX [s1] ON [e10_base_nomenc]');
		$this->db()->query ('DROP INDEX [s2] ON [e10_base_nomenc]');
	}

	public function init ()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nace')->fetch();
		if ($nomencType)
			$this->nomencNACENdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-tobe')->fetch();
		if ($nomencType)
			$this->nomencTOBENdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-empcnt')->fetch();
		if ($nomencType)
			$this->nomencEmpCntNdx = $nomencType['ndx'];

		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-revenue')->fetch();
		if ($nomencType)
			$this->nomencRevenueNdx = $nomencType['ndx'];

		$this->nomencNdx['cz-empcnt'] = $this->nomencEmpCntNdx;
		$this->nomencNdx['cz-nace'] = $this->nomencNACENdx;
		$this->nomencNdx['cz-tobe'] = $this->nomencTOBENdx;

		$this->nomencCorrections['cz-empcnt'] = utils::loadCfgFile(__APP_DIR__.'/e10-modules/services/subjects/libs/imports/czall/corrections-empcnt.json');
		$this->nomencCorrections['cz-nace'] = utils::loadCfgFile(__APP_DIR__.'/e10-modules/services/subjects/libs/imports/czall/corrections-nace.json');
		$this->nomencCorrections['cz-tobe'] = utils::loadCfgFile(__APP_DIR__.'/e10-modules/services/subjects/libs/imports/czall/corrections-tobe.json');
	}

	public function run()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$this->deleteIndexes();
		$this->deleteOld();

		$timeStart = time();

		$this->init();

		$this->importFile = new \SplFileObject('databaze.csv');
		$this->importFile->setMaxLineLen (2000);
		$fileSize = filesize('databaze.csv');

		$line = $this->importFile->fgets(); // skip header
		$rowNumber = 0;

		while (!$this->importFile->eof()) 
		{
			$line = $this->importFile->fgets();
			$cols = str_getcsv($line, ';');

			while (count($cols) < 17 && !$this->importFile->eof())
			{
				$line = str_replace(["\r", "\n"], '', $line);
				$line .= $this->importFile->fgets();
				$cols = str_getcsv($line, ';');
			}

			if (count($cols) < 17)
			{
				echo "#ERR1: ".$line;
				continue;
			}

			$docState = 4000;
			$docStateMain = 2;
			$revalidate = 90;
			
			$name = trim($cols[0]);
			if ($name === '')
			{
				$revalidate = 10;
				$docState = 9100;
				$docStateMain = 6;
			}

			// -- subject
			$subject = [
					'country' => 'cz', 'subjectType' => 1, 'company' => 1, 'fullName' => $name, 'lastName' => $name,
					'docState' => $docState, 'docStateMain' => $docStateMain, 'revalidate' => $revalidate,
			];

			if (strlen($cols[16]) === 10)
				$subject['validFrom'] = $cols[16];
			$this->db()->query ('INSERT INTO [services_subjects_subjects] ', $subject);
			$newNdx = intval ($this->db()->getInsertId ());

			// -- address
			$address = [
					'tableid' => 'services.subjects.subjects', 'recid' => $newNdx, 'type' => 1,
					'street' => trim($cols[4]), 'city' => trim($cols[3]), 'zipcode' => strval($cols[5]), 'country' => 'cz'
			];

			if (is_numeric($address['street']))
				$address['street'] = $address['city'].' '.$address['street'];

			if ($address['zipcode'] == '0')
				unset ($address['zipcode']);

			$address['city'] = trim(str::str_replace('(nečleněné město)', '', $address['city']));
			$address['city'] = trim(str::str_replace('(nečleněná část města)', '', $address['city']));

			if ($address['city'] !== '' && $address['street'] !== '')
				$this->db()->query ('INSERT INTO [e10_persons_address] ', $address);

			// -- oid
			$p = [
					'property' => 'e10srv-subj-id-oid', 'group' => 'e10srv-subj-id',
					'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
					'valueString' => trim($cols[1]), 'created' => new \DateTime ()
			];
			$this->db()->query ('INSERT INTO [e10_base_properties]', $p);

			// -- taxid
			if ($cols[2] !== '')
			{
				$p = [
						'property' => 'e10srv-subj-id-taxid', 'group' => 'e10srv-subj-id',
						'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
						'valueString' => trim($cols[2]), 'created' => new \DateTime ()
				];
				$this->db()->query('INSERT INTO [e10_base_properties]', $p);
			}

			// -- phone
			if ($cols[8] !== '')
			{
				$values = explode (',', $cols[8]);
				foreach ($values as $value)
				{
					$v = trim ($value);
					if (strlen ($v) < 9)
						continue;
					$p = [
							'property' => 'e10srv-subj-con-phone', 'group' => 'e10srv-subj-con',
							'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
							'valueString' => $v, 'created' => new \DateTime ()
					];
					$this->db()->query('INSERT INTO [e10_base_properties]', $p);
				}
			}

			// -- fax
			if ($cols[9] !== '')
			{
				$values = explode (',', $cols[9]);
				foreach ($values as $value)
				{
					$v = trim ($value);
					if (strlen ($v) < 9)
						continue;
					$p = [
							'property' => 'e10srv-subj-con-phone', 'group' => 'e10srv-subj-con',
							'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
							'valueString' => $v, 'note' => 'FAX', 'created' => new \DateTime ()
					];
					$this->db()->query('INSERT INTO [e10_base_properties]', $p);
				}
			}

			// -- email
			if ($cols[10] !== '')
			{
				$values = explode (',', $cols[10]);
				foreach ($values as $value)
				{
					$v = trim ($value);
					if (strlen ($v) < 5)
						continue;
					$p = [
							'property' => 'e10srv-subj-con-email', 'group' => 'e10srv-subj-con',
							'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
							'valueString' => $v, 'created' => new \DateTime ()
					];
					$this->db()->query('INSERT INTO [e10_base_properties]', $p);
				}
			}

			// -- web
			if ($cols[11] !== '')
			{
				$values1 = explode (',', $cols[11]);

				foreach ($values1 as $value1)
				{
					$values2 = explode ('www.', $value1);
					foreach ($values2 as $value2)
					{
						if (count($values2) > 1)
							$v = 'www.'.trim($value2);
						else
							$v = trim($value1);

						if (strlen($v) < 5)
							continue;
						$p = [
								'property' => 'e10srv-subj-con-web', 'group' => 'e10srv-subj-con',
								'tableid' => 'services.subjects.subjects', 'recid' => $newNdx,
								'valueString' => $v, 'created' => new \DateTime ()
						];
						$this->db()->query('INSERT INTO [e10_base_properties]', $p);
					}
				}
			}

			// -- CZ-NACE
			$naceNdx = $this->searchNACE(str::toDb($cols[17]));
			if ($naceNdx)
			{
				$n = [
						'nomencType' => $this->nomencNACENdx, 'nomencItem' => $naceNdx,
						'tableId' => 'services.subjects.subjects', 'recId' => $newNdx
				];
				$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			}

			// -- CZ-TOBE
			$tobeNdx = $this->searchTOBE(str::toDb($cols[15]));
			if ($tobeNdx)
			{
				$n = [
						'nomencType' => $this->nomencTOBENdx, 'nomencItem' => $tobeNdx,
						'tableId' => 'services.subjects.subjects', 'recId' => $newNdx
				];
				$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			}

			// -- CZ-EmpCnt
			$empCntNdx = $this->searchEmpCnt(str::toDb($cols[13]));
			if ($empCntNdx)
			{
				$n = [
						'nomencType' => $this->nomencEmpCntNdx, 'nomencItem' => $empCntNdx,
						'tableId' => 'services.subjects.subjects', 'recId' => $newNdx
				];
				$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			}

			// -- CZ-Revenue
			$revenueNdx = $this->searchRevenue(str::toDb($cols[12]));
			if ($revenueNdx)
			{
				$n = [
						'nomencType' => $this->nomencRevenueNdx, 'nomencItem' => $revenueNdx,
						'tableId' => 'services.subjects.subjects', 'recId' => $newNdx
				];
				$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			}

			$rowNumber++;

			if ($rowNumber % 25000 === 0)
			{
				$filePos = $this->importFile->ftell();
				$timeNow = time();
				$doneRatio = $filePos / $fileSize;
				$etaSecs = intval(($timeNow - $timeStart) / $doneRatio);
				$etaMinutes = intval($etaSecs / 60);
				$eta = utils::minutesToTime($etaMinutes);
				$timeEnd = $timeStart + $etaSecs + date('Z');
				$etaDate = new \DateTime('@'.$timeEnd);
				$etaDateStr = $etaDate->format ('H:i:s');

				echo "~ ".utils::nf($rowNumber)." recs, ".utils::nf(($doneRatio) * 100, 1)." %, ETA $etaDateStr\n";
			}

			//if ($rowNumber > 10000)
			//	break;
		}

		file_put_contents('corrections-nace.json', json::lint($this->unknownNace));
		file_put_contents('corrections-tobe.json', json::lint($this->unknownTobe));
		file_put_contents('corrections-empcnt.json', json::lint($this->unknownEmpCnt));
		file_put_contents('corrections-revenue.json', json::lint($this->unknownRevenue));

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins\n";
	}

	function searchNACE ($text)
	{
		if (isset($this->unknownNace[$text]))
			return 0;

		$corrNdx = $this->searchNomenclatureCorrection ('cz-nace', $text);
		if ($corrNdx)
			return $corrNdx;

		if (isset($this->foundedNace[$text]))
			return $this->foundedNace[$text];

		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [fullName] LIKE %s', '%'.$text.'%',
				' AND [nomencType] = %i', $this->nomencNACENdx, ' AND [docStateMain] = 2',
				' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			if (!isset($this->unknownNace[$text]))
				$this->unknownNace[$text] = '';

			return 0;
		}

		$this->foundedNace[$text] = $exist['ndx'];
		return $exist['ndx'];
	}

	function searchTOBE ($text)
	{
		$corrNdx = $this->searchNomenclatureCorrection ('cz-tobe', $text);
		if ($corrNdx)
			return $corrNdx;

		if (isset($this->foundedTobe[$text]))
			return $this->foundedTobe[$text];

		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [fullName] LIKE %s', '%'.$text.'%',
				' AND [nomencType] = %i', $this->nomencTOBENdx, ' AND [docStateMain] = 2',
				' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			if (!isset($this->unknownTobe[$text]))
				$this->unknownTobe[$text] = '';

			return 0;
		}

		$this->foundedTobe[$text] = $exist['ndx'];
		return $exist['ndx'];
	}

	function searchEmpCnt ($text)
	{
		$corrNdx = $this->searchNomenclatureCorrection ('cz-empcnt', $text);
		if ($corrNdx)
			return $corrNdx;

		if (isset($this->foundedEmpCnt[$text]))
			return $this->foundedEmpCnt[$text];

		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [fullName] LIKE %s', '%'.$text.'%',
				' AND [nomencType] = %i', $this->nomencEmpCntNdx, ' AND [docStateMain] = 2',
				' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			if (!isset($this->unknownEmpCnt[$text]))
				$this->unknownEmpCnt[$text] = '';

			return 0;
		}

		$this->foundedEmpCnt[$text] = $exist['ndx'];
		return $exist['ndx'];
	}

	function searchRevenue ($text)
	{
		if (isset($this->foundedRevenue[$text]))
			return $this->foundedRevenue[$text];

		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [fullName] LIKE %s', '%'.$text.'%',
				' AND [nomencType] = %i', $this->nomencRevenueNdx, ' AND [docStateMain] = 2',
				' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			if (!isset($this->unknownRevenue[$text]))
				$this->unknownRevenue[$text] = '';

			return 0;
		}

		$this->foundedRevenue[$text] = $exist['ndx'];
		return $exist['ndx'];
	}

	public function searchNomenclatureCorrection ($nomencId, $nomencString)
	{
		if (isset ($this->nomencCorrections[$nomencId][$nomencString]))
		{
			if (is_string($this->nomencCorrections[$nomencId][$nomencString]))
			{
				$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [itemId] = %s', $this->nomencCorrections[$nomencId][$nomencString],
						' AND [nomencType] = %i', $this->nomencNdx[$nomencId], ' AND [docStateMain] = 2',
						' ORDER BY [order], [level], [ndx]')->fetch();
				if ($exist)
				{
					$this->nomencCorrections[$nomencId][$nomencString] = intval($exist['ndx']);
					return $this->nomencCorrections[$nomencId][$nomencString];
				}
			}
			else
				return $this->nomencCorrections[$nomencId][$nomencString];
		}
		return 0;
	}
}
