<?php

namespace swdev\world\libs;

use e10\Utility, e10\json;


/**
 * Class CfgGenerator
 * @package swdev\world\libs
 */
class CfgGenerator extends Utility
{
	var $data = [];
	var $texts = [];

	public function generateCountries()
	{
		$this->data['countries'] = [];

		$q [] = 'SELECT countries.*, tr.nameCommon AS trNameCommon, tr.nameOfficial AS trNameOfficial ';
		array_push ($q, ' FROM [swdev_world_countries] AS countries');
		array_push ($q, ' LEFT JOIN swdev_world_countriesTr AS tr ON countries.ndx = tr.country AND tr.language = 102');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' ORDER BY [countries].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$id = strtolower($r['cca2']);
			$ndx = $r['ndx'];

			$this->data['countries'][$ndx] = ['i' => $id, 'f' => $r['flag'], 't' => $r['trNameCommon']];
			$this->data['countryIds'][$id] = $ndx;
		}


		// -- text data
		$tj = '';
		$tj .= "{\n";
		$tj .= "\t\"countries\": {\n";

		$index = 0;
		foreach ($this->data['countries'] as $countryNdx => $country)
		{
			if ($index)
				$tj .= ",\n";
			$tj .= "\t\t\"$countryNdx\": ";
			$tj .= json_encode ($country, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

			$index++;
		}

		$tj .= "\n\t},\n";


		// -- country ids
		$tj .= "\t\"countryIds\": {\n\t\t";
		$index = 0;
		$firstLetter = '';
		foreach (\e10\sortByOneKey($this->data['countries'], 'i', TRUE) as $countryNdx => $country)
		{
			$fl = $country['i'][0];
			if ($fl !== $firstLetter && $firstLetter !== '')
				$tj .= ",\n\t\t";
			else
				if ($index)
					$tj .= ', ';

			$tj .= "\"{$country['i']}\": ".$countryNdx;

			$firstLetter = $fl;
			$index++;
		}
		$tj .= "\n\t}\n";

		// -- close
		$tj .= "}\n\n";
		$this->texts['countries']['json'] = $tj;
	}

	public function generateCurrencies()
	{
		$this->data['currencies'] = [];

		$q [] = 'SELECT currencies.*, tr.name AS trName';
		array_push ($q, ' FROM [swdev_world_currencies] AS currencies');
		array_push ($q, ' LEFT JOIN swdev_world_currenciesTr AS tr ON currencies.ndx = tr.currency AND tr.language = 102');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' ORDER BY [currencies].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$id = strtolower($r['id']);
			$ndx = $r['ndx'];

			$this->data['currencies'][$ndx] = ['i' => $id, 't' => $r['trName']];
			$this->data['currencyIds'][$id] = $ndx;
		}


		// -- text data
		$tj = '';
		$tj .= "{\n";
		$tj .= "\t\"currencies\": {\n";

		$index = 0;
		foreach ($this->data['currencies'] as $currencyNdx => $currency)
		{
			if ($index)
				$tj .= ",\n";
			$tj .= "\t\t\"$currencyNdx\": ";
			$tj .= json_encode ($currency, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

			$index++;
		}

		$tj .= "\n\t},\n";


		// -- currency ids
		$tj .= "\t\"currencyIds\": {\n\t\t";
		$index = 0;
		$firstLetter = '';
		foreach (\e10\sortByOneKey($this->data['currencies'], 'i', TRUE) as $currencyNdx => $currency)
		{
			$fl = $currency['i'][0];
			if ($fl !== $firstLetter && $firstLetter !== '')
				$tj .= ",\n\t\t";
			else
				if ($index)
					$tj .= ', ';

			$tj .= "\"{$currency['i']}\": ".$currencyNdx;

			$firstLetter = $fl;
			$index++;
		}
		$tj .= "\n\t}\n";

		// -- close
		$tj .= "}\n\n";
		$this->texts['currencies']['json'] = $tj;
	}

	public function generateLanguages()
	{
		$this->data['languages'] = [];

		$q [] = 'SELECT languages.*, tr.name AS trName';
		array_push ($q, ' FROM [swdev_world_languages] AS languages');
		array_push ($q, ' LEFT JOIN swdev_world_languagesTr AS tr ON languages.ndx = tr.languageSrc AND tr.languageDst = 102');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND languages.docState = %i', 4000);
		array_push ($q, ' ORDER BY [languages].ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if (!$r['trName'])
				continue;

			$id = $r['alpha2'];
			if ($id === '')
				continue;
			$ndx = $r['ndx'];

			$this->data['languages'][$ndx] = ['i' => $id, 't' => $r['trName']];
			$this->data['languageIds'][$id] = $ndx;
		}

		// -- text data
		$tj = '';
		$tj .= "{\n";
		$tj .= "\t\"languages\": {\n";

		$index = 0;
		foreach ($this->data['languages'] as $languageNdx => $language)
		{
			if ($index)
				$tj .= ",\n";
			$tj .= "\t\t\"$languageNdx\": ";
			$tj .= json_encode ($language, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

			$index++;
		}

		$tj .= "\n\t},\n";


		// -- language ids
		$tj .= "\t\"languageIds\": {\n\t\t";
		$index = 0;
		$firstLetter = '';
		foreach (\e10\sortByOneKey($this->data['languages'], 'i', TRUE) as $languageNdx => $language)
		{
			$fl = $language['i'][0];
			if ($fl !== $firstLetter && $firstLetter !== '')
				$tj .= ",\n\t\t";
			else
				if ($index)
					$tj .= ', ';

			$tj .= "\"{$language['i']}\": ".$languageNdx;

			$firstLetter = $fl;
			$index++;
		}
		$tj .= "\n\t}\n";

		// -- close
		$tj .= "}\n\n";
		$this->texts['languages']['json'] = $tj;
	}

	public function run()
	{
		$this->generateCountries();
		$this->generateCurrencies();
		$this->generateLanguages();
	}
}

