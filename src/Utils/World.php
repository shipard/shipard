<?php

namespace Shipard\Utils;


class World
{
	static function country($app, $countryNdx)
	{
		$c = $app->cfgItem('world.data.countries.'.$countryNdx, NULL);
		return $c;
	}

	static function countryNdx($app, $countryId)
	{
		$ci = $app->cfgItem('world.data.countryIds', NULL);
		$id = strtolower($countryId);

		if ($ci && isset($ci[$id]))
			return $ci[$id];

		return 0;
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

