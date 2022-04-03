<?php

namespace services\persons\libs\cz;

use services\persons\libs\ImportPersons;
use \Shipard\Utils\Utils;


class InitialImportPersonsCZ extends ImportPersons
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
				//if ($cnt > 50000)
				//	break;
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
			return FALSE;

		if ($onePerson->person['base']['oid'] === '')
			$onePerson->person['base']['oid'] = $oid;

		$this->savePerson($onePerson->person, $xmlString, 1);

		return TRUE;
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

				//if ($cnt > 50000)
				//	break;
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
		$qe = [];
		array_push($qe, 'SELECT * FROM [services_persons_persons]');
		array_push($qe, ' WHERE [oid] = %s', $person['base']['oid']);
		array_push($qe, ' AND [country] = %i', $person['base']['country']);
		
		$exist = $this->db()->query($qe)->fetch();
		$now = new \DateTime();
		if ($exist)
		{
			$personNdx = $exist['ndx'];
		}
		else
		{
			$insert = $person['base'];
			$insert['fullName'] = $this->checkName($insert['originalName']);
			$insert['created'] = $now;
			$insert['updated'] = $now;
			$insert['valid'] = TRUE;
			if (!Utils::dateIsBlank($person['base']['validTo'] ?? NULL) && $person['base']['validTo'] < $now)
			{
				$insert['valid'] = FALSE;
			}

			$this->db()->query('INSERT INTO [services_persons_persons]', $insert);
			$personNdx = $this->db()->getInsertId ();
		}

		if (isset($person['address']))
			$this->saveAddress($personNdx, $person['address']);

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
	}

	function checkName ($name)
	{
		$s = str_replace('"', '', $name);
		$s = str_replace("'", '', $s);
		$s = trim ($s);

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
}

