<?php

namespace lib\ros\cz;

use \e10\utils, \e10\utility, \e10\json, \e10\Application;

use Ondrejnov\EET\Dispatcher;
use Ondrejnov\EET\Receipt;


/**
 * Class RosEngine
 * @package lib\ros\cz
 */
class RosEngine extends Utility
{
	var $rosReg = NULL;
	var $rosType = NULL;
	var $rosMode = 0;

	var $wsdlPath;
	var $certPath;

	var $sendIndex = 0;
	var $cashBox = NULL;
	var $receiptDataItem;
	var $pkp = '';
	var $bkp = '';

	var $resultData = FALSE;
	var $error = FALSE;

	var $msgErrors = [
			-1 => 'Docasna technicka chyba zpracovani – odeslete prosim datovou zpravu pozdeji',
			2 => 'Kodovani XML neni platne',
			3 => 'XML zprava nevyhovela kontrole XML schematu',
			4 => 'Neplatny podpis SOAP zpravy',
			5 => 'Neplatny kontrolni bezpecnostni kod poplatnika (BKP)',
			6 => 'DIC poplatnika ma chybnou strukturu',
			7 => 'Datova zprava je prilis velka',
			8 => 'Datova zprava nebyla zpracovana kvuli technicke chybe nebo chybe dat',
	];

	var $msgWarnings = [
			1 => 'DIC poplatnika v datove zprave se neshoduje s DIC v certifikatu',
			2 => 'Chybny format DIC poverujiciho poplatnika',
			3 => 'Chybna hodnota PKP',
			4 => 'Datum a cas prijeti trzby je novejsi nez datum a cas prijeti zpravy',
			5 => 'Datum a cas prijeti trzby je vyrazne v minulosti'
	];


	public function init ($rosRegNdx)
	{
		$this->rosReg = $this->app()->cfgItem('terminals.ros.regs.'.$rosRegNdx, NULL);
		$this->rosType = $this->app()->cfgItem('terminals.ros.types.cz');
		if (isset($this->rosReg['rosMode']))
			$this->rosMode = $this->rosReg['rosMode'];

		$isDemo = ($this->app()->model()->module ('demo.core') !== FALSE);
		$dsMode = $this->app()->cfgItem ('dsMode', 0);
		if (($dsMode != Application::dsmProduction && $this->rosMode === 0) || $isDemo)
			$this->rosMode = 2; // non production data source or demo

		if ($this->rosMode == 2)
		{ // demo ==> playground
			$this->wsdlPath = __SHPD_ROOT_DIR__ . '/src/_deprecated/lib/' . 'ros/cz/res/playground/Service.wsdl';
			$this->certPath = __SHPD_ROOT_DIR__ . '/src/_deprecated/lib/' . 'ros/cz/res/playground/cert';
		}
		else
		{
			$this->wsdlPath = __SHPD_ROOT_DIR__ . '/src/_deprecated/lib/' . 'ros/cz/res/production/Service.wsdl';
			$this->certPath = __APP_DIR__ . '/res/ros/' . $this->rosReg['certPath'];
		}
	}

