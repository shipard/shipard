<?php

namespace Shipard\Utils;



/**
 * GPS Coordinates Parser
 *
 * Forms:
 * - 49.7175669N, 15.3728881E
 * - N 49°43.05402', E 15°22.37328'
 * - 49°43'3.241"N, 15°22'22.397"E
 * - N48.7382° E19.56845°
 * - N48°44.292' E19°34.107'
 * - 50.068651, 14.475237
 *
 * https://gist.github.com/f3l1x/732249b697fbc66faea1
 * @author Milan Felix Sulc <sulc@webtoad.cz>
 * @copyright WebToad s.r.o. <info@webtoad.cz>
 * 
 * https://gist.github.com/severak/d2a3ba96f2e3924e8fc3fbf9af32027c
 */
final class gps
{
	public static function parse($lonlat)
	{
		// Strip E,N
		$lonlat = str_replace(['E', 'N'], NULL, $lonlat);
		// Strip spaces and whitespaces
		$lonlat = str_replace([' ', '#\s+#'], '', $lonlat);
		// XX.XX, YY.YY
		if (preg_match('#^(\d+\.\d*)[,\s]*(\d+\.\d*)$#is', $lonlat, $matches)) {
			list (, $lat, $lng) = $matches;
			return [
				// Latitude
				'lat' => floatval($lat),
				// Longitude
				'lon' => floatval($lng),
			];
		}
		// XX°XX.X'[XX.XXXX"], YY°YY.Y'[YY.YYY"]
		if (preg_match_all('#^(.*)[,\s]+(.*)$#', $lonlat, $matches)) {
			list(, $lat, $lng) = $matches;
			return [
				// Latitude
				'lat' => self::toDec(array_shift($lat)),
				// Longitude
				'lon' => self::toDec(array_shift($lng)),
			];
		}
		return NULL;
	}

	private static function toDec($coordinate)
	{
		if (preg_match_all('#(\d+\.?\d*)#', $coordinate, $matches)) {
			$match = array_shift($matches);
			$degrees = floatval($match[0]);
			$minutes = floatval(isset($match[1]) ? $match[1] : 0);
			$seconds = floatval(isset($match[2]) ? $match[2] : 0);
			// Do math
			$degrees = $degrees + ($minutes / 60) + ($seconds / 60 / 60);
			// Round to 7 decimals
			$degrees = round($degrees, 7);
			return floatval($degrees);
		} else {
			return $coordinate;
		}
	}
}

