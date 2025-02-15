<?php

namespace e10doc\waster\libs;
use \Shipard\Utils\Utils;


/**
 * class ReportWasteReturn
 */
class ReportWasteReturn extends \e10doc\core\libs\reports\DocReportBase
{
	var ?\e10pro\reports\waste_cz\libs\ReportWasteCompanies $report = NULL;

	function init ()
	{
		parent::init();
		$this->setReportId('e10doc.waster.wasteReturn');

		$this->paperOrientation = 'landscape';
	}

	public function loadData2 ()
	{
		parent::loadData2();

		$this->loadData_MainPerson('person');
		$this->loadData_DocumentOwner (0);

		$this->data['person']['oid'] = $this->loadPersonOid ($this->recData['person']);

		$this->data['person']['addressMain'] = $this->loadPersonAddress($this->recData['person'], 1);
		$this->checkAddress($this->data['person']['addressMain']);
		$this->data['person']['addressOffice'] = $this->loadPersonAddress($this->recData['person'], 0, $this->recData['personOffice']);
		$this->checkAddress($this->data['person']['addressOffice']);

		$this->data['pokus'] = json_encode($this->data['person']);

		$this->report = new \e10pro\reports\waste_cz\libs\ReportWasteCompanies($this->app);
		$this->report->periodBegin = $this->recData['dateFrom'];
		$this->report->periodEnd = $this->recData['dateTo'];

		$this->report->init ();
		$this->report->createContent_Report();
		$this->report->createContent_Partners();

		$this->data['reports']['waste'] = [$this->report->content[0]];
		$this->data['reports']['partners'] = [$this->report->content[1]];
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = [
			'text' => 'Hlášení pro ISPOP (.xml)', 'icon' => 'system/actionDownload',
			'type' => 'action', 'action' => 'print', 'data-saveas' => 'xml', 'data-filename' => $this->saveAsFileName('xml'),
			'data-table' => $this->table->tableId(), 'data-report' => 'e10doc.waster.libs.ReportWasteReturn', 'data-pk' => $this->recData['ndx']
		];
	}

	public function saveReportAs ()
	{

		$data = $this->createXmlCode();

		$fn = Utils::tmpFileName ('xml');
		file_put_contents($fn, $data);
		$this->fullFileName = $fn;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'text/xml';
	}

	public function saveAsFileName ($type)
	{
		$fn = 'hlaseni-odpady-';
		$fn .= $this->recData['ndx'].'.xml';
		return $fn;
	}

	protected function createXmlCode()
	{
		$c = '';

		$si = $this->app->cfgItem ('serverInfo', 0);
		$shpVer = __E10_VERSION__.'.'.$si['e10commit'];

		$c .= '<F_ODP_PROD xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="2023" revision="1">'."\n";
		$c .= "<meta-info>\n";
			$c .= "\t<user-agent>SHIPARD</user-agent>\n";
			$c .= "\t<user-agent-version>".$shpVer."</user-agent-version>\n";
			$c .= "\t<custom-data>Shipard s.r.o.</custom-data>\n";
		$c .= "</meta-info>\n";

		$c .= $this->createXmlCode_Header();

		$c .= '<sekce2>'."\n";
		$c .= $this->createXmlCode_WasteCodes();
		$c .= $this->createXmlCode_Partners();
		$c .= '</sekce2>'."\n";

		$c .= '</F_ODP_PROD>'."\n";

		return $c;
	}

