<?php


namespace E10Doc\Balance;
use \e10doc\core\libs\E10Utils, \e10\utils;
use \Shipard\Utils\World;
use e10doc\core\ShortPaymentDescriptor;
use \e10\base\libs\UtilsBase;

/**
 * class RequestForPayment
 */
class RequestForPayment extends \e10doc\core\libs\reports\DocReportBase
{
	public $fiscalYear;
	var $currencies;
	var $tablePersons;
	var $tableDocHeads;

	var $messageMoney = 0.0;
	var $messageCurrency = '';

	function init ()
	{
		parent::init();
		$this->setReportId('e10doc.balance.requestForPayment');
	}

	public function checkDocumentInfo (&$documentInfo)
	{
		$documentInfo['messageDocKind'] = 'outbox-other-demandForPay';
		$documentInfo['money'] = $this->messageMoney;
		$documentInfo['currency'] = $this->messageCurrency;
	}

	public function loadData ()
	{
		$this->sendReportNdx = 2100;

		$this->fiscalYear = E10Utils::todayFiscalYear($this->app);
		$this->tablePersons = $this->app->table('e10.persons.persons');
		$this->tableDocHeads = $this->app->table('e10doc.core.heads');
		$this->currencies = $this->app->cfgItem ('e10.base.currencies');

		parent::loadData();

		// -- person
		$this->loadData_MainPerson('person', $this->recData['ndx']);

		// -- owner
		$this->loadData_DocumentOwner ();

		// -- author
		$authorNdx = $this->app->user()->data ('id');
		$this->loadData_Author($authorNdx);

		// -- default bank account
		$myBANdx = intval($this->app()->cfgItem('options.e10doc-sale.myBankAccount', 0));
		if ($myBANdx)
		{
			$ba = $this->app()->cfgItem('e10doc.bankAccounts.'.$myBANdx, NULL);
			if ($ba)
			{
				$this->data ['payment']['baCfg'] = $ba;
				$this->data ['payment']['bankAccount'] = $ba['bankAccount'];
			}
		}

		$this->loadData_Documents ();
		$this->createPaymentInfo();
	}

	public function loadData_Documents ()
	{
		$today = utils::today();
		$this->data ['today'] = utils::datef($today, '%d');
		$dueDate = E10Utils::balanceOverDueDate ($this->app);

		$q[] = 'SELECT heads.docNumber, heads.dateDue, heads.dateDue as docDateDue, heads.ndx as docNdx, heads.docType as docType, heads.title as docTitle,';
		array_push ($q, ' heads.myBankAccount,');
		array_push ($q, ' journal.currency as currency, journal.request as totalRequest, journal.symbol1, journal.symbol2, journal.[date] as dateDue,');
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1 AND s.fiscalYear = %i) AS payments, ', $this->fiscalYear);
		array_push ($q, ' (SELECT SUM(payment) FROM `e10doc_balance_journal` AS s WHERE s.pairId = journal.pairId AND s.side = 1) AS totalPayment');

		array_push ($q, ' FROM [e10doc_balance_journal] AS journal');
		array_push ($q, ' LEFT JOIN [e10doc_core_heads] as heads ON journal.docHead = heads.ndx');
//		array_push ($q, ' LEFT JOIN [e10_persons_persons] as persons ON journal.person = persons.ndx');
		array_push ($q, ' WHERE journal.side = 0', ' AND journal.[date] < %d', $dueDate);
		array_push ($q, ' AND journal.fiscalYear = %i', $this->fiscalYear, ' AND journal.person = %i', $this->recData ['ndx']);
		array_push ($q, ' AND EXISTS (',
			' SELECT SUM(q.request) as sumRequest, SUM(q.payment) as sumPayment FROM `e10doc_balance_journal` as q',
			' WHERE q.[type] = 1000 AND q.pairId = journal.pairId AND q.fiscalYear = %i', $this->fiscalYear,
			' GROUP BY q.[pairId] HAVING sumPayment < sumRequest',
			')');
		array_push ($q, ' ORDER BY journal.[date]');

		$rows = $this->app->db()->query($q);

