<?php

namespace services\persons\libs\cz;

use services\persons\libs\InitialImportPersons;
use \Shipard\Utils\Utils, \Shipard\Utils\Str;


class InitialImportPersonsCZ extends InitialImportPersons
{
	public function initialImportARES()
	{
		echo "=== ARES ===\n";

		$fn = __APP_DIR__.'/res/ares_seznamIC_VR.csv';
		$cnt = 0;

		$archiveFileName = __APP_DIR__.'/res/ares_vreo_all.tar';

		ini_set('memory_limit', '1024M');
		$archive = new \PharData($archiveFileName);

		if ($file = fopen($fn, "r"))
		{
			while(!feof($file))
			{
				$line = fgets($file);
				//echo (trim($line)."\n");
				if ($line === '')
					continue;
				$parts = explode(';', $line);
				$oid = sprintf('%08d', intval($parts[0]));
				//echo ($oid.";");

				if ($this->companyId !== '' && $this->companyId !== ltrim($parts[0], " \t\n\r\0\x0B0"))
					continue;

				$oneTarFileName = 'VYSTUP/DATA/'.$oid.'.xml';
				$xmlTarFileName = __APP_DIR__.'/tmp/'.$oneTarFileName;

				try
				{
					$archive->extractTo(__APP_DIR__.'/tmp/', './'.$oneTarFileName, TRUE);
				}
				catch (\Exception $e)
				{

				}

				if (!is_readable($xmlTarFileName))
					continue;

				$data = file_get_contents($xmlTarFileName);

				if (!$this->importOnePersonARES($data, $oid))
					continue;
				if ($this->debug)
					echo $data."\n-------------\n\n";

				if (!$this->debug)
					unlink($xmlTarFileName);

				$cnt++;

				if ($cnt % 10000 === 0)
					echo ' '.$cnt;
				if ($this->maxCount && $cnt > $this->maxCount)
					break;
			}
			fclose($file);

			echo "\n\n";
		}
		else
		{

		}
	}

	public function importOnePersonARES($xmlString, $oid)
	{
		$onePerson = new \services\persons\libs\cz\OnePersonARES($this->app);
		$onePerson->setData($xmlString);
		if (!$onePerson->parse())
		{
			echo "ERROR1: ========== parse failed ==========\n";
			echo $xmlString."\n\n";
			return 0;
		}
		if ($onePerson->person['base']['oid'] === '')
			$onePerson->person['base']['oid'] = $oid;

		$newPersonNdx = $this->savePerson($onePerson->person, $xmlString, 1);

		return $newPersonNdx;
	}

	public function initialImportRES()
	{
		echo "=== RES ===\n";

		$fn = __APP_DIR__.'/res/res_data.csv';
		$cnt = 0;

		if ($file = fopen($fn, "r"))
		{
			while(!feof($file))
			{
				$line = fgets($file);
				if ($line === '')
					continue;
				if ($cnt === 0)
				{
					$cnt = 1;
					continue;
				}

				if ($this->companyId !== '')
				{
					$cols = str_getcsv($line, ',');
					if ($this->companyId !== ltrim($cols[0], " \t\n\r\0\x0B0"))
						continue;
				}

				if ($this->debug)
					echo (trim($line)."\n");

				$this->importOnePersonRES($line, '');
				$cnt++;

				if ($cnt % 10000 === 0)
					echo ' '.$cnt;

				if ($this->maxCount && $cnt > $this->maxCount)
					break;
			}
			fclose($file);

			echo "\n\n";
		}
		else
		{

		}
	}

	public function importOnePersonRES($csvString, $oid)
	{
		$onePerson = new \services\persons\libs\cz\OnePersonRES($this->app);
		$onePerson->setData($csvString);
		if (!$onePerson->parse())
			return FALSE;

		if ($onePerson->person['base']['oid'] === '')
			return FALSE;
		if ($onePerson->person['base']['fullName'] === '')
			return FALSE;

		$this->savePerson($onePerson->person, $csvString, 2);

		return TRUE;
	}

