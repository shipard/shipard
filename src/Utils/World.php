<?php

namespace Shipard\Utils;


class World
{
	static function country($app, $countryNdx)
	{
		$c = $app->cfgItem('world.data.countries.'.$countryNdx, NULL);
		return $c;
	}

	static function countryId($app, $countryNdx)
	{
		$c = $app->cfgItem('world.data.countries.'.$countryNdx, NULL);
		if ($c)
			return $c['i'];
		return '';
	}

	static function countryNdx($app, $countryId)
	{
		$id = strtolower($countryId);
		$ci = $app->cfgItem('world.data.countriesIds.'.$id, NULL);

		if ($ci)
			return $ci['n'];

		return 0;
	}

	static function setCountryInfo(\Shipard\Application\Application $app, int $countryNdx, array &$dst)
	{
		$c = $app->cfgItem('world.data.countries.'.$countryNdx, NULL);
		if (!$c)
			return;

		$dst['countryName'] = $c['t'];
		$dst['countryNameEng'] = $c['e'];
		$dst['countryNameSC2'] = $c['i'];

		if (isset($c['l'][0]))
		{ // primary language
			$l = self::language($app, $c['l'][0]);
			if ($l)
			{
				$dst['countryLangSC2'] = $l['i'];
			}
		}
	}

	static function currency($app, $currencyNdx)
	{
		$c = $app->cfgItem('world.data.currencies.'.$currencyNdx, NULL);
		return $c;
	}

	static function currencyNdx($app, $currencyId)
	{
		$ci = $app->cfgItem('world.data.currencyIds', NULL);
		$id = strtolower($currencyId);

		if ($ci && isset($ci[$id]))
			return $ci[$id];

		return 0;
	}

	static function language($app, $languageNdx)
	{
		$l = $app->cfgItem('world.data.languages.'.$languageNdx, NULL);
		return $l;
	}
}

