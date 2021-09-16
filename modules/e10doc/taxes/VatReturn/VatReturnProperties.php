<?php

namespace e10doc\taxes\VatReturn;

use \e10\utils, \e10\Utility;


/**
 * Class VatReturnProperties
 * @package e10doc\taxes\VatReturn
 */
class VatReturnProperties extends \e10doc\taxes\TaxReportProperties
{
	public function load ($taxReportNdx, $filingNdx = 0)
	{
		parent::load($taxReportNdx, $filingNdx);

		$this->loadProperties($taxReportNdx, $filingNdx);
		$this->createHeadProperties();
	}

	public function loadProperties ($taxReportNdx, $filingNdx = 0)
	{
		$xmlConvert = [
				'e10-CZ-TR-zakladni' => [ // informace o přiznání
						'e10-CZ-TR-FU' => 'c_ufo',
						'e10-CZ-TR-pracFU' => 'c_pracufo',
				],
				'e10-CZ-TR-subjekt' => [ // informace o subjektu
						'e10-CZ-TR-typSubjektu' => 'typ_ds',
						'e10-CZ-TR-typPlatce' => 'typ_platce',
						'e10-CZ-TR-jmenoPrOsoby' => 'zkrobchjm',
						'e10-CZ-TR-prijmeni' => 'prijmeni',
						'e10-CZ-TR-jmeno' => 'jmeno',
						'e10-CZ-TR-titul' => 'titul',

						'e10-CZ-TR-ulice' => 'ulice',
						'e10-CZ-TR-cisPopis' => 'c_pop',
						'e10-CZ-TR-cisOrient' => 'c_orient',
						'e10-CZ-TR-mesto' => 'naz_obce',
						'e10-CZ-TR-PSC' => 'psc',
						'e10-CZ-TR-stat' => 'stat',
						'e10-CZ-TR-KOKEC' => 'c_okec',
				],
				'e10-CZ-TR-kontakt' => [ // kontakt na subjekt
						'e10-CZ-TR-telefon'=> 'c_telef',
						'e10-CZ-TR-email'=> 'email',
						'e10-CZ-TR-datSchranka'=> 'id_dats',
				],
				'e10-CZ-TR-oprOsoba' => [ // oprávněná/podepisující osoba subjektu
						'e10-CZ-TR-prijmeni' => 'opr_prijmeni',
						'e10-CZ-TR-jmeno' => 'opr_jmeno',
						'e10-CZ-TR-vztah' => 'opr_postaveni',
				],
				'e10-CZ-TR-sestavil' => [
						'e10-CZ-TR-prijmeni' => 'sest_prijmeni',
						'e10-CZ-TR-jmeno' => 'sest_jmeno',
						'e10-CZ-TR-telefon' => 'sest_telef',
				],
				'e10-CZ-TR-podOsoba' => [ // podepisující osoba - zástupce
						'e10-CZ-TR-typPodOsoba' => 'zast_typ',
						'e10-CZ-TR-kodPodOsoba' => 'zast_kod',
						'e10-CZ-TR-nazevPrOsoby' => 'zast_nazev',
						'e10-CZ-TR-ICPrOsoby' => 'zast_ic',
						'e10-CZ-TR-prijmeni' => 'zast_prijmeni',
						'e10-CZ-TR-jmeno' => 'zast_jmeno',
						'e10-CZ-TR-datumNar' => 'zast_dat_nar',
						'e10-CZ-TR-evidCislo' => 'zast_ev_cislo'
				],
				'e10-EU-VTR-podani' => [ // podání
						'e10-EU-VTR-druhHlaseni' => 'dapdph_forma',
						'e10-EU-VTR-denDuvDodPri' => 'd_zjist',
				]
		];

		if ($filingNdx)
		{
			$tableId = 'e10doc.taxes.filings';
			$recId = $filingNdx;
		}
		else
		{
			$tableId = 'e10doc.taxes.reports';
			$recId = $taxReportNdx;
		}

		$q[] = 'SELECT * FROM [e10_base_properties] ';
		array_push ($q, ' WHERE [tableid] = %s', $tableId, ' AND [recid] = %i', $recId);

		$allProperties = $this->app()->cfgItem('e10.base.properties');
		$myProperties = [];
		$xmlProperties = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$p = ['value' => $r['valueString'], 'text' => $r['valueString']];
			if (isset($allProperties[$r['property']]) && $allProperties[$r['property']]['type'] === 'enum')
				$p['text'] = $allProperties[$r['property']]['enum'][$r['valueString']]['fullName'];

			$myProperties[$r['group'].'-'.$r['property']] = $p['text'];
			if (isset($xmlConvert[$r['group']][$r['property']]))
				$xmlProperties[$xmlConvert[$r['group']][$r['property']]] = $p['value'];
		}