	function savePerson($person, string $srcData, int $regType)
	{
		$this->db()->begin();
		$qe = [];
		array_push($qe, 'SELECT * FROM [services_persons_persons]');
		array_push($qe, ' WHERE [oid] = %s', $person['base']['oid']);
		array_push($qe, ' AND [country] = %i', $person['base']['country']);

		$exist = $this->db()->query($qe)->fetch();
		$now = new \DateTime();
		if ($exist)
		{
			$personNdx = $exist['ndx'];
			if ($exist['originalName'] !== '' && $exist['originalName'][0] === '-' && $person['base']['originalName'] !== '' && $person['base']['originalName'][0] !== '-')
			{
				$update = [
					'originalName' => $person['base']['originalName'],
					'fullName' => $this->checkName($person['base']['originalName']),
					'cleanedName' => 0,
				];
				if ($update['fullName'] !== $update['originalName'])
					$update['cleanedName'] = 1;

				$this->db()->query('UPDATE [services_persons_persons] SET ', $update, ' WHERE [ndx] = %i', $personNdx);
			}
		}
		else
		{
			$iid = Utils::createToken(8, FALSE, TRUE);
			$insert = $person['base'];
			$insert['fullName'] = $this->checkName($insert['originalName']);
			$insert['created'] = $now;
			$insert['updated'] = $now;
			$insert['iid'] = $iid;
			$insert['vatState'] = 99;

			$insert['cleanedName'] = 0;
			if ($insert['fullName'] !== $insert['originalName'])
				$insert['cleanedName'] = 1;

			$insert['valid'] = TRUE;
			if (!Utils::dateIsBlank($person['base']['validTo'] ?? NULL) && $person['base']['validTo'] < $now)
			{
				$insert['valid'] = FALSE;
			}

			$this->db()->query('INSERT INTO [services_persons_persons]', $insert);
			$personNdx = $this->db()->getInsertId ();

			// -- insert id
			$insertId = [
				'person' => $personNdx,
				'idType' => 2,
				'id' => $person['base']['oid']
			];
			$this->db()->query('INSERT INTO [services_persons_ids]', $insertId);
		}

		if (isset($person['address']))
		{
			$person['address']['addressId'] = 'P'.$person['base']['oid'];
			$this->saveAddress($personNdx, $person['address']);
		}

		$insert = [
			'person' => $personNdx,
			'regType' => $regType,
			'subId' => $person['base']['oid'],
			'srcData' => $srcData,
			'timeUpdated' => new \DateTime(),
			'srcDataCheckSum' => sha1($srcData),
			'imported' => 1,
			'importedCheckSum' => sha1($srcData),
		];
		$this->db()->query ('INSERT INTO [services_persons_regsData]', $insert);

		$this->db()->commit();

		return $personNdx;
	}

	function checkName ($name)
	{
		$s = str_replace('"', '', $name);
		$s = str_replace("'", '', $s);
		$s = preg_replace("/ {4,}/", " ", $s);

		if (str_starts_with($s, ',,'))
			$s = substr($s, 2);
		if (str_ends_with($s, ",,"))
			$s = substr($s, 0, -2);
		if (str_ends_with($s, "´´"))
			$s = Str::substr($s, 0, -2);
		$s = trim ($s);

		// -- check words with spaces
		$newString = '';

		$wp = mb_str_split($s, 1, 'UTF-8');
		$pos = 0;
		$len = count($wp);
		$disableSpaceCheck = 0;
		while ($pos < $len)
		{
			if (isset($wp[5]) && $wp[5] === ' ')
			{
				if (!$disableSpaceCheck && isset($wp[$pos + 3]) && $wp[$pos + 1] === ' ' && $wp[$pos + 3] === ' ')
				{
					$newString .= $wp[$pos];
					$newString .= $wp[$pos + 2];
					if ($wp[$pos + 2] === ',')
						$newString .= ' ';
					$pos += 4;
					continue;
				}
			}

			$disableSpaceCheck = 1;
			$newString .= $wp[$pos];
			$pos++;
		}
		$s = str_replace("-", '', $s);
		$s = preg_replace("/ {2,}/", " ", $newString);
		$s = trim($s);

		return $s;
	}

	function saveAddress($personNdx, $address)
	{
		$qe = [];
		array_push($qe, 'SELECT * FROM [services_persons_address]');
		array_push($qe, ' WHERE [person] = %i', $personNdx);
		array_push($qe, ' AND [type] = %i', $address['type']);

		$exist = $this->db()->query($qe)->fetch();
		$now = new \DateTime();
		if ($exist)
		{

		}
		else
		{
			$insert = $address;
			$insert['person'] = $personNdx;
			//echo json_encode($insert)."\n";

			$this->db()->query('INSERT INTO [services_persons_address]', $insert);
		}
	}

	public function initialImport()
	{
		$this->initialImportARES();
		$this->initialImportRES();
	}

	public function dailyImport($fileName)
	{
		$idsStr = file_get_contents($fileName);
		if (!$idsStr)
			return;

		$ids = preg_split("/\\r\\n|\\r|\\n/", $idsStr);
		$cntRefreshed = 0;

		foreach ($ids as $id)
		{
			if (trim($id) === '')
				continue;

			$personNdx = 0;
			$count = 0;

			$exist = $this->db()->query('SELECT * FROM [services_persons_persons] WHERE [country] = %i', 60, ' AND [oid] = %s', $id);
			foreach ($exist as $e)
			{
				//echo $e['fullName']."; ";
				$personNdx = $e['ndx'];
				$count++;
			}

			if ($count === 0)
			{
				echo $id.'; NEW';
				$downloadUrl = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/darv_vreo.cgi?ico='.$id;
				$xmlString = @file_get_contents ($downloadUrl);
				if (!$xmlString)
				{
					echo "DOWNLOAD ERROR!!!\n";
					continue;
				}

				//echo $xmlString."\n-------\n";

				$newPersonNdx = $this->importOnePersonARES($xmlString, $id);
				if ($newPersonNdx)
				{
					//echo "NEW PERSON NDX: `$newPersonNdx`\n";
					$e = new \services\persons\libs\PersonData($this->app());
					$e->refreshImport(intval($newPersonNdx));
				}

				echo "\n";
			}
			elseif ($count === 1)
			{
				echo $id.'; UPDATE';
				$e = new \services\persons\libs\PersonData($this->app());
				$e->refreshImport(intval($personNdx));

				$cntRefreshed++;

				//if ($cntRefreshed > 10)
				//	break;

				sleep(1);

				echo "\n";
			}
			else
			{
				echo $id.'; MULTI!!!';
				echo "\n";
			}
		}
	}
}

