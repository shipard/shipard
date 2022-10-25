<?php

namespace e10doc\core\libs;
use \e10\utils;
use \Shipard\Utils\World;



class E10Utils
{
	static function datePeriodQuery ($column, &$q, $value)
	{
		if (isset ($value[$column]['from']) && $value[$column]['from'] != '')
			array_push ($q, " AND heads.[$column] >= %d", date_create_from_format ('d.m.Y', $value[$column]['from']));
		if (isset ($value[$column]['to']) && $value[$column]['to'] != '')
			array_push ($q, " AND heads.[$column] <= %d", date_create_from_format ('d.m.Y', $value[$column]['to']));
	}

	static function exchangeRate($app, $date, $srcCurrency, $dstCurrency)
	{
		$dstCurrencyNdx = World::currencyNdx($app, $dstCurrency);
		$srcCurrencyNdx = World::currencyNdx($app, $srcCurrency);

		$q [] = 'SELECT [values].*, [lists].listType AS listType, [lists].validFrom, [lists].validTo';
		array_push ($q, ' FROM [e10doc_base_exchangeRatesValues] AS [values]');
		array_push ($q, ' LEFT JOIN [e10doc_base_exchangeRatesLists] AS [lists] ON [values].[list] = [lists].ndx ');
		array_push ($q, ' WHERE 1');

		array_push ($q, ' AND [values].[currency] = %i', $dstCurrencyNdx);
		array_push ($q, ' AND [lists].[currency] = %i', $srcCurrencyNdx);
		array_push ($q, ' AND [lists].[rateType] = %i', 0);

		array_push ($q, ' AND [lists].[validFrom] <= %d', $date);

		array_push ($q, ' ORDER BY lists.validFrom DESC');
		array_push ($q, ' LIMIT 0, 1');

		$er = $app->db()->query($q)->fetch();
		if ($er)
			return $er['exchangeRateOneUnit'];

		return 0;
	}

	static function unitsConversionCoefficient($app, $srcUnit, $dstUnit)
	{
		if ($srcUnit === 'kg' && $dstUnit === 'g')
			return 1000;
		if ($srcUnit === 'g' && $dstUnit === 'kg')
			return 0.001;

		return 1;
	}

	static function getCashBoxInitState ($app, $cashBox, $date, $fiscalYear = 0)
	{
		if ($fiscalYear === 0)
		{
			$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalyears] WHERE start <= %d AND end >= %d ORDER BY [start] DESC", $date, $date)->fetch ();
			if ($period)
				$fiscalYear = $period['ndx'];
		}

		$initState = 0.0;

		$q1 [] = "SELECT dateAccounting, initBalance FROM e10doc_core_heads WHERE ";
		array_push ($q1, " docState = 4000 AND initState = 1 AND fiscalYear = %i", $fiscalYear);
		array_push ($q1, " AND docType = %s", 'cash');
		array_push ($q1, " AND dateAccounting <= %d", $date);
		array_push ($q1, " AND cashBox = %i", $cashBox);
		array_push ($q1, " ORDER BY dateAccounting DESC, ndx DESC LIMIT 1");

		$initStates = $app->db()->query($q1)->fetchAll();

		if ($initStates)
			$initState = $initStates[0]['initBalance'];
		else
		{
			unset ($q1);
			$q1 [] = 'SELECT dateAccounting, initBalance FROM e10doc_core_heads WHERE ';
			array_push ($q1, ' docState = 4000 AND initState = 1 AND fiscalYear = %i', $fiscalYear);
			array_push ($q1, ' AND docType = %s', 'cash');
			array_push ($q1, ' AND cashBox = %i', $cashBox);
			array_push ($q1, ' ORDER BY dateAccounting ASC, ndx DESC LIMIT 1');
			$initStates = $app->db()->query($q1)->fetchAll();
			if ($initStates)
				$initState = $initStates[0]['initBalance'];
		}