	protected function createXmlCode_Header()
	{
		$today = new \DateTime();

		$c = '';

		$c .= "<sekce1>\n";
			$c .= "\t<radneDoplneneHlaseni>\n";
				$c .= "\t\t<radne/>";
			$c .= "\t</radneDoplneneHlaseni>\n";
			$c .= "\t<rok>".$this->recData['year']."</rok>\n";
			$c .= "\t<overovatel>\n";
				$c .= "\t\t<orpSop>\n";
					$c .= "\t\t\t<orpSopKod>".Utils::es($this->recData['returnToORPId'])."</orpSopKod>\n";
					$c .= "\t\t\t<orpSopText>".Utils::es($this->recData['returnToORPName'])."</orpSopText>\n";
				$c .= "\t\t</orpSop>\n";
			$c .= "\t</overovatel>\n";
			$c .= "\t<overovatelOstatni/>\n";
			$c .= "\t<ohlasovatel>\n";
			$c .= "\t<spolecnostVyber>\n";

			$c .= "\t\t<pravnickaOsoba>\n";
			$c .= "\t\t\t<nazev>".Utils::es($this->data['person']['fullName'])."</nazev>\n";
			$c .= "\t\t\t<ic>".Utils::es($this->data['person']['oid'])."</ic>\n";
			$c .= "\t\t</pravnickaOsoba>\n";
		$c .= "\t</spolecnostVyber>\n";
		$c .= "\t<adresa>\n";
		$c .= "\t\t<adresaVyber>\n";
		$c .= "\t\t\t<adresaNonIszr>\n";
		$c .= "\t\t\t\t<adresaCr>\n";
		$c .= "\t\t\t\t\t<zujKod>".Utils::es($this->recData['personZUJId'])."</zujKod>\n";
		$c .= "\t\t\t\t\t<zujNazev>".Utils::es($this->data['person']['addressMain']['city'])."</zujNazev>\n";
		$c .= "\t\t\t\t\t<ulice>".Utils::es($this->data['person']['addressMain']['street'])."</ulice>\n";
		$c .= "\t\t\t\t\t<psc>".Utils::es($this->data['person']['addressMain']['zipcode'])."</psc>\n";
		$c .= "\t\t\t\t\t<cisloOrientacni>".Utils::es($this->data['person']['addressMain']['houseNumber1'])."</cisloOrientacni>\n";
		if (isset($this->data['person']['addressMain']['houseNumber2']))
			$c .= "\t\t\t\t\t<cisloPopisne>".Utils::es($this->data['person']['addressMain']['houseNumber2'])."</cisloPopisne>\n";
		$c .= "\t\t\t\t</adresaCr>\n";
		$c .= "\t\t\t</adresaNonIszr>\n";
		$c .= "\t\t</adresaVyber>\n";
		$c .= "\t</adresa>\n";
		$c .= "\t</ohlasovatel>\n";
		$c .= "\t<ohlasovatelOstatni>\n";
		$c .= "\t\t<orp>".Utils::es($this->recData['personORPId'])."</orp>\n";
		$c .= "\t</ohlasovatelOstatni>\n";
		$c .= "\t<provozovna>\n";
		$c .= "\t\t<nuloveHlaseni>false</nuloveHlaseni>\n";
		$c .= "\t\t<ohlasovatelHlasiZa>PROVOZOVNA_MIMO_SIDLO</ohlasovatelHlasiZa>\n";
		$c .= "\t\t<skladPuvodce>false</skladPuvodce>\n";
		if (isset($this->data['person']['addressOffice']['id2']))
		{
			$c .= "\t\t<icz>\n";
			$c .= "\t\t\t<icz>".Utils::es($this->data['person']['addressOffice']['id2'])."</icz>\n";
			$c .= "\t\t</icz>\n";
		}
		$c .= "\t<nazev>".Utils::es($this->data['person']['fullName'])."</nazev>\n";
		$c .= "\t<adresa>\n";
		$c .= "\t\t<adresaVyber>\n";
		$c .= "\t\t\t<adresaNonIszr>\n";
		$c .= "\t\t\t\t<adresaCr>\n";
		$c .= "\t\t\t\t\t<zujKod>".Utils::es($this->recData['personOfficeZUJId'])."</zujKod>\n";
		$c .= "\t\t\t\t\t<zujNazev>".Utils::es($this->data['person']['addressOffice']['city'])."</zujNazev>\n";
		$c .= "\t\t\t\t\t<ulice>".Utils::es($this->data['person']['addressOffice']['street'])."</ulice>\n";
		$c .= "\t\t\t\t\t<psc>".Utils::es($this->data['person']['addressOffice']['zipcode'])."</psc>\n";
		$c .= "\t\t\t\t</adresaCr>\n";
		$c .= "\t\t\t</adresaNonIszr>\n";
		$c .= "\t\t</adresaVyber>\n";
		$c .= "\t</adresa>\n";
		$c .= "\t<orp>".Utils::es($this->recData['personOfficeORPId'])."</orp>\n";
		$c .= "\t</provozovna>\n";
		$c .= "\t<provozovnaOstatni>\n";
		$c .= "\t<zapojeniDoSystemuSberuKomunOdpadu/>\n";
		$c .= "\t<udajeOObecnimSystemuNakladani/>\n";
		$c .= "\t</provozovnaOstatni>\n";
		$c .= "\t<hlaseniVyplnil>\n";
		$c .= "\t\t<jmeno>".Utils::es($this->recData['authorFirstName'])."</jmeno>\n";
		$c .= "\t\t<prijmeni>".Utils::es($this->recData['authorLastName'])."</prijmeni>\n";
		$c .= "\t\t<kontakt>\n";
		$c .= "\t\t\t<email>".Utils::es($this->recData['authorEmail'])."</email>\n";
		$c .= "\t\t\t<telefon>\n";
		$c .= "\t\t\t\t<predvolba>".Utils::es($this->recData['authorPhonePrefix'])."</predvolba>\n";
		$c .= "\t\t\t\t<cislo>".Utils::es($this->recData['authorPhone'])."</cislo>\n";
		$c .= "\t\t\t</telefon>\n";
		$c .= "\t\t</kontakt>\n";
		$c .= "<datumVyplneni>".$today->format('Y-m-d')."</datumVyplneni>\n";
		$c .= "</hlaseniVyplnil>\n";

		$c .= "</sekce1>\n";

		return $c;
	}