	function prepareData (&$recData)
	{
		$this->cashBox = $this->app()->cfgItem ('e10doc.cashBoxes.'.$recData['cashBox'], NULL);

		$uuid = utils::guidv4();
		$vatId = $this->rosReg['vatIdPrimary'];

		$dataItem = [
			'uuid_zpravy' => $uuid,
			'dic_popl' => $vatId,
			'porad_cis' => $recData['docNumber'],
			'dat_trzby' => $recData['activateTimeLast'],
			'zakl_dan1' => 0.0, 'dan1' => 0.0,
			'zakl_dan2' => 0.0, 'dan2' => 0.0,
			'zakl_dan3' => 0.0, 'dan3' => 0.0,
			'zakl_nepodl_dph' => 0.0, 'celk_trzba' => 0.0,
			'prvni_zaslani' => TRUE,
			'id_provoz' => '1', 'id_pokl' => '1',
		];

		if ($this->cashBox)
			$dataItem['id_pokl'] = $this->cashBox['id'];

		if ($this->rosReg['placeId'] !== '')
			$dataItem['id_provoz'] = $this->rosReg['placeId'];

		$this->loadOldRows ($recData, $dataItem);

		$canceled = FALSE;
		if ($recData['docState'] == 4100)
			$canceled = TRUE;

		if (isset($this->rosType['paymentMethods']) && !in_array($recData['paymentMethod'], $this->rosType['paymentMethods']))
			$canceled = TRUE;

		if ($recData['docType'] === 'cash' && $recData['cashBoxDir'] != 1)
			$canceled = TRUE;

		if (!$canceled)
		{ // not canceled
			if ($recData ['taxPayer'])
			{
				$q = "SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER BY [taxPercents] DESC, [taxCode]";
				$rows = $this->db()->query($q, $recData ['ndx']);
				foreach ($rows as $r)
				{
					if ($r['taxCode'] == 120)
					{ // Tuzemsko/Výstup/Základní
						$dataItem['zakl_dan1'] += $r['sumBaseHc'];
						$dataItem['dan1'] += $r['sumTaxHc'];
					} elseif ($r['taxCode'] == 310)
					{ // Tuzemsko/Výstup/První snížená
						$dataItem['zakl_dan2'] += $r['sumBaseHc'];
						$dataItem['dan2'] += $r['sumTaxHc'];
					} elseif ($r['taxCode'] == 123)
					{ // Tuzemsko/Výstup/Osvobozeno
						$dataItem['zakl_dan3'] += $r['sumBaseHc'];
						$dataItem['dan3'] += $r['sumTaxHc'];
					} else
					{
						$dataItem['zakl_nepodl_dph'] += $r['sumBaseHc'];
					}
				}
			}

			$dataItem['celk_trzba'] += $recData['toPayHc'];
		}

		$this->receiptDataItem = $dataItem;
	}

	function sendNeeded ()
	{
		if ($this->receiptDataItem['celk_trzba'] == 0.0)
			return FALSE;

		return TRUE;
	}

	function loadOldRows ($recData, &$dataItem)
	{
		$q[] = 'SELECT journal.* ';
		array_push($q, ' FROM [e10doc_ros_journal] as journal');
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$this->sendIndex++;

			if ($r['state'] == 2)
				continue;

			$oldDataItem = json_decode($r['dataSent'], TRUE);

			$dataItem['zakl_dan1'] -= $oldDataItem['zakl_dan1'];
			$dataItem['dan1'] -= $oldDataItem['dan1'];

			$dataItem['zakl_dan2'] -= $oldDataItem['zakl_dan2'];
			$dataItem['dan2'] -= $oldDataItem['dan2'];

			$dataItem['zakl_dan3'] -= $oldDataItem['zakl_dan3'];
			$dataItem['dan3'] -= $oldDataItem['dan3'];

			$dataItem['zakl_nepodl_dph'] -= $oldDataItem['zakl_nepodl_dph'];
			$dataItem['celk_trzba'] -= $oldDataItem['celk_trzba'];
		}

