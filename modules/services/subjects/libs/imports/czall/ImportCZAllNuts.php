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
class ImportCZAllNuts extends Utility
{
	var $importFile;

	var $nomencNutsNdx = 0;
	var $foundedNuts = [];
	var $unknownNuts = [];

	var $nomencCorrections = [];
	var $nomencNdx = [];

	public function init ()
	{
		$nomencType = $this->db()->query('SELECT * FROM [e10_base_nomencTypes] WHERE [id] = %s', 'cz-nuts')->fetch();
		if ($nomencType)
			$this->nomencNutsNdx = $nomencType['ndx'];

		$this->nomencNdx['cz-nuts'] = $this->nomencNutsNdx;
		$this->nomencCorrections['cz-nuts'] = utils::loadCfgFile(__APP_DIR__.'/e10-modules/services/subjects/libs/imports/czall/corrections-nuts.json');

		echo "Deleting old nuts records...\n";
		$this->db()->query ('DELETE FROM [e10_base_nomenc] WHERE [tableId] = %s', 'services.subjects.subjects', ' AND [nomencType] = %i', $this->nomencNutsNdx);
	}

	public function run()
	{
		$dateStart = new \DateTime();
		echo "START: ".$dateStart->format('Y-m-d H:i:s')."\n";

		$timeStart = time();

		$this->init();

		$this->importFile = new \SplFileObject('databaze.csv');
		$this->importFile->setMaxLineLen (2000);
		$fileSize = filesize('databaze.csv');

		$line = $this->importFile->fgets(); // skip header
		$rowNumber = 0;

		echo "Importing...\n";
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



			// -- address
			$address = [
					'tableid' => 'services.subjects.subjects',
					'street' => trim($cols[4]), 'city' => trim($cols[3]), 'zipcode' => strval($cols[5]), 'country' => 'cz'
			];

			if (is_numeric($address['street']))
				$address['street'] = $address['city'].' '.$address['street'];

			if ($address['zipcode'] == '0')
				unset ($address['zipcode']);

			$address['city'] = trim(str::str_replace('(nečleněné město)', '', $address['city']));
			$address['city'] = trim(str::str_replace('(nečleněná část města)', '', $address['city']));

			if ($address['city'] === '' && $address['street'] === '')
				continue;

			// -- oid
			$oid = trim($cols[1]);
			$subjectLink = $this->db()->query ('SELECT recid FROM [e10_base_properties] ',
					'WHERE [valueString] = %s', $oid, ' AND [tableId] = %s', 'services.subjects.subjects',
					' AND [property] = %s', 'e10srv-subj-id-oid'
			)->fetch();
			if (!$subjectLink)
			{
				echo "OID $oid not found...\n";
				continue;
			}

			$subjectNdx = $subjectLink['recid'];
			/*
			$subject = $this->db()->query('SELECT * FROM [services_subjects_subjects] WHERE ndx = %i', $subjectNdx)->fetch();
			if (!$subject)
			{
				echo "SUBJECT $oid / $subjectNdx not found...\n";
				continue;
			}*/

			// -- CZ-NUTS-2
			$nuts2Ndx = $this->searchNuts(str::toDb($cols[7]));
			if ($nuts2Ndx)
			{
				$n = [
						'nomencType' => $this->nomencNutsNdx, 'nomencItem' => $nuts2Ndx,
						'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
				];
				$this->db()->query('INSERT INTO [e10_base_nomenc]', $n);
			}

			// -- CZ-NUTS-3
			$nts3 = str::toDb(str::str_replace('Okres ', '', $cols[6]));
			$nuts3Ndx = $this->searchNuts($nts3);
			if ($nuts3Ndx)
			{
				$n = [
						'nomencType' => $this->nomencNutsNdx, 'nomencItem' => $nuts3Ndx,
						'tableId' => 'services.subjects.subjects', 'recId' => $subjectNdx
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

		$dateDone = new \DateTime();
		echo "DONE: ".$dateDone->format('Y-m-d H:i:s')."; ";
		echo utils::dateDiffMinutes($dateDone, $dateStart);
		echo " mins\n";

		file_put_contents('corrections-nuts.json', json::lint($this->unknownNuts));
	}

	function searchNuts ($text)
	{
		if (isset($this->unknownNuts[$text]))
			return 0;

		$corrNdx = $this->searchNomenclatureCorrection ('cz-nuts', $text);
		if ($corrNdx)
			return $corrNdx;

		if (isset($this->foundedNuts[$text]))
			return $this->foundedNuts[$text];

		$exist = $this->db()->query ('SELECT * FROM [e10_base_nomencItems] WHERE [fullName] = %s', $text,
				' AND [nomencType] = %i', $this->nomencNutsNdx, ' AND [docStateMain] = 2',
				' ORDER BY [order], [level], [ndx]')->fetch();

		if (!$exist)
		{
			if (!isset($this->unknownNuts[$text]))
				$this->unknownNuts[$text] = '';

			return 0;
		}

		$this->foundedNuts[$text] = $exist['ndx'];
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