	protected function createXmlCode_WasteCodes()
	{
		$c = '';

		$c .= "\n";
		foreach ($this->report->wastes as $wasteId => $waste)
		{
			$c .= "<odpadSeq>\n";
			$c .= "\t<katalogoveCisloOdpadu>\n";
			$c .= "\t\t<kod>".Utils::es($waste['wasteCode'])."</kod>\n";
			$c .= "\t\t<nazev>".Utils::es($waste['wasteName'])."</nazev>\n";
			$c .= "\t</katalogoveCisloOdpadu>\n";
			$c .= "\t<kategorieOdpaduVyberVycet>O</kategorieOdpaduVyberVycet>\n";

			foreach ($waste['rows'] as $row)
			{
				$c .= "\t<bilanceSeq>\n";
				$c .= "\t\t<mnozstviOdpadu>\n";

				if (isset($row['quantityIn']) && $row['quantityIn'] != 0.0)
					$c .= "\t\t\t<celkem>".sprintf('%.6f', $row['quantityIn'])."</celkem>\n";

				if (isset($row['quantityOut']) && $row['quantityOut'] != 0.0)
					$c .= "\t\t\t<zTohoDleSloupce7>".sprintf('%.6f', $row['quantityOut'])."</zTohoDleSloupce7>\n";

				$c .= "\t\t</mnozstviOdpadu>\n";
				$c .= "\t\t<kodZpusobuNakladani>\n";
				$c .= "\t\t\t<kod>".Utils::es($row['hc'])."</kod>\n";
				$c .= "\t\t</kodZpusobuNakladani>\n";

				if (isset($row['partnerId']))
				{
					$partner = $this->report->partners[$row['partnerId']] ?? NULL;
          if ($partner)
						$c .= "\t\t<partnerIndex>".intval($partner['number'])."</partnerIndex>\n";
				}

				$c .= "\t</bilanceSeq>\n";
			}

			$c .= "</odpadSeq>\n";
		}

		return $c;
	}

