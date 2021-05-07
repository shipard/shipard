<?php


namespace swdev\world;

use e10\E10ApiObject, \e10\str;



/**
 * Class UploaderDataModel
 * @package swdev\dm
 */
class UploaderWorld extends E10ApiObject
{
	/** @var \swdev\world\TableLanguages */
	var $tableLanguages;
	/** @var \swdev\world\TableCurrencies */
	var $tableCurrencies;
	/** @var \swdev\world\TableCountries */
	var $tableCountries;
	/** @var \swdev\world\TableCountriesTr */
	var $tableCountriesTr;
	/** @var \swdev\world\TableCurrenciesTr */
	var $tableCurrenciesTr;
	/** @var \swdev\world\TableLanguagesTr */
	var $tableLanguagesTr;
	/** @var \swdev\world\TableTerritories */
	var $tableTerritories;

	var $uploadedNdx = 0;

	var $fallbackCurrencies;

	public function init ()
	{
		$this->tableLanguages = $this->app()->table('swdev.world.languages');
		$this->tableLanguagesTr = $this->app()->table('swdev.world.languagesTr');
		$this->tableCurrencies = $this->app()->table('swdev.world.currencies');
		$this->tableCurrenciesTr = $this->app()->table('swdev.world.currenciesTr');
		$this->tableCountries = $this->app()->table('swdev.world.countries');
		$this->tableCountriesTr = $this->app()->table('swdev.world.countriesTr');
		$this->tableTerritories = $this->app()->table('swdev.world.territories');

		$this->fallbackCurrencies = [
		'AFN' => 'Afghan Afghani',
		'AFA' => 'Afghan Afghani (1927–2002)',
		'ALL' => 'Albanian Lek',
		'ALK' => 'Albanian Lek (1946–1965)',
		'DZD' => 'Algerian Dinar',
		'ADP' => 'Andorran Peseta',
		'AOA' => 'Angolan Kwanza',
		'AOK' => 'Angolan Kwanza (1977–1991)',
		'AON' => 'Angolan New Kwanza (1990–2000)',
		'AOR' => 'Angolan Readjusted Kwanza (1995–1999)',
		'ARA' => 'Argentine Austral',
		'ARS' => 'Argentine Peso',
		'ARM' => 'Argentine Peso (1881–1970)',
		'ARP' => 'Argentine Peso (1983–1985)',
		'ARL' => 'Argentine Peso Ley (1970–1983)',
		'AMD' => 'Armenian Dram',
		'AWG' => 'Aruban Florin',
		'AUD' => 'Australian Dollar',
		'ATS' => 'Austrian Schilling',
		'AZN' => 'Azerbaijani Manat',
		'AZM' => 'Azerbaijani Manat (1993–2006)',
		'BSD' => 'Bahamian Dollar',
		'BHD' => 'Bahraini Dinar',
		'BDT' => 'Bangladeshi Taka',
		'BBD' => 'Barbadian Dollar',
		'BYN' => 'Belarusian Ruble',
		'BYB' => 'Belarusian Ruble (1994–1999)',
		'BYR' => 'Belarusian Ruble (2000–2016)',
		'BEF' => 'Belgian Franc',
		'BEC' => 'Belgian Franc (convertible)',
		'BEL' => 'Belgian Franc (financial)',
		'BZD' => 'Belize Dollar',
		'BMD' => 'Bermudan Dollar',
		'BTN' => 'Bhutanese Ngultrum',
		'BOB' => 'Bolivian Boliviano',
		'BOL' => 'Bolivian Boliviano (1863–1963)',
		'BOV' => 'Bolivian Mvdol',
		'BOP' => 'Bolivian Peso',
		'BAM' => 'Bosnia-Herzegovina Convertible Mark',
		'BAD' => 'Bosnia-Herzegovina Dinar (1992–1994)',
		'BAN' => 'Bosnia-Herzegovina New Dinar (1994–1997)',
		'BWP' => 'Botswanan Pula',
		'BRC' => 'Brazilian Cruzado (1986–1989)',
		'BRZ' => 'Brazilian Cruzeiro (1942–1967)',
		'BRE' => 'Brazilian Cruzeiro (1990–1993)',
		'BRR' => 'Brazilian Cruzeiro (1993–1994)',
		'BRN' => 'Brazilian New Cruzado (1989–1990)',
		'BRB' => 'Brazilian New Cruzeiro (1967–1986)',
		'BRL' => 'Brazilian Real',
		'GBP' => 'British Pound',
		'BND' => 'Brunei Dollar',
		'BGL' => 'Bulgarian Hard Lev',
		'BGN' => 'Bulgarian Lev',
		'BGO' => 'Bulgarian Lev (1879–1952)',
		'BGM' => 'Bulgarian Socialist Lev',
		'BUK' => 'Burmese Kyat',
		'BIF' => 'Burundian Franc',
		'XPF' => 'CFP Franc',
		'KHR' => 'Cambodian Riel',
		'CAD' => 'Canadian Dollar',
		'CVE' => 'Cape Verdean Escudo',
		'KYD' => 'Cayman Islands Dollar',
		'XAF' => 'Central African CFA Franc',
		'CLE' => 'Chilean Escudo',
		'CLP' => 'Chilean Peso',
		'CLF' => 'Chilean Unit of Account (UF)',
		'CNX' => 'Chinese People’s Bank Dollar',
		'CNY' => 'Chinese Yuan',
		'COP' => 'Colombian Peso',
		'COU' => 'Colombian Real Value Unit',
		'KMF' => 'Comorian Franc',
		'CDF' => 'Congolese Franc',
		'CRC' => 'Costa Rican Colón',
		'HRD' => 'Croatian Dinar',
		'HRK' => 'Croatian Kuna',
		'CUC' => 'Cuban Convertible Peso',
		'CUP' => 'Cuban Peso',
		'CYP' => 'Cypriot Pound',
		'CZK' => 'Czech Koruna',
		'CSK' => 'Czechoslovak Hard Koruna',
		'DKK' => 'Danish Krone',
		'DJF' => 'Djiboutian Franc',
		'DOP' => 'Dominican Peso',
		'NLG' => 'Dutch Guilder',
		'XCD' => 'East Caribbean Dollar',
		'DDM' => 'East German Mark',
		'ECS' => 'Ecuadorian Sucre',
		'ECV' => 'Ecuadorian Unit of Constant Value',
		'EGP' => 'Egyptian Pound',
		'GQE' => 'Equatorial Guinean Ekwele',
		'ERN' => 'Eritrean Nakfa',
		'EEK' => 'Estonian Kroon',
		'ETB' => 'Ethiopian Birr',
		'EUR' => 'Euro',
		'XEU' => 'European Currency Unit',
		'FKP' => 'Falkland Islands Pound',
		'FJD' => 'Fijian Dollar',
		'FIM' => 'Finnish Markka',
		'FRF' => 'French Franc',
		'XFO' => 'French Gold Franc',
		'XFU' => 'French UIC-Franc',
		'GMD' => 'Gambian Dalasi',
		'GEK' => 'Georgian Kupon Larit',
		'GEL' => 'Georgian Lari',
		'DEM' => 'German Mark',
		'GHS' => 'Ghanaian Cedi',
		'GHC' => 'Ghanaian Cedi (1979–2007)',
		'GIP' => 'Gibraltar Pound',
		'GRD' => 'Greek Drachma',
		'GTQ' => 'Guatemalan Quetzal',
		'GWP' => 'Guinea-Bissau Peso',
		'GNF' => 'Guinean Franc',
		'GNS' => 'Guinean Syli',
		'GYD' => 'Guyanaese Dollar',
		'HTG' => 'Haitian Gourde',
		'HNL' => 'Honduran Lempira',
		'HKD' => 'Hong Kong Dollar',
		'HUF' => 'Hungarian Forint',
		'ISK' => 'Icelandic Króna',
		'ISJ' => 'Icelandic Króna (1918–1981)',
		'INR' => 'Indian Rupee',
		'IDR' => 'Indonesian Rupiah',
		'IRR' => 'Iranian Rial',
		'IQD' => 'Iraqi Dinar',
		'IEP' => 'Irish Pound',
		'ILS' => 'Israeli New Shekel',
		'ILP' => 'Israeli Pound',
		'ILR' => 'Israeli Shekel (1980–1985)',
		'ITL' => 'Italian Lira',
		'JMD' => 'Jamaican Dollar',
		'JPY' => 'Japanese Yen',
		'JOD' => 'Jordanian Dinar',
		'KZT' => 'Kazakhstani Tenge',
		'KES' => 'Kenyan Shilling',
		'KWD' => 'Kuwaiti Dinar',
		'KGS' => 'Kyrgystani Som',
		'LAK' => 'Laotian Kip',
		'LVL' => 'Latvian Lats',
		'LVR' => 'Latvian Ruble',
		'LBP' => 'Lebanese Pound',
		'LSL' => 'Lesotho Loti',
		'LRD' => 'Liberian Dollar',
		'LYD' => 'Libyan Dinar',
		'LTL' => 'Lithuanian Litas',
		'LTT' => 'Lithuanian Talonas',
		'LUL' => 'Luxembourg Financial Franc',
		'LUC' => 'Luxembourgian Convertible Franc',
		'LUF' => 'Luxembourgian Franc',
		'MOP' => 'Macanese Pataca',
		'MKD' => 'Macedonian Denar',
		'MKN' => 'Macedonian Denar (1992–1993)',
		'MGA' => 'Malagasy Ariary',
		'MGF' => 'Malagasy Franc',
		'MWK' => 'Malawian Kwacha',
		'MYR' => 'Malaysian Ringgit',
		'MVR' => 'Maldivian Rufiyaa',
		'MVP' => 'Maldivian Rupee (1947–1981)',
		'MLF' => 'Malian Franc',
		'MTL' => 'Maltese Lira',
		'MTP' => 'Maltese Pound',
		'MRO' => 'Mauritanian Ouguiya',
		'MUR' => 'Mauritian Rupee',
		'MXV' => 'Mexican Investment Unit',
		'MXN' => 'Mexican Peso',
		'MXP' => 'Mexican Silver Peso (1861–1992)',
		'MDC' => 'Moldovan Cupon',
		'MDL' => 'Moldovan Leu',
		'MCF' => 'Monegasque Franc',
		'MNT' => 'Mongolian Tugrik',
		'MAD' => 'Moroccan Dirham',
		'MAF' => 'Moroccan Franc',
		'MZE' => 'Mozambican Escudo',
		'MZN' => 'Mozambican Metical',
		'MZM' => 'Mozambican Metical (1980–2006)',
		'MMK' => 'Myanmar Kyat',
		'NAD' => 'Namibian Dollar',
		'NPR' => 'Nepalese Rupee',
		'ANG' => 'Netherlands Antillean Guilder',
		'TWD' => 'New Taiwan Dollar',
		'NZD' => 'New Zealand Dollar',
		'NIO' => 'Nicaraguan Córdoba',
		'NIC' => 'Nicaraguan Córdoba (1988–1991)',
		'NGN' => 'Nigerian Naira',
		'KPW' => 'North Korean Won',
		'NOK' => 'Norwegian Krone',
		'OMR' => 'Omani Rial',
		'PKR' => 'Pakistani Rupee',
		'PAB' => 'Panamanian Balboa',
		'PGK' => 'Papua New Guinean Kina',
		'PYG' => 'Paraguayan Guarani',
		'PEI' => 'Peruvian Inti',
		'PEN' => 'Peruvian Sol',
		'PES' => 'Peruvian Sol (1863–1965)',
		'PHP' => 'Philippine Peso',
		'PLN' => 'Polish Zloty',
		'PLZ' => 'Polish Zloty (1950–1995)',
		'PTE' => 'Portuguese Escudo',
		'GWE' => 'Portuguese Guinea Escudo',
		'QAR' => 'Qatari Rial',
		'XRE' => 'RINET Funds',
		'RHD' => 'Rhodesian Dollar',
		'RON' => 'Romanian Leu',
		'ROL' => 'Romanian Leu (1952–2006)',
		'RUB' => 'Russian Ruble',
		'RUR' => 'Russian Ruble (1991–1998)',
		'RWF' => 'Rwandan Franc',
		'SVC' => 'Salvadoran Colón',
		'WST' => 'Samoan Tala',
		'SAR' => 'Saudi Riyal',
		'RSD' => 'Serbian Dinar',
		'CSD' => 'Serbian Dinar (2002–2006)',
		'SCR' => 'Seychellois Rupee',
		'SLL' => 'Sierra Leonean Leone',
		'SGD' => 'Singapore Dollar',
		'SKK' => 'Slovak Koruna',
		'SIT' => 'Slovenian Tolar',
		'SBD' => 'Solomon Islands Dollar',
		'SOS' => 'Somali Shilling',
		'ZAR' => 'South African Rand',
		'ZAL' => 'South African Rand (financial)',
		'KRH' => 'South Korean Hwan (1953–1962)',
		'KRW' => 'South Korean Won',
		'KRO' => 'South Korean Won (1945–1953)',
		'SSP' => 'South Sudanese Pound',
		'SUR' => 'Soviet Rouble',
		'ESP' => 'Spanish Peseta',
		'ESA' => 'Spanish Peseta (A account)',
		'ESB' => 'Spanish Peseta (convertible account)',
		'LKR' => 'Sri Lankan Rupee',
		'SHP' => 'St. Helena Pound',
		'SDD' => 'Sudanese Dinar (1992–2007)',
		'SDG' => 'Sudanese Pound',
		'SDP' => 'Sudanese Pound (1957–1998)',
		'SRD' => 'Surinamese Dollar',
		'SRG' => 'Surinamese Guilder',
		'SZL' => 'Swazi Lilangeni',
		'SEK' => 'Swedish Krona',
		'CHF' => 'Swiss Franc',
		'SYP' => 'Syrian Pound',
		'STD' => 'São Tomé & Príncipe Dobra',
		'TJR' => 'Tajikistani Ruble',
		'TJS' => 'Tajikistani Somoni',
		'TZS' => 'Tanzanian Shilling',
		'THB' => 'Thai Baht',
		'TPE' => 'Timorese Escudo',
		'TOP' => 'Tongan Paʻanga',
		'TTD' => 'Trinidad & Tobago Dollar',
		'TND' => 'Tunisian Dinar',
		'TRY' => 'Turkish Lira',
		'TRL' => 'Turkish Lira (1922–2005)',
		'TMT' => 'Turkmenistani Manat',
		'TMM' => 'Turkmenistani Manat (1993–2009)',
		'USD' => 'US Dollar',
		'USN' => 'US Dollar (Next day)',
		'USS' => 'US Dollar (Same day)',
		'UGX' => 'Ugandan Shilling',
		'UGS' => 'Ugandan Shilling (1966–1987)',
		'UAH' => 'Ukrainian Hryvnia',
		'UAK' => 'Ukrainian Karbovanets',
		'AED' => 'United Arab Emirates Dirham',
		'UYU' => 'Uruguayan Peso',
		'UYP' => 'Uruguayan Peso (1975–1993)',
		'UYI' => 'Uruguayan Peso (Indexed Units)',
		'UZS' => 'Uzbekistani Som',
		'VUV' => 'Vanuatu Vatu',
		'VEF' => 'Venezuelan Bolívar',
		'VEB' => 'Venezuelan Bolívar (1871–2008)',
		'VND' => 'Vietnamese Dong',
		'VNN' => 'Vietnamese Dong (1978–1985)',
		'CHE' => 'WIR Euro',
		'CHW' => 'WIR Franc',
		'XOF' => 'West African CFA Franc',
		'YDD' => 'Yemeni Dinar',
		'YER' => 'Yemeni Rial',
		'YUN' => 'Yugoslavian Convertible Dinar (1990–1992)',
		'YUD' => 'Yugoslavian Hard Dinar (1966–1990)',
		'YUM' => 'Yugoslavian New Dinar (1994–2002)',
		'YUR' => 'Yugoslavian Reformed Dinar (1992–1993)',
		'ZRN' => 'Zairean New Zaire (1993–1998)',
		'ZRZ' => 'Zairean Zaire (1971–1993)',
		'ZMW' => 'Zambian Kwacha',
		'ZMK' => 'Zambian Kwacha (1968–2012)',
		'ZWD' => 'Zimbabwean Dollar (1980–2008)',
		'ZWR' => 'Zimbabwean Dollar (2008)',
		'ZWL' => 'Zimbabwean Dollar (2009)',
	];

	}