		if (isset($xmlProperties['stat'])) // CZ -> Česká republika
			$xmlProperties['stat'] = $myProperties['e10-CZ-TR-subjekt-e10-CZ-TR-stat'];

		$xmlProperties['trans'] = 'A';

		// OKEC/NACE
		$okecList = utils::loadCfgFile(__SHPD_MODULES_DIR__.'e10doc/taxes/reports/tax-eu-vat-tr/cz/okec.json');
		if (isset($okecList[$xmlProperties['c_okec']]))
			$myProperties['e10-CZ-TR-subjekt-e10-CZ-TR-NOKEC'] = $okecList[$xmlProperties['c_okec']];
		else
			$myProperties['e10-CZ-TR-subjekt-e10-CZ-TR-NOKEC'] = 'Chybný kód OKEČ: '.$xmlProperties['c_okec'];

		$this->properties['all'] = $myProperties;
		$this->properties['xml'] = $xmlProperties;
	}

	function createHeadProperties ()
	{
		$this->properties['xml']['dokument'] = 'DP3';
		$this->properties['xml']['k_uladis'] = 'DPH';

		$this->properties['xml']['d_poddp'] = utils::today()->format ('d.m.Y');

		if (!utils::dateIsBlank($this->taxReportRecData['datePeriodBegin']))
		{
			$this->properties['xml']['rok'] = $this->taxReportRecData['datePeriodBegin']->format('Y');

			if ($this->taxReportRecData['datePeriodBegin']->format('m') == $this->taxReportRecData['datePeriodEnd']->format('m'))
			{ // monthly
				$this->properties['xml']['mesic'] = intval($this->taxReportRecData['datePeriodBegin']->format('m'));
			} else
			{ // quarterly
				$this->properties['xml']['ctvrt'] = intval(($this->taxReportRecData['datePeriodEnd']->format('n') - 1) / 3) + 1;
			}

			if ($this->taxReportRecData['datePeriodBegin']->format('d') != 1 ||
					$this->taxReportRecData['datePeriodEnd']->format('d.m.Y') != $this->taxReportRecData['datePeriodEnd']->format('t.m.Y')
			)
			{ // is not full period
				$this->properties['xml']['zdobd_od'] = $this->taxReportRecData['datePeriodBegin']->format('d.m.Y');
				$this->properties['xml']['zdobd_do'] = $this->taxReportRecData['datePeriodEnd']->format('d.m.Y');
			}
		}

		$vatReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$this->taxReportRecData['taxReg'], NULL);
		if ($vatReg)
			$this->properties['xml']['dic'] = substr($vatReg['taxId'], 2);

		if ($this->filingRecData)
		{
			if (!utils::dateIsBlank($this->filingRecData['dateIssue']))
				$this->properties['xml']['d_poddp'] = $this->filingRecData['dateIssue']->format ('d.m.Y');
		}

		if (!isset($this->properties['xml']['dapdph_forma']))
			$this->properties['xml']['dapdph_forma'] = 'B';

		$this->properties['flags']['forma'][$this->properties['xml']['dapdph_forma']] = 'X';
		$this->properties['flags']['typPlatce'][$this->properties['xml']['typ_platce']] = 'X';
	}

	public function name()
	{
		$name = '';
		if (isset ($this->properties['all']['e10-EU-VTR-podani-e10-EU-VTR-druhHlaseni']))
			$name .= $this->properties['all']['e10-EU-VTR-podani-e10-EU-VTR-druhHlaseni'];

		if (isset($this->filingRecData['dateIssue']))
			$name .= ' '.utils::datef ($this->filingRecData['dateIssue'], '%d');

		return $name;
	}

	public function details(&$details)
	{
	}
}