		if ($this->sendIndex)
			$dataItem['prvni_zaslani'] = FALSE;
	}

	function setResultData ($res)
	{
		$this->resultData = [
				'uuid' => (isset($res->Hlavicka)) ? $res->Hlavicka->uuid_zpravy : $this->receiptDataItem['uuid'],
				'pkp' => $this->pkp,
				'bkp' => $this->bkp,
				'fik' => NULL,
		];

		if (isset($res->Potvrzeni->fik))
			$this->resultData['fik'] = $res->Potvrzeni->fik;

		if (isset($res->Hlavicka->bkp))
			$this->resultData['bkp'] = $res->Hlavicka->bkp;

		if (isset($res->Hlavicka->dat_prij))
			$this->resultData['datum_prijeti'] = $res->Hlavicka->dat_prij;
		elseif (isset($res->Hlavicka->dat_odmit))
			$this->resultData['datum_prijeti'] = $res->Hlavicka->dat_odmit;

		if (isset($res->Varovani))
		{
			$this->resultData['warnings'] = [];
			$this->resultData['warnings'][] = [
					'code' => $res->Varovani->kod_varov,
					'msg' => (isset($this->msgWarnings[$res->Varovani->kod_varov])) ? $this->msgWarnings[$res->Varovani->kod_varov] : '#'.$res->Varovani->kod_varov
			];
		}

		if (isset($res->Chyba))
		{
			$this->resultData['errors'] = [];
			$this->resultData['errors'][] = [
					'code' => $res->Chyba->kod,
					'msg' => (isset($this->msgErrors[$res->Chyba->kod])) ? $this->msgErrors[$res->Chyba->kod] : '#'.$res->Chyba->kod
			];
			$this->error = TRUE;
		}
	}

	public function test()
	{
		$this->init(1);

		$r = new Receipt();
		$r->uuid_zpravy = utils::guidv4();
		$r->dic_popl = 'CZ72080043';
		$r->id_provoz = '181';
		$r->id_pokl = '1';
		$r->porad_cis = '1';
		$r->dat_trzby = new \DateTime();
		$r->celk_trzba = 1000;

		$response = $this->sendReceipt($r);
		$this->setResultData ($response);
	}

	public function sendReceipt ($receipt, $check = FALSE)
	{
		$dispatcher = new Dispatcher($this->wsdlPath, $this->certPath.'.pem', $this->certPath.'.crt');
		$data = $dispatcher->prepareData($receipt, $check);
		$this->pkp = base64_encode($data['KontrolniKody']['pkp']['_']);
		$this->bkp = $data['KontrolniKody']['bkp']['_'];

		$response = NULL;
		try
		{
			$response = $dispatcher->getSoapClient()->OdeslaniTrzby($data);
		}
		catch (\Exception $e)
		{
			print_r ($response);
		}

		return $response;
	}

	public function send(&$recData)
	{
		$r = new Receipt();
		$r->uuid_zpravy = $this->receiptDataItem['uuid_zpravy'];
		$r->dic_popl = $this->receiptDataItem['dic_popl'];
		$r->id_provoz = $this->receiptDataItem['id_provoz'];
		$r->id_pokl = $this->receiptDataItem['id_pokl'];
		$r->porad_cis = $this->receiptDataItem['porad_cis'];
		$r->dat_trzby = $this->receiptDataItem['dat_trzby'];
		$r->celk_trzba = $this->receiptDataItem['celk_trzba'];
		$r->zakl_dan1 = $this->receiptDataItem['zakl_dan1'];
		$r->dan1 = $this->receiptDataItem['dan1'];
		$r->zakl_dan2 = $this->receiptDataItem['zakl_dan2'];
		$r->dan2 = $this->receiptDataItem['dan2'];
		$r->zakl_dan3 = $this->receiptDataItem['zakl_dan3'];
		$r->dan3 = $this->receiptDataItem['dan3'];
		$r->zakl_nepodl_dph = $this->receiptDataItem['zakl_nepodl_dph'];
		$r->prvni_zaslani = $this->receiptDataItem['prvni_zaslani'];

		$response = $this->sendReceipt($r, $this->rosMode === 1);
		$this->setResultData ($response);
	}

	function appendToJournal (&$recData)
	{
		$item = [
				'document' => $recData['ndx'],
				'msgId' => $this->receiptDataItem['uuid_zpravy'],
				'rosMode' => $this->rosMode,
				'state' => 1, 'sendIndex' => $this->sendIndex,
				'dataSent' => json::lint($this->receiptDataItem),
				'dateSent' => new \DateTime(),
				'amount' => $this->receiptDataItem['celk_trzba'],
				'placeId1' => $this->receiptDataItem['id_provoz'],
				'placeId2' => $this->receiptDataItem['id_pokl'],
				'datePay' => $this->receiptDataItem['dat_trzby'],
		];

		if ($this->resultData)
		{
			$item['dataReceived'] = json::lint ($this->resultData);
			$item['resultCode1'] = $this->resultData['fik'];
			$item['resultCode2'] = $this->resultData['bkp'];

			$item['state'] = 1;
			$recData['rosState'] = 1;

			if (isset($this->resultData['warnings']))
				$item['state'] = 3;
			if ($this->error || !$item['resultCode1'] || $item['resultCode1'] == '')
			{
				$item['state'] = 2;
				$recData['rosState'] = 3;
			}
		}
		else
		{
			$recData['rosState'] = 3;
			$item['state'] = 2;
		}


		$this->db()->query('INSERT INTO [e10doc_ros_journal]', $item);
		$newItemNdx = intval ($this->db()->getInsertId ());

		$recData['rosRecord'] = $newItemNdx;

		$this->db()->query(
				'UPDATE [e10doc_core_heads] SET [rosState] = %i, ', $recData['rosState'], '[rosRecord] = %i, ', $recData['rosRecord'],
				'[rosReg] = %i', $this->rosReg['ndx'],
				' WHERE ndx = %i', $recData['ndx']
				);
	}

	public function doDocument ($rosRegNdx, &$recData)
	{
		$this->init($rosRegNdx);

		$this->prepareData($recData);

		if (!$this->sendIndex  && !$this->testRosDuty($recData))
			return;

		if (!$this->sendNeeded())
			return;

		$recData['rosReg'] = $rosRegNdx;

		$this->send($recData);
		$this->appendToJournal($recData);
	}

	function testRosDuty($recData)
	{
		if ($recData['docType'] !== 'cash')
			return TRUE;

		$q[] = 'SELECT COUNT(*) as cnt FROM [e10doc_core_rows]';
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' AND [operation] IN %in', $this->rosType['cashInOperations']);
		$cnt = $this->db()->query($q)->fetch();
		if ($cnt['cnt'])
			return TRUE;

		return FALSE;
	}

	public function documentDetail ($recData)
	{
		$rosModes = $this->app()->cfgItem('terminals.ros.modes');

		$q[] = 'SELECT journal.* ';
		array_push($q, ' FROM [e10doc_ros_journal] as journal');
		array_push($q, ' WHERE [document] = %i', $recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$data = [];
		$totalAmount = 0.0;
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray();
			$item['dateSent'] = [
				[
					'text' => utils::datef ($r['dateSent'], '%d'),
					'suffix' => $r['dateSent']->format('H:i:s'),
					'docAction' => 'show', 'table' => 'e10doc.ros.journal', 'pk' => $r['ndx'], 'class' => 'block'
				]
			];

			$rosMode = $rosModes[$r['rosMode']];
			if ($r['rosMode'] != 0)
				$item['dateSent'][] = ['text' => $rosMode['sc'], 'icon' => $rosMode['icon'], 'class' => 'label '.$rosMode['lc']];

			if ($r['state'] === 1 && $r['resultCode1'] && $r['resultCode1'] !== '')
				$item['_options']['cellClasses']['idx'] = 'e10-row-plus';
			if ($r['state'] !== 1 || !$r['resultCode1'] || $r['resultCode1'] === '')
				$item['_options']['cellClasses']['idx'] = 'e10-warning3';

			if ($r['amount'] < 0.0)
				$item['_options']['cellClasses']['amount'] = 'e10-error';

			$data[] = $item;

			if ($r['state'] === 1 && $r['resultCode1'] && $r['resultCode1'] !== '')
				$totalAmount += $item['amount'];
		}

		$idx = count($data);
		foreach ($data as &$item)
		{
			$item['idx'] = $idx.'.';
			$idx--;
		}

		if (!count($data))
		{
			$content = [
					'pane' => 'e10-pane e10-pane-table', 'type' => 'line',
					'line' => ['icon' => 'icon-microchip', 'text' => 'Doklad '.$recData['docNumber'].' nemá záznamy pro EET', 'class' => 'h2']
			];
			return $content;
		}

		$h = [
				'idx' => '#', 'dateSent' => 'Datum a čas', 'amount' => ' Tržba',
				'msgId' => 'Číslo transakce', 'resultCode1' => 'FIK', 'resultCode2' => 'BKP',
		];
		$t = [
			['icon' => 'icon-microchip', 'text' => 'Záznamy pro EET'],
			['icon' => 'icon-upload', 'class' => 'pull-right', 'title' => 'Výsledná odeslaná částka', 'text' => utils::nf($totalAmount, 2)]
		];

		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'table' => $data, 'header' => $h, 'title' => $t];

		return $content;
	}

	public function loadReportData($docFormReport)
	{
		$q[] = 'SELECT journal.* ';
		array_push($q, ' FROM [e10doc_ros_journal] as journal');
		array_push($q, ' WHERE [document] = %i', $docFormReport->recData['ndx']);
		array_push($q, ' ORDER BY [ndx] DESC');

		$r = $this->db()->query ($q)->fetch($q);
		if (!$r)
			return;

		$sentDataItem = json_decode($r['dataReceived'], TRUE);

		$docFormReport->data ['flags']['ros'] = 1;
		$docFormReport->data ['flags']['rosCZ'] = 1;

		$docFormReport->data ['ros'] = [
				'type' => 'v běžném režimu',
				'fik' => $r['resultCode1'],
				'bkp' => $r['resultCode2'],
				'pkp' => $sentDataItem['pkp'],
				'datePay' => $r['datePay']->format ('d.m.Y H:i:s'),
				'placeId1' => $r['placeId1'],
				'placeId2' => $r['placeId2'],
				'placeId1Txt' => 'Provozovna: '.$r['placeId1'],
				'placeId2Txt' => 'Pokladna: '.$r['placeId2'],
		];
	}
}
