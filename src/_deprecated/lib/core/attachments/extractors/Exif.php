<?php

namespace lib\core\attachments\extractors;
use \lib\core\attachments\extractors\Base, \e10\json, \Shipard\Utils\Gps;


/**
 * Class Exif
 * @package lib\core\attachments\extractors
 */
class Exif extends Base
{
	public function run()
	{
		$jsonFileName = $this->tmpFileName.'.json';
		$cmd = 'exiftool -json "'.$this->attFileName.'" > '.$jsonFileName;
		//echo "      -> ".$cmd."\n";
		system($cmd);
		$data = $this->loadCfgFile($jsonFileName);

		if ($data && is_array($data) && count($data))
		{
			// -- delete unneeded values
			foreach ($data as $exifPartNdx => $exifPartData)
			{
				unset (
					$data[$exifPartNdx]['SourceFile'], $data[$exifPartNdx]['FileName'], $data[$exifPartNdx]['Directory'],
					$data[$exifPartNdx]['FilePermissions'], $data[$exifPartNdx]['ThumbnailImage']
				);
			}

			// -- detect attachments values
			$locState = 2; // gps location is not available
			$values = [];
			foreach ($data as $exifPartNdx => $exifPartData)
			{
				// -- resolution
				if (isset($exifPartData['ExifImageWidth']))
					$values['i1'] = intval($exifPartData['ExifImageWidth']);
				if (isset($exifPartData['ExifImageHeight']))
					$values['i2'] = intval($exifPartData['ExifImageHeight']);

				// -- gps
				if (isset($exifPartData['GPSPosition']))
				{
					$coordinates = $this->parseGps($exifPartData['GPSPosition']);
					if ($coordinates)
					{
						$values['lat'] = $coordinates['lat'];
						$values['lon'] = $coordinates['lon'];
						$locState = 1; // gps location exist
					}
				}

				// -- create date time
				if (isset($exifPartData['DateTimeOriginal']))
				{
					$values['contentDate'] = new \DateTime($exifPartData['DateTimeOriginal']);
				}
				elseif (isset($exifPartData['CreateDate']))
				{
					$values['contentDate'] = new \DateTime($exifPartData['CreateDate']);
				}
			}

			$values['locState'] = $locState;

			if (count($values))
			{
				$this->applyUpdateValues($values);
			}

			$this->saveData(self::mdtExif, json::lint($data));
		}
	}

	function parseGps($coordinates)
	{
		$c = str_replace('deg', '°', $coordinates);
		$c = str_replace(' ', '', $c);

		$latLon = Gps::parse($coordinates);
		return $latLon;
	}
}

