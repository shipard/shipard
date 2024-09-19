<?php

namespace e10doc\core\libs\reports;

use \E10\Application, \E10\utils, \e10\docsmodes, \E10\FormReport, \E10\FormSidebar, \E10\TableViewPanel;


/**
 * Class DocReportISDoc
 * @package e10doc\core\libs\reports
 */
class DocReportISDoc extends \e10doc\core\libs\reports\DocReport
{
	static $czBanks = [
		'0100' => ['name' => 'Komerční banka, a.s.', 'SWIFT' => 'KOMBCZPP'],
		'0300' => ['name' => 'Československá obchodní banka, a.s.', 'SWIFT' => 'CEKOCZPP'],
		'0600' => ['name' => 'GE Money Bank, a.s.', 'SWIFT' => 'AGBACZPP'],
		'0710' => ['name' => 'Česká národní banka', 'SWIFT' => 'CNBACZPP'],
		'0800' => ['name' => 'Česká spořitelna, a.s.', 'SWIFT' => 'GIBACZPX'],
		'2010' => ['name' => 'Fio banka, a.s.', 'SWIFT' => 'FIOBCZPP'],
		'2020' => ['name' => 'Bank of Tokyo-Mitsubishi UFJ (Holland) N.V. Prague Branch, organizační složka', 'SWIFT' => 'BOTKCZPP'],
		'2030' => ['name' => 'AKCENTA, spořitelní a úvěrní družstvo', 'SWIFT' => ''],
		'2060' => ['name' => 'Citfin, spořitelní družstvo', 'SWIFT' => 'CITFCZPP'],
		'2070' => ['name' => 'Moravský Peněžní Ústav – spořitelní družstvo', 'SWIFT' => 'MPUBCZPP'],
		'2100' => ['name' => 'Hypoteční banka, a.s.', 'SWIFT' => ''],
		'2200' => ['name' => 'Peněžní dům, spořitelní družstvo', 'SWIFT' => ''],
		'2210' => ['name' => 'ERB bank, a.s.', 'SWIFT' => 'FICHCZPP'],
		'2220' => ['name' => 'Artesa, spořitelní družstvo', 'SWIFT' => 'ARTTCZPP'],
		'2240' => ['name' => 'Poštová banka, a.s., pobočka Česká republika', 'SWIFT' => 'POBNCZPP'],
		'2250' => ['name' => 'Záložna CREDITAS, spořitelní družstvo', 'SWIFT' => 'CTASCZ22'],
		'2260' => ['name' => 'ANO spořitelní družstvo', 'SWIFT' => ''],
		'2310' => ['name' => 'ZUNO BANK AG, organizační složka', 'SWIFT' => 'ZUNOCZPP'],
		'2600' => ['name' => 'Citibank Europe plc, organizační složka', 'SWIFT' => 'CITICZPX'],
		'2700' => ['name' => 'UniCredit Bank Czech Republic and Slovakia, a.s.', 'SWIFT' => 'BACXCZPP'],
		'3030' => ['name' => 'Air Bank a.s.', 'SWIFT' => 'AIRACZPP'],
		'3500' => ['name' => 'ING Bank N.V.', 'SWIFT' => 'INGBCZPP'],
		'4000' => ['name' => 'Expobank CZ a.s.', 'SWIFT' => 'EXPNCZPP'],
		'4300' => ['name' => 'Českomoravská záruční a rozvojová banka, a.s.', 'SWIFT' => 'CMZRCZP1'],
		'5400' => ['name' => 'The Royal Bank of Scotland plc, organizační složka', 'SWIFT' => 'ABNACZPP'],
		'5500' => ['name' => 'Raiffeisenbank a.s.', 'SWIFT' => 'RZBCCZPP'],
		'5800' => ['name' => 'J & T Banka, a.s.', 'SWIFT' => 'JTBPCZPP'],
		'6000' => ['name' => 'PPF banka a.s.', 'SWIFT' => 'PMBPCZPP'],
		'6100' => ['name' => 'Equa bank a.s.', 'SWIFT' => 'EQBKCZPP'],
		'6200' => ['name' => 'COMMERZBANK Aktiengesellschaft, pobočka Praha', 'SWIFT' => 'COBACZPX'],
		'6210' => ['name' => 'mBank S.A., organizační složka', 'SWIFT' => 'BREXCZPP'],
		'6300' => ['name' => 'BNP Paribas Fortis SA/NV, pobočka Česká republika', 'SWIFT' => 'GEBACZPP'],
		'6700' => ['name' => 'Všeobecná úverová banka a.s., pobočka Praha', 'SWIFT' => 'SUBACZPP'],
		'6800' => ['name' => 'Sberbank CZ, a.s.', 'SWIFT' => 'VBOECZ2X'],
		'7910' => ['name' => 'Deutsche Bank A.G. Filiale Prag', 'SWIFT' => 'DEUTCZPX'],
		'7940' => ['name' => 'Waldviertler Sparkasse Bank AG', 'SWIFT' => 'SPWTCZ21'],
		'7950' => ['name' => 'Raiffeisen stavební spořitelna a.s.', 'SWIFT' => ''],
		'7960' => ['name' => 'Českomoravská stavební spořitelna, a.s.', 'SWIFT' => ''],
		'7970' => ['name' => 'Wüstenrot-stavební spořitelna a.s.', 'SWIFT' => ''],
		'7980' => ['name' => 'Wüstenrot hypoteční banka a.s.', 'SWIFT' => ''],
		'7990' => ['name' => 'Modrá pyramida stavební spořitelna, a.s.', 'SWIFT' => ''],
		'8030' => ['name' => 'Raiffeisenbank im Stiftland eG pobočka Cheb, odštěpný závod', 'SWIFT' => 'GENOCZ21'],
		'8040' => ['name' => 'Oberbank AG pobočka Česká republika', 'SWIFT' => 'OBKLCZ2X'],
		'8060' => ['name' => 'Stavební spořitelna České spořitelny, a.s.', 'SWIFT' => ''],
		'8090' => ['name' => 'Česká exportní banka, a.s.', 'SWIFT' => 'CZEECZPP'],
		'8150' => ['name' => 'HSBC Bank plc - pobočka Praha', 'SWIFT' => 'MIDLCZPP'],
		'8200' => ['name' => 'PRIVAT BANK AG der Raiffeisenlandesbank Oberösterreich v České republice', 'SWIFT' => ''],
		'8220' => ['name' => 'Payment Execution s.r.o.', 'SWIFT' => 'PAERCZP1'],
		'8230' => ['name' => 'EEPAYS s.r.o.', 'SWIFT' => 'EEPSCZPP'],
		'8240' => ['name' => 'Družstevní záložna Kredit', 'SWIFT' => '']
	];