		$totals = [];
		$data = [];
		foreach ($rows as $r)
		{
			$overDueDays = utils::dateDiff ($r['dateDue'], $today);
			$item = [
				'docNdx' => $r['docNdx'],
				'docNumber' => $r['docNumber'], 'docType' => $r['docType'],
				'request' => $r['totalRequest'] - $r['payments'] + $r['totalPayment'], 'curr' => $this->currencies[$r['currency']]['shortcut'],
				'dateDue' => $r['dateDue'], 's1' => $r['symbol1'], 's2' => $r['symbol2'], 'docTitle' => $r['docTitle'], 'payment' => 0,
				'_options' => ['class' => E10Utils::balanceOverDueClass ($this->app, $overDueDays)]
			];

			$ba = $this->app()->cfgItem('e10doc.bankAccounts.'.$r['myBankAccount'], NULL);
			if ($ba)
				$item['ba'] = $ba['bankAccount'];
			if (!isset($this->data ['payment']['bankAccount']))
			{
				$this->data ['payment']['baCfg'] = $ba;
				$this->data ['payment']['bankAccount'] = $ba['bankAccount'];
			}

			if ($r['totalPayment'])
			{
				$item['payment'] = $r['totalPayment'];
				$item['restAmount'] = round($r['totalRequest'] - $r['payments'], 2);
			}
			else
				$item['restAmount'] = $r['totalRequest'];

			$cid = $r['currency'];
			if (isset($totals[$cid]))
				$totals[$cid] += $item['restAmount'];
			else
				$totals[$cid] = $item['restAmount'];


			$item['print'] = ['request' => utils::nf($item['request'], 2),
				'payment' => utils::nf($item['payment'], 2), 'restAmount' => utils::nf($item['restAmount'], 2)];

			$data [] = $item;

		}

		$this->data ['rows'] = $data;
		$this->data ['totals'] = [];

		foreach ($totals as $curr => $rest)
		{
			$sum = [
				'restAmount' => $rest, 'currency' => $curr, 'curr' => $this->currencies[$curr]['shortcut'],
				'print' => ['restAmount' => utils::nf($rest, 2)]
				];

			if (count($totals) === 1)
			{
				$this->data ['payment']['restAmount'] = $rest;
				$this->data ['payment']['currency'] = $curr;
			}

			$this->data ['totals'][] = $sum;
		}

		if (count($this->data ['totals']))
		{
			$this->messageMoney = $this->data ['totals'][0]['restAmount'];
			$this->messageCurrency = $this->data ['totals'][0]['currency'];
		}
	}

	protected function createPaymentInfo()
	{
		if (!isset($this->data ['payment']['restAmount']))
			return;

		$symbol1 = '';
		$symbol2 = '';

		if (count($this->data ['rows']) === 1)
		{
			$symbol1 = $this->data ['rows'][0]['s1'];
			$symbol2 = $this->data ['rows'][0]['s2'];
		}
		else
		{
			$symbol1 = '991'.$this->recData['ndx'];
		}

		$spayd = new ShortPaymentDescriptor($this->app);
		$spayd->setBankAccount ('CZ', $this->data['payment']['baCfg']['bankAccount'], $this->data['payment']['baCfg']['iban'], $this->data['payment']['baCfg']['swift']);
		$spayd->setAmount ($this->data ['payment']['restAmount'], $this->data ['payment']['currency']);

		$spayd->setPaymentSymbols ($symbol1, $symbol2);

		$spayd->createString();
		$spayd->createQRCode();

		$this->data ['spayd'] = $spayd;
	}

	public function addMessageAttachments(\Shipard\Report\MailMessage $msg)
	{
		$cnt = 0;
		foreach ($this->data ['rows'] as $r)
		{
			$docNdx = $r['docNdx'];
			$docNumber = $r['docNumber'];

			if ($r['docType'] === 'cmnbkp')
			{
				$originalDoc = $this->app->db()->query ('SELECT * FROM [e10doc_core_heads] WHERE [docType] = %s', 'invno',
					'AND symbol1 = %s', $r['s1'], ' AND symbol2 = %s', $r['s2'], ' AND person = %i', $this->recData['ndx'])->fetch();
				if ($originalDoc)
				{
					$docNdx = $originalDoc['ndx'];
					$docNumber = $originalDoc['docNumber'];
				}
			}

			$q = [];
			array_push($q, 'SELECT * FROM [wkf_core_issues]');
			array_push($q, ' WHERE 1');
			array_push($q, ' AND recNdx = %i', $docNdx);
			array_push($q, ' AND tableNdx = %i', 1078);
			array_push($q, ' ORDER BY ndx DESC');
			array_push($q, ' LIMIT 1');

			$outBoxRecs = $this->db()->query($q);
			foreach ($outBoxRecs as $or)
			{
				$attachments = UtilsBase::loadAttachments ($this->app(), [$or['ndx']], 'wkf.core.issues');
				if (isset($attachments[$or['ndx']]['images']))
				{
					$attIdx = 0;
					foreach ($attachments[$or['ndx']]['images'] as $a)
					{
						if (strtolower($a['filetype']) !== 'pdf')
							continue;

						$attFileName = __APP_DIR__.'/att/'.$a['path'].$a['filename'];
						$attName = $a['name'];
						if (!$attIdx)
							$attName = 'VF'.$docNumber;

						if (!str_ends_with($attName, '.pdf'))
							$attName .= '.pdf';

						$msg->addAttachment($attFileName, $attName, 'application/pdf');
						$attIdx++;
					}
				}
			}

			$cnt++;
			if ($cnt > 10)
				break;
		}
	}
}