		$q2 [] = "SELECT SUM([totalCash]) as totalCash FROM e10doc_core_heads WHERE ";
		array_push ($q2, " fiscalYear = %i", $fiscalYear);
		array_push ($q2, " AND dateAccounting < %d", $date);
		array_push ($q2, " AND cashBox = %i", $cashBox);
		array_push ($q2, " AND docType IN ('invni', 'invno', 'cash', 'cashreg', 'purchase')");
		array_push ($q2, " AND totalCash != 0 AND docState = 4000");

		$row = $app->db()->query($q2)->fetch();
		if ($row)
			$initState += $row['totalCash'];

		return $initState;
	}

	static function amountQuery (&$q, $column, $amount, $diff)
	{
		if (is_string($amount) && $amount === '')
			return '';

		$a = floatval($amount);
		$d = floatval($diff);

		$paramValueName = '';

		if ($d == 0.0)
		{
			array_push ($q, " AND $column = %f", $a);
			$paramValueName = strval ($a);
		}
		else
		{
			array_push ($q, " AND ($column >= %f AND $column <= %f)", $a-$d, $a+$d);
			$paramValueName = strval ($a).' ±'.strval($d);
		}

		return $paramValueName;
	}

	static function fiscalYearEnum ($app)
	{
		$enum = [];

		$fiscalYears = \e10\sortByOneKey($app->cfgItem('e10doc.acc.periods'), 'begin', TRUE, FALSE);
		forEach ($fiscalYears as $fyNdx => $fy)
		{
			$enum[$fyNdx] = $fy['fullName'];
		}
		return $enum;
	}

	static function fiscalPeriods ($app, $periodWanted, &$periodResult)
	{
		// L-12-M / C-12-M
		$parts = explode ('-', $periodWanted);
		$type = $parts[0];
		$cnt = intval ($parts[1]);
		if ($type === 'L' || $type === 'C')
			$periodType = $parts[2];
		elseif ($periodWanted[0] === 'Y')
			$periodType = 'Y';
		else
			$periodType = 'P';

		$periodResult['accMethods'] = [];

		if ($type === 'L' || $type === 'C')
		{ // closed periods only
			$todayDate = utils::today();
			$startDate = clone $todayDate;
			$startDate->sub (new \DateInterval('P'.$cnt.$periodType));

			if ($type === 'C')
			{
				//$todayDate->sub(new \DateInterval('P1M'));
				$startDate->add(new \DateInterval('P1M'));
			}
			else
			{ // L
				$todayDate->sub (new \DateInterval('P2M'));
				$startDate->sub (new \DateInterval('P1M'));
			}
		}

		if ($periodType === 'P')
		{
			$periods = explode (',', $periodWanted);
			$fms = [];
			$months = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [fiscalType] = 0 AND [ndx] IN %in ORDER BY [globalOrder]", $periods);
			foreach ($months as $fm)
			{
				$fms[] = $fm['ndx'];
				$periodResult['periods'][$fm['ndx']] = ['title' => $fm['calendarYear'].'.'.$fm['calendarMonth']];
				if (!isset($periodResult['dateBegin']))
					$periodResult['dateBegin'] = $fm['start'];
				$periodResult['dateEnd'] = $fm['end'];
				$fiscalYearMethod = $app->cfgItem ('e10doc.acc.periods.'.$fm['fiscalYear'].'.method');
				if (!isset($periodResult['accMethods'][$fiscalYearMethod]))
					$periodResult['accMethods'][$fiscalYearMethod] = 1;
				else
					$periodResult['accMethods'][$fiscalYearMethod]++;
			}
			$periodResult['fiscalPeriod'] = /*'M'.*/implode(',', $fms);
		}
		else
		if ($periodType === 'Y')
		{
			$fy = intval (substr($periodWanted, 1));
			$fms = [];
			$months = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [fiscalType] = 0 AND [fiscalYear] = %i ORDER BY [globalOrder]", $fy);
			foreach ($months as $fm)
			{
				$fms[] = $fm['ndx'];
				$periodResult['periods'][$fm['ndx']] = ['title' => $fm['calendarYear'].'.'.$fm['calendarMonth']];
				if (!isset($periodResult['dateBegin']))
					$periodResult['dateBegin'] = $fm['start'];
				$periodResult['dateEnd'] = $fm['end'];
				$fiscalYearMethod = $app->cfgItem ('e10doc.acc.periods.'.$fm['fiscalYear'].'.method');
				if (!isset($periodResult['accMethods'][$fiscalYearMethod]))
					$periodResult['accMethods'][$fiscalYearMethod] = 1;
				else
					$periodResult['accMethods'][$fiscalYearMethod]++;
			}
			$periodResult['fiscalPeriod'] = /*'M'.*/implode(',', $fms);
		}
		else
		if ($periodType === 'M')
		{
			$fms = [];
			$months = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [fiscalType] = 0 AND [end] >= %d AND [start] <= %d ORDER BY [globalOrder]", $startDate, $todayDate);
			foreach ($months as $fm)
			{
				$fms[] = $fm['ndx'];
				$periodResult['periods'][$fm['ndx']] = ['title' => $fm['calendarYear'].'.'.$fm['calendarMonth']];
				if (!isset($periodResult['dateBegin']))
					$periodResult['dateBegin'] = $fm['start'];
				$periodResult['dateEnd'] = $fm['end'];
				$fiscalYearMethod = $app->cfgItem ('e10doc.acc.periods.'.$fm['fiscalYear'].'.method');
				if (!isset($periodResult['accMethods'][$fiscalYearMethod]))
					$periodResult['accMethods'][$fiscalYearMethod] = 1;
				else
					$periodResult['accMethods'][$fiscalYearMethod]++;
			}
			$periodResult['fiscalPeriod'] = /*'M'.*/implode(',', $fms);
		}
	}

	static function fiscalPeriodQuery (&$q, $value, $tablePrefix = 'heads.')
	{
		if ($value === NULL)
			return;
		$parts = explode(',', $value);
		if (count($parts) === 1)
		{
			if ($parts[0][0] === 'Y')
			{
				$fy = intval (substr ($parts[0], 1));
				if ($fy)
					array_push ($q, " AND {$tablePrefix}[fiscalYear] = %i", $fy);
			}
			else
			{
				$fm = intval ($parts[0]);
				if ($fm)
					array_push ($q, " AND {$tablePrefix}[fiscalMonth] = %i", $fm);
			}
		}
		else
		{
			array_push ($q, " AND {$tablePrefix}[fiscalMonth] IN %in", $parts);
		}
	}

	static function fiscalPeriodDateQuery ($app, &$q, $column, $value)
	{
		if ($value === NULL)
			return;
		$parts = explode(',', $value);
		if (count($parts) === 1)
		{
			if ($parts[0][0] === 'Y')
			{
				$fy = intval (substr ($parts[0], 1));
				$fiscalYear = $app->cfgItem ('e10doc.acc.periods.'.$fy, FALSE);
				if ($fiscalYear)
					array_push ($q, " AND ($column <= %d", $fiscalYear['end'], " AND $column >= %d)", $fiscalYear['begin']);
			}
			else
			{
				$fm = intval ($parts[0]);
				$i = e10utils::fiscalPeriodDateInterval($app, $fm);
				array_push ($q, " AND ($column <= %d", $i['end'], " AND $column >= %d)", $i['begin']);
			}
		}
		else
		{
			$i = e10utils::fiscalPeriodDateInterval($app, $parts);
			array_push ($q, " AND ($column <= %d", $i['end'], " AND $column >= %d)", $i['begin']);
		}
	}

	static function fiscalPeriodDateInterval ($app, $fm, $enableAllTypes = FALSE)
	{
		$q[] = 'SELECT * FROM [e10doc_base_fiscalmonths] WHERE 1';

		if (!$enableAllTypes)
			array_push ($q, ' AND [fiscalType] = %i', 0);

		if (is_array($fm))
			array_push ($q, ' AND ndx IN %in', $fm);
		else
			array_push ($q, ' AND ndx = %i', $fm);
		array_push ($q, ' ORDER BY [globalOrder]');

		$result = ['begin' => NULL, 'end' => NULL];
		$periods = $app->db()->query($q);
		foreach ($periods as $r)
		{
			if (!$result['begin'] || $r['start'] < $result['begin'])
				$result['begin'] = $r['start'];
			if (!$result['end'] || $r['end'] > $result['end'])
				$result['end'] = $r['end'];
		}

		return $result;
	}

	static function vatPeriodQuery (&$q, $value)
	{
		$vp = intval ($value);
		if ($vp)
			array_push ($q, " AND heads.[taxPeriod] = %i", $vp);
	}

	static function todayFiscalMonth ($app, $date = NULL, $fiscalType = 0)
	{
		if ($date)
			$cd = utils::createDateTime($date)->format('Y-m-d');
		else
			$cd = utils::today('Y-m-d', $app);

		$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE start <= %d AND end >= %d AND [fiscalType] = %i ORDER BY [globalOrder] DESC", $cd, $cd, $fiscalType)->fetch ();
		if ($period)
			return $period ['ndx'];

		return 0;
	}

	static function yearLastFiscalMonth ($app, $fiscalYear, $fiscalType = 0)
	{
		$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalYear = %i AND [fiscalType] = %i ORDER BY [globalOrder] DESC", $fiscalYear, $fiscalType)->fetch ();
		if ($period)
			return $period ['ndx'];

		return 0;
	}

	static function yearFirstFiscalMonth ($app, $fiscalYear, $fiscalType = 0, $returnRecData = FALSE)
	{
		$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE fiscalYear = %i", $fiscalYear,
			" AND [fiscalType] = %i", $fiscalType,
			" ORDER BY [globalOrder] LIMIT 1")->fetch ();
		if ($period && $returnRecData)
			return $period->toArray();
		elseif ($period)
			return $period ['ndx'];

		return 0;
	}

	static function prevFiscalYear($app, $fiscalYear)
	{
		$ffm = self::yearFirstFiscalMonth ($app, $fiscalYear, 1, TRUE);

		$period = $app->db()->query("SELECT * FROM [e10doc_base_fiscalmonths] WHERE [globalOrder] < %i", $ffm['globalOrder'],
			" ORDER BY [globalOrder] DESC LIMIT 1")->fetch ();

		if ($period)
			return $period['fiscalYear'];

		return 0;
	}

	static function prevFiscalMonth ($app, $date = NULL, $months = 1)
	{
		if ($date)
			$cd = utils::createDateTime($date);
		else
			$cd = utils::today('', $app);

		$pd = clone $cd;
		$pd->sub(new \DateInterval('P'.$months.'M'));

		return self::todayFiscalMonth($app, $pd);
	}

	static function todayFiscalYear ($app, $date = NULL, $getCfgItem = FALSE)
	{
		if ($date)
			$cd = utils::createDateTime($date)->format('Y-m-d');
		else
			$cd = utils::today('Y-m-d');
		foreach ($app->cfgItem ('e10doc.acc.periods') as $ap)
		{
			if ($ap['begin'] > $cd || $ap['end'] < $cd)
				continue;
			if ($getCfgItem)
				return $ap;

			return $ap['ndx'];
		}

		return 0;
	}

	static function prevFiscalPeriodYear ($app, $fp)
	{
		$period = $app->db()->query('SELECT * FROM [e10doc_base_fiscalmonths] WHERE [ndx] = %i', $fp)->fetch ();
		if (!$period)
			return 0;

		$pd = new \DateTime($period['start']);
		$pd->sub(new \DateInterval('P12M'));

		return self::todayFiscalMonth($app, $pd, $period['fiscalType']);
	}

	static function usersBalancesGroups ($app)
	{
		$groups = [];

		$ug = $app->userGroups ();

		$ql[] = 'SELECT * FROM [e10_base_doclinks] as docLinks ';
		array_push ($ql, 'WHERE srcTableId = %s', 'e10.persons.groups', 'AND dstTableId = %s', 'e10.persons.groups');
		array_push ($ql, ' AND docLinks.linkId = %s', 'e10-persons-groups-balances', 'AND srcRecId IN %in', $ug);

		$rows = $app->db()->query ($ql);
		foreach ($rows as $r)
			$groups[] = $r['dstRecId'];

		if (count($groups))
			return $groups;

		return FALSE;
	}

	static function personHasGroup ($app, $personNdx, $groups)
	{
		$q = 'SELECT ndx FROM e10_persons_personsgroups WHERE person = %i AND [group] IN %in';
		$exist = $app->db()->query ($q, $personNdx, $groups)->fetch ();

		if (isset ($exist['ndx']))
			return TRUE;

		return FALSE;
	}

	static function personGroups ($app, $personNdx)
	{
		$groups = [];
		if (!$personNdx)
			return $groups;
		$rows = $app->db()->query('SELECT [group] FROM e10_persons_personsgroups WHERE person = %i', $personNdx);
		foreach ($rows as $r)
			$groups[] = $r['group'];
		return $groups;
	}

	static function docRowDir ($app, $rowRecData, $headRecData)
	{
		$docType = $app->cfgItem ('e10.docs.types.' . $headRecData['docType']);
		if (isset ($docType ['docDir']) && $docType ['docDir'] != 0)
			return $docType ['docDir'];

		if ($headRecData['docType'] == 'cash')
		{
			if ($headRecData ['cashBoxDir'] == 1) // příjem
				return 2;
			return 1;
		}

		if ($headRecData['docType'] === 'bank' || $headRecData['docType'] === 'cmnbkp')
		{
			if ($rowRecData ['debit'] != 0.0)
				return 1;
			if ($rowRecData ['credit'] != 0.0)
				return 2;
			return FALSE;
		}

		return 0;
	}

	static function balanceOverDueClass ($app, $overDueDays)
	{
		if ($overDueDays > 90)
			return 'e10-warning3';
		if ($overDueDays > 60)
			return 'e10-warning2';
		if ($overDueDays > 15)
			return 'e10-warning1';

		return 'e10-warning0';
	}

	static function balanceOverDueDate ($app)
	{
		$today = utils::today();
		$today->add(\DateInterval::createFromDateString('-7 days'));

		return $today;
	}

	static function headsTaxDate ($recData)
	{
		switch ($recData['taxPercentDateType'])
		{
			case 0: return $recData['dateTax'];
			case 1: return $recData['dateIssue'];
		}

		return NULL;
	}

	static function warehouseOptions ($app, $warehouse, $fiscalYear)
	{
		$options = [
			'calcPrices' => 0, 'accounting' => 0,
			'debsAccInvAcquisition' => '', 'debsAccInvInStore' => '', 'debsAccInvInTransit' => ''
			];

		$or = $app->db()->query ('SELECT * FROM e10doc_base_warehousesoptions WHERE warehouse = %i AND fiscalYear = %i', $warehouse, $fiscalYear)->fetch();

		if (!$or)
			$or = $app->db()->query ('SELECT * FROM e10doc_base_warehousesoptions WHERE warehouse = %i AND fiscalYear = 0', $warehouse)->fetch();

		if ($or)
		{
			$options['calcPrices'] = $or['calcPrices'];
			$options['debsAccInvAcquisition'] = $or['debsAccInvAcquisition'];
			$options['debsAccInvInStore'] = $or['debsAccInvInStore'];
			$options['debsAccInvInTransit'] = $or['debsAccInvInTransit'];
		}

		return $options;
	}

	static function loadProperties ($app, $pkeys)
	{
		if (is_array($pkeys))
			$personsIds = implode (', ', $pkeys);
		else
			$personsIds = strval($pkeys);

		$properties = array ();

		if ($personsIds === '')
			return $properties;

		/* properties */
		$pdefs = $app->cfgItem ('e10.base.properties');

		$q = "SELECT [recid], [group], [valueString], [valueDate], [property], [note] FROM [e10_base_properties] WHERE [tableid] = %s AND [recid] IN ($personsIds)";
		$contacts = $app->db()->fetchAll ($q, 'e10.persons.persons');
		forEach ($contacts as $c)
		{
			if ($c ['valueString'] == '')
				continue;

			$text = $c ['valueString'];
			if ($c ['valueDate'])
				$text = utils::datef ($c ['valueDate'], '%D');

			$np = array ('text' => $text, 'pid' => $c ['property'], 'class' => 'nowrap');
			if (isset ($pdefs [$c ['property']]['icon']))
				$np ['icon'] = $pdefs [$c ['property']]['icon'];
			if (isset ($pdefs [$c ['property']]['icontxt']))
				$np ['icontxt'] = $pdefs [$c ['property']]['icontxt'];
			if ($c['note'] != '')
				$np['prefix'] = $c['note'];
			if ($c ['valueDate'])
				$np['valueDate'] = $c['valueDate'];

			$properties [$c ['recid']][$c ['group']][] = $np;
		}

		return $properties;
	}

	static function docTaxHomeCountryId($app, $docHeadRecData)
	{
		$thc = $app->cfgItem ('options.core.ownerDomicile', 'cz');
		if (!$thc || $thc === '')
			$thc = 'cz';
		return $thc;
	}

	static function docTaxCountryId($app, $docHeadRecData)
	{
		if (!isset($docHeadRecData['taxCountry']) || $docHeadRecData['taxCountry'] === '')
			return 'cz';
		return $docHeadRecData['taxCountry'];
	}

	static function docTaxAreaId($app, $docHeadRecData)
	{
		$taxReg = $app->cfgItem('e10doc.base.taxRegs.'.$docHeadRecData['vatReg'], NULL);
		if (!$taxReg)
			return 'eu';

		return $taxReg['taxArea'];
	}

	static function docTaxCodes($app, $docHeadRecData)
	{
		return self::taxCodes ($app, self::docTaxAreaId($app, $docHeadRecData), self::docTaxCountryId($app, $docHeadRecData));
	}

	static function docTaxNotes($app, $docHeadRecData)
	{
		return self::taxNotes ($app, self::docTaxAreaId($app, $docHeadRecData), self::docTaxCountryId($app, $docHeadRecData));
	}

	static function taxRegCurrency($app, $taxRegCfg, $taxCountryId)
	{
		if ($taxRegCfg['payerKind'] === 1 && $taxRegCfg['taxArea'] === 'eu')
			return 'eur';

		$country = $app->cfgItem('e10doc.base.taxAreas.'.$taxRegCfg['taxArea'].'.countries.'.$taxCountryId, NULL);
		if (!$country)
			return 'eur';

		return $country['currency'];
	}

	static function taxCodes($app, $areaId, $countryId)
	{
		$tc = $app->cfgItem ('e10doc.taxes.'.$areaId.'.'.$countryId.'.taxCodes', NULL);
		return $tc;
	}

	static function taxNotes($app, $areaId, $countryId)
	{
		$tc = $app->cfgItem ('e10doc.taxes.'.$areaId.'.'.$countryId.'.taxNotes', NULL);
		return $tc;
	}

	static function taxCodeForDocRow ($app, $docHeadRecData, $dirTax, $taxRate)
	{
		$taxes = self::docTaxCodes($app, $docHeadRecData);
		$taxCode = 'EUCZ000';
		if ($taxes)
		{
			forEach ($taxes as $itmid => $itm)
			{
				if ($itm ['dir'] != $dirTax)
					continue;
				if ($itm ['rate'] != $taxRate)
					continue;
				$taxCode = $itmid;
				break;
			}
		}
		return $taxCode;
	}

	static function taxCodeForItem ($app, $vatRegCfg, $dirTax, $taxRate)
	{
		//$taxes = self::docTaxCodes($app, $docHeadRecData);
		$taxes = self::taxCodes ($app, $vatRegCfg['taxArea'], $vatRegCfg['taxCountry']);
		$taxCode = '';
		forEach ($taxes as $itmid => $itm) {
			if ($itm ['dir'] != $dirTax)
				continue;
			if ($itm ['rate'] != $taxRate)
				continue;
			$taxCode = $itmid;
			break;
		}
		return $taxCode;
	}

	static function taxCodeCfg($app, $taxCodeId)
	{
		$areaId = strtolower(substr($taxCodeId, 0, 2));
		$countryId = strtolower(substr($taxCodeId, 2, 2));

		$tc = $app->cfgItem ('e10doc.taxes.'.$areaId.'.'.$countryId.'.taxCodes.'.$taxCodeId, NULL);
		return $tc;
	}

	static function taxCountries($app, $taxAreaId, $date = NULL, $fullConfig = FALSE)
	{
		$ta = $app->cfgItem('e10doc.base.taxAreas.'.$taxAreaId, NULL);
		if (!$ta)
			return [];

		$enum = [];
		foreach ($ta['countries'] as $countryId => $country)
		{
			if ($fullConfig)
				$enum[$countryId] = $country;
			else
				$enum[$countryId] = $country['fn'];
		}

		return $enum;
	}

	static function taxPercent ($app, $taxCode, $date)
	{
		$areaId = strtolower(substr($taxCode, 0, 2));
		$countryId = strtolower(substr($taxCode, 2, 2));
		$dateTax = utils::createDateTime ($date);

		$percSettings = $app->cfgItem ('e10doc.taxes.'.$areaId.'.'.$countryId.'.taxPercents', NULL);

		forEach ($percSettings as $itm)
		{
			if ($itm ['code'] != $taxCode)
				continue;

			$dateFrom = utils::createDateTime ($itm ['from']);
			$dateTo = utils::createDateTime ($itm ['to']);

			if (($dateFrom) && ($dateFrom > $dateTax))
				continue;
			if (($dateTo) && ($dateTo < $dateTax))
				continue;

			return floatval ($itm ['value']);
		}
		return FALSE;
	}

	static function taxCalcIncludingVATCode ($app, $dateAccounting, $initValue = FALSE)
	{
		if ($initValue === 1)
			return $initValue;

		$dvs1 = $app->cfgItem ('options.e10doc-finance.dateValidFrom_CZ_VAT_2019_4', '1.9.2019');
		$dv1 = \DateTime::createFromFormat ('d.m.Y H:i:s', $dvs1.' 00:00:00');
		if (!$dv1)
			$dv1 = \DateTime::createFromFormat ('d.m.Y  H:i:s', '1.9.2019 00:00:00');

		if (isset($dateAccounting) && !utils::dateIsBlank($dateAccounting))
		{
			if (utils::createDateTime($dateAccounting) < $dv1)
				return 2;
		}

		return 3;
	}

	static function itemPriceSell($app, $taxRegCfg, $taxCalc, $itemRecData)
	{
		if ($taxRegCfg)
		{
			if ($taxCalc == 1)
			{ // ze základu
				if ($itemRecData['priceSellBase'] != 0.0)
					return $itemRecData['priceSellBase'];
				if ($itemRecData['priceSellTotal'] != 0.0)
				{
					$taxDate = utils::today();
					$taxCode = self::taxCodeForItem($app, $taxRegCfg, 1, $itemRecData['vatRate']);
					$taxPercents = self::taxPercent ($app, $taxCode, $taxDate);
					$price = $itemRecData['priceSellTotal'];
					$k = round (($taxPercents / ($taxPercents + 100)), 4);
					$tax = utils::round (($price * $k), 2, 0);
					$base = round (($price - $tax), 2);
					return $base;
				}
			}
			elseif ($taxCalc == 2 || $taxCalc == 3)
			{ // ze ceny celkem
				if ($itemRecData['priceSellTotal'] != 0.0)
					return $itemRecData['priceSellTotal'];
				if ($itemRecData['priceSellBase'] != 0.0)
				{
					$taxDate = utils::today();
					$taxCode = self::taxCodeForItem($app, $taxRegCfg, 1, $itemRecData['vatRate']);
					$taxPercents = self::taxPercent ($app, $taxCode, $taxDate);
					$price = $itemRecData['priceSellBase'];
					$total = utils::round (($price * ((100 + $taxPercents) / 100)), 2, 0);
					return $total;
				}
			}
		}

		// -- nedaňový doklad
		if ($itemRecData['priceSellTotal'] != 0.0)
			return $itemRecData['priceSellTotal'];
		if ($itemRecData['priceSellBase'] != 0.0)
			return $itemRecData['priceSellBase'];
		if ($itemRecData ['priceSell'] != 0.0)
			return $itemRecData ['priceSell'];

		return 0.0;
	}

	static function primaryTaxRegCfg($app)
	{
		$taxRegs = $app->cfgItem('e10doc.base.taxRegs', NULL);
		if (!$taxRegs)
			return NULL;

		$k = key($taxRegs);

		return $taxRegs[$k];
	}
}