	static $paymentMethods = [
		"0" => 42, // převodem
		"1" => 10, // v hotovosti
		"2" => 48, // kartou
		"3" => 50, // dobírka
		"4" => 42, // fakturou --> převodem
		"5" => 10, // pokladním lístkem --> v hotovosti
		"6" => 97, // sběrný doklad --> zaúčtování mezi partnery
		"7" => 31, // inkasem --> credit transfer?
		"8" => 97, // nehradí se --> zaúčtování mezi partnery
		"9" => 20, // šekem
	 "10" => 20, // poštovní poukázkou --> šekem
	 "11" => 42, // PayPal --> platba na účet
	 "12" => 42, // platební brána --> platba na účet
	];

	function init ()
	{
		$this->reportId = 'reports.default.e10doc.cmnbkp.isdoc';
		$this->reportTemplate = 'reports.default.e10doc.cmnbkp.isdoc';
	}

	function loadData ()
	{
		parent::loadData();

		// -- uuid
		$t = (!utils::dateIsBlank($this->recData['activateTimeLast'])) ? intval($this->recData['activateTimeLast']->format('U')) : time();
		$uuid = sprintf('%08x-%04x-%04x-%04x-%012s',
			$t,
			$t & 0x0000FFFF, ($this->recData['ndx'] & 0x00000FFF) * $this->recData['dbCounter'], $this->recData['ndx'] & 0xFFFFFFFF,
			substr(base_convert($this->recData['ndx'], 10, 16).base_convert(intval($this->app->cfgItem('dsid')), 10, 16), 0, 12)
		);
		$this->data['ISDOC']['UUID'] = $uuid;


		/*
	<!--Typ dokumentu, z násl.číselníku-->
	<!-- 1: Faktura - daňový doklad
			 2: Opravný daňový doklad (dobropis)
			 3: Opravný daňový doklad (vrubopis)
			 4: Zálohová faktura (nedaňový zálohový list)
			 5: Daňový doklad při přijetí platby (daňový zálohový list)
			 6: Opravný daňový doklad při přijetí platby (dobropis DZL)
			 7: Zjednodušený daňový doklad
	-->
		*/
		$this->data['ISDOC']['documentType'] = 1;
		if ($this->recData['correctiveDoc'] == TRUE)
		{
			if ($this->recData['sumTotal'] >= 0.0)
				$this->data['ISDOC']['documentType'] = 3;
			else
				$this->data['ISDOC']['documentType'] = 2;
		}

		$this->data['ISDOC']['paymentMethod'] = 42;
		if (isset(self::$paymentMethods[$this->recData['paymentMethod']]))
			$this->data['ISDOC']['paymentMethod'] = self::$paymentMethods[$this->recData['paymentMethod']];

		// -- vat calc
		$this->data['ISDOC']['vatCalculationMethod'] = 0;
		if ($this->recData['taxCalc'] == 3 || $this->recData['taxCalc'] == 2)
			$this->data['ISDOC']['vatCalculationMethod'] = 1;

		$this->data['ISDOC']['dateIssue'] = $this->recData['dateIssue']->format('Y-m-d');
		$this->data['ISDOC']['dateTax'] = $this->recData['dateTax']->format('Y-m-d');
		if (!Utils::dateIsBlank($this->recData['dateDue']))
			$this->data['ISDOC']['dateDue'] = $this->recData['dateDue']->format('Y-m-d');

		$pos = strrpos ($this->data['owner']['address']['street'], ' ');
		if ($pos === FALSE)
		{
			$this->data['ISDOC']['owner']['address']['streetName'] = $this->data['owner']['address']['street'];
			$this->data['ISDOC']['owner']['address']['buildingNumber'] = '';
		}
		else
		{
			$this->data['ISDOC']['owner']['address']['streetName'] = substr ($this->data['owner']['address']['street'], 0, $pos);
			$this->data['ISDOC']['owner']['address']['buildingNumber'] = substr ($this->data['owner']['address']['street'], $pos+1);
		}

		$pos = strrpos ($this->data['person']['address']['street'], ' ');
		if ($pos === FALSE)
		{
			$this->data['ISDOC']['person']['address']['streetName'] = $this->data['person']['address']['street'];
			$this->data['ISDOC']['person']['address']['buildingNumber'] = '';
		}
		else
		{
			$this->data['ISDOC']['person']['address']['streetName'] = substr ($this->data['person']['address']['street'], 0, $pos);
			$this->data['ISDOC']['person']['address']['buildingNumber'] = substr ($this->data['person']['address']['street'], $pos+1);
		}

		$q = "SELECT * FROM [e10_base_properties] WHERE [tableid] = 'e10.persons.persons' AND [recid] = %i";
		$docProperties = $this->table->db()->query($q, $this->recData['owner']);
		foreach ($docProperties as $p)
			$this->data['ISDOC']['owner'][$p['group']][$p['property']] = $p['valueString'];

		$this->data['ISDOC']['owner']['contacts']['fullName'] = $this->data['author']['fullName'];


		$q = "SELECT * FROM [e10_base_properties] WHERE [tableid] = 'e10.persons.persons' AND [recid] = %i";
		$docProperties = $this->table->db()->query($q, $this->recData['person']);
		foreach ($docProperties as $p)
			$this->data['ISDOC']['person'][$p['group']][$p['property']] = $p['valueString'];

		if ($this->recData['docType'] == 'invni')
		{
			$tmpOwner = $this->data['owner'];
			$tmpISDOCOwner = $this->data['ISDOC']['owner'];
			unset ($this->data['owner']);
			unset ($this->data['ISDOC']['owner']);
			$this->data['owner'] = $this->data['person'];
			$this->data['ISDOC']['owner'] = $this->data['ISDOC']['person'];
			unset ($this->data['person']);
			unset ($this->data['ISDOC']['person']);
			$this->data['person'] = $tmpOwner;
			$this->data['ISDOC']['person'] = $tmpISDOCOwner;
		}

		$bankAccount = $this->data['myBankAccount']['bankAccount'] ?? NULL;
		if ($bankAccount)
		{
			if ($this->recData['docType'] == 'invni')
				$bankAccount = $this->recData['bankAccount'];
			$separatorPos = strrpos($bankAccount, "/");
			if ($separatorPos === false)
			{
				$bankID = $bankAccount;
				$bankCode = '';
			}
			else
			{
				$bankID = substr ($bankAccount, 0, $separatorPos);
				$bankCode = substr ($bankAccount, $separatorPos+1);
			}
			$this->data['ISDOC']['bankID'] = $bankID;
			$this->data['ISDOC']['bankCode'] = $bankCode;
			$this->data['ISDOC']['bankName'] = self::$czBanks[$bankCode]['name'];
			$this->data['ISDOC']['bankSWIFT'] = self::$czBanks[$bankCode]['SWIFT'];
			$this->data['ISDOC']['bankIBAN'] = "";
		}
	}

	public function saveReportAs ()
	{
		$data = $this->renderTemplate ($this->reportTemplate, $this->saveAs);

		$fn = utils::tmpFileName ('isdoc');
		file_put_contents($fn, $data);
		$this->fullFileName = $fn;
		$this->saveFileName = $this->saveAsFileName ($this->saveAs);
		$this->mimeType = 'application/xml';
	}

	public function saveAsFileName ($type)
	{
		$fn = $this->data['documentName'].'-';
		$fn .= $this->recData['docNumber'].'.isdoc';
		return $fn;
	}
}