	function checkUpdateField ($srcKey, $srcData, $dstKey, $dstData, &$updateFields)
	{
		if (isset($srcData[$srcKey]) && isset($dstData[$dstKey]) && $srcData[$srcKey] == $dstData[$dstKey])
			return;

		$updateFields[$dstKey] = $srcData[$dstKey];
	}

	function checkTerritoryExist($countryNdx, $rowOrder, $name)
	{
		if (!$name || $name == '')
			return;

		$id = str::tolower(str_replace(' ', '-', $name));
		$exist = $this->db()->query('SELECT * FROM [swdev_world_territories] WHERE [id] = %s', $id)->fetch();
		if (!$exist)
		{
			$item = ['id' => $id, 'name' => $name, 'docState' => 4000, 'docStateMain' => 2];
			$ndx = $this->tableTerritories->dbInsertRec($item);
			$this->tableTerritories->docsLog($ndx);
		}
		else
		{
			$ndx = $exist['ndx'];
		}

		// -- check country territories list
		$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_countryTerritories]',
			' WHERE [country] = %i', $countryNdx, ' AND [territory] = %i', $ndx)->fetch();
		if (!$existedRow)
		{
			$newRow = ['country' => $countryNdx, 'territory' => $ndx, 'rowOrder' => $rowOrder];
			$this->db()->query('INSERT INTO [swdev_world_countryTerritories] ', $newRow);
		}
	}

	function upload()
	{
		if ($this->requestParams['type'] === 'language')
			$this->uploadLanguage();
		elseif ($this->requestParams['type'] === 'currency')
			$this->uploadCurrency();
		elseif ($this->requestParams['type'] === 'countryMledoze')
			$this->uploadCountryMledoze();
		elseif ($this->requestParams['type'] === 'translateCurrencies')
			$this->translateCurrencies();
		elseif ($this->requestParams['type'] === 'translateLanguages')
			$this->translateLanguages();

		return TRUE;
	}

	function uploadLanguage()
	{
		$data = $this->requestParams['data'];
		$itemId = $this->requestParams['data']['alpha3b'];

		if (!$itemId)
			return;

		$exist = $this->db()->query('SELECT * FROM [swdev_world_languages] WHERE [id] = %s', $itemId)->fetch();
		if (!$exist)
		{
			$item = [
				'id' => $data['alpha3b'],
				'alpha2' => $data['alpha2'],
				'alpha3b' => $data['alpha3b'],
				'alpha3t' => $data['alpha3t'],
				'name' => $data['name'],
				'docState' => 4000, 'docStateMain' => 2];
			$ndx = $this->tableLanguages->dbInsertRec($item);
			$this->tableLanguages->docsLog($ndx);
			$this->uploadedNdx = $ndx;

			return;
		}

		$r = $exist->toArray();
		$item = [];
		$this->checkUpdateField ('alpha2', $data, 'alpha2', $r, $item);
		$this->checkUpdateField ('alpha3b', $data, 'alpha3b', $r, $item);
		$this->checkUpdateField ('alpha3t', $data, 'alpha3t', $r, $item);
		$this->checkUpdateField ('name', $data, 'name', $r, $item);

		if (count($item))
		{
			$item['ndx'] = $r['ndx'];
			$ndx = $this->tableLanguages->dbUpdateRec($item);
			$this->tableLanguages->docsLog($ndx);
		}
	}

	function uploadCurrency()
	{
		$data = $this->requestParams['data'];
		$itemId = $this->requestParams['data']['id'];

		$exist = $this->db()->query('SELECT * FROM [swdev_world_currencies] WHERE [id] = %s', $itemId)->fetch();
		if (!$exist)
		{
			$item = [
				'id' => $data['id'],
				'name' => $data['name'], 'namePlural' => $data['namePlural'],
				'symbol' => $data['symbol'], 'symbolNative' => $data['symbolNative'],
				'decimals' => $data['decimals'],
				'docState' => 4000, 'docStateMain' => 2,
			];
			$ndx = $this->tableCurrencies->dbInsertRec($item);
			$this->tableCurrencies->docsLog($ndx);
			$this->uploadedNdx = $ndx;

			return;
		}

		$r = $exist->toArray();
		$item = [];
		$this->checkUpdateField ('id', $data, 'id', $r, $item);
		$this->checkUpdateField ('name', $data, 'name', $r, $item);
		$this->checkUpdateField ('namePlural', $data, 'namePlural', $r, $item);
		$this->checkUpdateField ('symbol', $data, 'symbol', $r, $item);
		$this->checkUpdateField ('symbolNative', $data, 'symbolNative', $r, $item);
		$this->checkUpdateField ('decimals', $data, 'decimals', $r, $item);

		if (count($item))
		{
			$item['ndx'] = $r['ndx'];
			$ndx = $this->tableCurrencies->dbUpdateRec($item);
			$this->tableCurrencies->docsLog($ndx);
		}
	}

	function uploadCountryMledoze()
	{
		$data = $this->requestParams['data'];
		$data['id'] = strtolower($data['cca3']);
		$data['nameCommon'] = $data['name']['common'];
		$data['nameOfficial'] = $data['name']['official'];
		$data['tlds'] = implode(' ', $data['tld']);
		$data['callingCodes'] = implode(' ', $data['callingCode']);
		$itemId = $data['id'];

		$exist = $this->db()->query('SELECT * FROM [swdev_world_countries] WHERE [id] = %s', $itemId)->fetch();
		if (!$exist)
		{
			$item = [
				'id' => $data['id'],
				'cca2' => $data['cca2'], 'cca3' => $data['cca3'], 'ccn3' => $data['ccn3'],
				'nameCommon' => $data['nameCommon'], 'nameOfficial' => $data['nameOfficial'],
				'flag' => $data['flag'], 'tlds' => $data['tlds'], 'callingCodes' => $data['callingCodes'],
				'independent' => intval($data['independent']),
				'docState' => 4000, 'docStateMain' => 2,
			];
			$ndx = $this->tableCountries->dbInsertRec($item);
			$this->tableCountries->docsLog($ndx);
			$this->uploadedNdx = $ndx;
		}
		else
		{
			$r = $exist->toArray();
			$ndx = $exist['ndx'];
			$item = [];
			$this->checkUpdateField('id', $data, 'id', $r, $item);
			$this->checkUpdateField('cca2', $data, 'cca2', $r, $item);
			$this->checkUpdateField('cca3', $data, 'cca3', $r, $item);
			$this->checkUpdateField('ccn3', $data, 'ccn3', $r, $item);
			$this->checkUpdateField('nameCommon', $data, 'nameCommon', $r, $item);
			$this->checkUpdateField('nameOfficial', $data, 'nameOfficial', $r, $item);
			$this->checkUpdateField ('flag', $data, 'flag', $r, $item);
			$this->checkUpdateField ('tlds', $data, 'tlds', $r, $item);
			$this->checkUpdateField ('callingCodes', $data, 'callingCodes', $r, $item);
			$this->checkUpdateField ('independent', $data, 'independent', $r, $item);

			if (count($item))
			{
				$item['ndx'] = $r['ndx'];
				$ndx = $this->tableCountries->dbUpdateRec($item);
				$this->tableCountries->docsLog($ndx);
			}
		}

		// -- currencies
		$rowOrder = 1000;
		foreach ($data['currency'] as $currencyId)
		{
			$existedCurrency = $this->db()->query ('SELECT * FROM [swdev_world_currencies] WHERE [id] = %s', $currencyId)->fetch();
			if (!$existedCurrency)
			{
				if (isset($this->fallbackCurrencies[$currencyId]))
				{
					$item = [
						'id' => $currencyId, 'name' => $this->fallbackCurrencies[$currencyId],
						'symbol' => $currencyId, 'decimals' => 2, 'docState' => 1000, 'docStateMain' => 0,
					];
					$ndx = $this->tableCurrencies->dbInsertRec($item);
					$this->tableCurrencies->docsLog($ndx);
					$existedCurrency = $this->db()->query ('SELECT * FROM [swdev_world_currencies] WHERE [id] = %s', $currencyId)->fetch();
				}
				else
					continue;
			}

			$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_countryCurrencies]',
				' WHERE [country] = %i', $ndx, ' AND [currency] = %i', $existedCurrency['ndx'])->fetch();
			if ($existedRow)
				continue;

			$newRow = ['country' => $ndx, 'currency' => $existedCurrency['ndx'], 'rowOrder' => $rowOrder];
			$this->db()->query ('INSERT INTO [swdev_world_countryCurrencies] ', $newRow);
			$rowOrder += 1000;
		}

		// -- languages
		$rowOrder = 1000;
		foreach ($data['languages'] as $languageId => $languageName)
		{
			$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [id] = %s', $languageId)->fetch();
			if (!$existedLanguage)
				$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [alpha3t] = %s', $languageId)->fetch();
			if (!$existedLanguage)
			{
				$item = ['id' => $languageId, 'name' => $languageName, 'docState' => 1000, 'docStateMain' => 0];
				$ndx = $this->tableLanguages->dbInsertRec($item);
				$this->tableLanguages->docsLog($ndx);
				$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [id] = %s', $languageId)->fetch();
				error_log ("----UNKNOWN LANG $languageId - $languageName");
			}

			$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_countryLanguages]',
				' WHERE [country] = %i', $ndx, ' AND [language] = %i', $existedLanguage['ndx'])->fetch();
			if ($existedRow)
				continue;

			$newRow = ['country' => $ndx, 'language' => $existedLanguage['ndx'], 'rowOrder' => $rowOrder];

			if (isset($data['name']['native'][$languageId]['official']))
				$newRow['nameOfficial'] = $data['name']['native'][$languageId]['official'];
			if (isset($data['name']['native'][$languageId]['common']))
				$newRow['nameCommon'] = $data['name']['native'][$languageId]['common'];

			$this->db()->query ('INSERT INTO [swdev_world_countryLanguages] ', $newRow);
			$rowOrder += 1000;
		}

		// -- translation
		foreach ($data['translations'] as $languageId => $tr)
		{
			$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [id] = %s', $languageId)->fetch();
			if (!$existedLanguage)
				$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [alpha3t] = %s', $languageId)->fetch();
			if (!$existedLanguage)
			{
				error_log ("----UNKNOWN TR LANG $languageId - $languageName");
				continue;
			}

			$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_countriesTr]',
				' WHERE [country] = %i', $ndx, ' AND [language] = %i', $existedLanguage['ndx'])->fetch();

			if (!$existedRow)
			{
				$newRow = ['country' => $ndx, 'language' => $existedLanguage['ndx'], 'docState' => 4000, 'docStateMain' => 2];
				if (isset($tr['official']))
					$newRow['nameOfficial'] = $tr['official'];
				if (isset($tr['common']))
					$newRow['nameCommon'] = $tr['common'];

				$trNdx = $this->tableCountriesTr->dbInsertRec($newRow);
				$this->tableCountriesTr->docsLog($trNdx);
			}
			else
			{
				$updateRow = [];
				if (isset($tr['official']))
					$updateRow['nameOfficial'] = $tr['official'];
				if (isset($tr['common']))
					$updateRow['nameCommon'] = $tr['common'];
				if (count($updateRow))
					$this->db()->query ('UPDATE [swdev_world_countriesTr] SET ',$updateRow, ' WHERE [ndx] = %i', $existedRow['ndx']);
			}
		}

		// -- territories
		$this->checkTerritoryExist($ndx, 1000, $data['region']);
		$this->checkTerritoryExist($ndx, 2000, $data['subregion']);
	}

	function translateCurrencies()
	{
		$data = $this->requestParams['data'];
		$languageId = strtolower($this->requestParams['language']);
		$existedLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [alpha2] = %s', $languageId)->fetch();
		if (!$existedLanguage)
		{
			error_log ("----UNKNOWN TR CURR LANG $languageId");
			return;
		}

		foreach ($data as $currencyId => $currencyName)
		{
			$existedCurrency = $this->db()->query('SELECT * FROM [swdev_world_currencies] WHERE [id] = %s', $currencyId)->fetch();
			if (!$existedCurrency)
			{
				error_log ("----UNKNOWN TR CURR CURRENCY $currencyId");
				continue;
			}
			$ndx = $existedCurrency['ndx'];

			$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_currenciesTr]',
				' WHERE [currency] = %i', $ndx, ' AND [language] = %i', $existedLanguage['ndx'])->fetch();

			if (!$existedRow)
			{
				$newRow = [
					'currency' => $ndx, 'language' => $existedLanguage['ndx'],
					'name' => $currencyName, 'namePlural' => $currencyName,
					'docState' => 4000, 'docStateMain' => 2,
				];

				$trNdx = $this->tableCurrenciesTr->dbInsertRec($newRow);
				$this->tableCurrenciesTr->docsLog($trNdx);
			}
		}
	}

	function translateLanguages()
	{
		$data = $this->requestParams['data'];
		$dstLanguageId = strtolower($this->requestParams['language']);
		$existedDstLanguage = $this->db()->query ('SELECT * FROM [swdev_world_languages] WHERE [alpha2] = %s', $dstLanguageId)->fetch();
		if (!$existedDstLanguage)
		{
			error_log ("----UNKNOWN TR LANG LANG $dstLanguageId");
			return;
		}

		foreach ($data as $srcLanguageId => $languageName)
		{
			$existedSrcLanguage = $this->db()->query('SELECT * FROM [swdev_world_languages] WHERE [alpha2] = %s', $srcLanguageId)->fetch();
			if (!$existedSrcLanguage)
				$existedSrcLanguage = $this->db()->query('SELECT * FROM [swdev_world_languages] WHERE [alpha3b] = %s', $srcLanguageId)->fetch();
			if (!$existedSrcLanguage)
			{
				error_log ("----UNKNOWN TR LANG LANGUAGE $srcLanguageId");
				continue;
			}
			$ndx = $existedSrcLanguage['ndx'];

			$existedRow = $this->db()->query ('SELECT * FROM [swdev_world_languagesTr]',
				' WHERE [languageSrc] = %i', $ndx, ' AND [languageDst] = %i', $existedDstLanguage['ndx'])->fetch();

			if (!$existedRow)
			{
				$newRow = [
					'languageSrc' => $ndx, 'languageDst' => $existedDstLanguage['ndx'],
					'name' => $languageName,
					'docState' => 4000, 'docStateMain' => 2,
				];

				$trNdx = $this->tableLanguagesTr->dbInsertRec($newRow);
				$this->tableLanguagesTr->docsLog($trNdx);
			}
		}
	}

	public function createResponseContent($response)
	{
		$this->init();

		if ($this->requestParams['operation'] === 'upload')
		{
			if ($this->upload())
			{
				$response->add('success', 1);
				if ($this->uploadedNdx)
					$response->add('uploadedNdx', $this->uploadedNdx);

				return;
			}
		}

		//error_log ("----UPLOADER !{$this->requestParams['operation']}! !{$this->requestParams['type']}!");

		$response->add ('success', 1);
		//$response->add ('rowsHtmlCode', $this->code);
	}
}