	protected function createXmlCode_Partners()
	{
		$c = '';

		$c .= "\n";
		foreach ($this->report->partners as $partnerId => $partner)
		{
			$c .= "<partnerSeq>\n";
			$c .= "\t<partnerIndex>".intval($partner['number'])."</partnerIndex>\n";
			$c .= "\t<partner>\n";

			if (isset($partner['isCity']) && $partner['isCity'])
			{
				$c .= "\t\t<obcanObce>\n";
				$c .= "\t\t\t<iczuj>".Utils::es($partner['iczuj'])."</iczuj>\n";
				$c .= "\t\t\t<obecNazev>".Utils::es($partner['obec'])."</obecNazev>\n";
				$c .= "\t\t</obcanObce>\n";
			}
			else
			{
				$c .= "\t\t<subjekt>\n";

				if (isset($partner['ico']) && $partner['ico'] != '')
					$c .= "\t\t\t<ic>".$partner['ico']."</ic>\n";

				if (isset($partner['icob']))
				{
					$c .= "\t\t\t<icob>\n";
					$c .= "\t\t\t\t<icob>".Utils::es($partner['icob'])."</icob>\n";
					$c .= "\t\t\t</icob>\n";
				}

				if (isset($partner['icz']))
				{
					$c .= "\t\t\t<icz>\n";
					$c .= "\t\t\t\t<icz>".Utils::es($partner['icz'])."</icz>\n";
					$c .= "\t\t\t</icz>\n";
				}

				if (isset($partner['icp']))
				{
					$c .= "\t\t\t<icp>\n";
					$c .= "\t\t\t\t<icp>".Utils::es($partner['icp'])."</icp>\n";
					$c .= "\t\t\t</icp>\n";
				}

				$c .= "\t\t\t<nazev>".Utils::es($partner['name'])."</nazev>\n";

				if (isset($partner['ulice']) && $partner['ulice'] != '')
					$c .= "\t\t\t<ulice>".Utils::es($partner['ulice'])."</ulice>\n";
				if (isset($partner['cisloPopisne']) && $partner['cisloPopisne'] != '')
					$c .= "\t\t\t<cisloPopisne>".Utils::es($partner['cisloPopisne'])."</cisloPopisne>\n";
				if (isset($partner['cisloOrientacni']) && $partner['cisloOrientacni'] != '')
					$c .= "\t\t\t<cisloOrientacni>".Utils::es($partner['cisloOrientacni'])."</cisloOrientacni>\n";
				$c .= "\t\t\t<iczuj>".Utils::es($partner['iczuj'])."</iczuj>\n";
				if (isset($partner['obec']) && $partner['obec'] != '')
					$c .= "\t\t\t<obecNazev>".Utils::es($partner['obec'])."</obecNazev>\n";
				if (isset($partner['psc']) && $partner['psc'] != '')
					$c .= "\t\t\t<psc>".Utils::es($partner['psc'])."</psc>\n";

				$c .= "\t\t</subjekt>\n";
			}
			$c .= "\t</partner>\n";
			$c .= "</partnerSeq>\n";
		}

		return $c;
	}

	protected function loadPersonOid ($personNdx)
	{
		$q[] = 'SELECT * FROM [e10_base_properties] AS props';
		array_push ($q, ' WHERE [recid] = %i', $personNdx);
		array_push ($q, ' AND [tableid] = %s', 'e10.persons.persons', 'AND [group] = %s', 'ids', ' AND property = %s', 'oid');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if ($r['valueString'] === '')
				continue;
			return $r['valueString'];
		}

		return '';
	}

	protected function checkAddress(&$address)
	{
    if (isset($address['street']) && $address['street'] !== '')
    {
      $sp = explode(' ', $address['street']);
      if (count($sp) > 1)
      {
        $num = array_pop($sp);
        $numbers = explode('/', $num);
        if (is_numeric($numbers[0]))
        {
          $address['houseNumber1'] = $numbers[0];
          if (isset($numbers[1]))
            $address['houseNumber2'] = $numbers[1];
          $address['street'] = implode(' ', $sp);
        }
      }
    }
	}
}
