<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Str;

/**
 * class MembersFeeInvoicingEngine
 */
class MembersFeeInvoicingEngine extends \Shipard\Base\Utility
{
	var $periodNdx = 1;
	var $periodCfg = NULL;
	var $entryKindCfg;
	var $periodBegin;
	var $periodEnd;
	var $periodHalf;

	var $sociPeriod = 'AY1';

	var $needMemberInWorkorder = 1;

	var $invDbCounterNdx = 0;

	var $invItemNdx = 0;
	var $invItemRecData = NULL;
	var $invItemPrice = 0;

	var $tagMembersNdx = 0;
	var $tagMembersRecData = NULL;

	var $thisMonth = 0;

	var $forPrint = 0;

	var $workOrderRecData = NULL;

	var $invHead = [];
	var $invRows = [];

	var $cntCreatedInvoices = 0;



	/** @var \e10pro\soci\TableEntries */
	var $tableEntries;
	/** @var \E10Doc\Core\TableHeads */
	var $tableHeads;
	/** @var \E10Doc\Core\TableRows */
	var $tableRows;


	var $personFullName = '';
	var $woTitle = '';

	public function init()
	{
		$this->tableEntries = $this->app()->table('e10pro.soci.entries');
		$this->tableHeads = $this->app()->table('e10doc.core.heads');
		$this->tableRows = $this->app()->table('e10doc.core.rows');

		$this->invDbCounterNdx = $this->app->cfgItem('options.e10-pro-soci.dbCounterInvoicesMembersFee');

		$this->invItemNdx = intval($this->app->cfgItem('options.e10-pro-soci.itemInvoicesMembersFee'));
		$this->invItemRecData = $this->app()->loadItem($this->invItemNdx, 'e10.witems.items');
		if ($this->invItemRecData)
		$this->invItemPrice = $this->invItemRecData['priceSellTotal'];

		$this->tagMembersNdx = intval($this->app->cfgItem('options.e10-pro-soci.tagMembers'));
		$this->tagMembersRecData = $this->app()->loadItem($this->tagMembersNdx, 'e10.base.clsfitems');

		$today = Utils::today();
		$this->thisMonth = intval($today->format('m'));

		$this->periodCfg = $this->app()->cfgItem('e10pro.soci.periods.'.$this->periodNdx, NULL);

		$this->periodBegin = Utils::createDateTime($this->periodCfg['dateBegin']);
		$this->periodEnd = Utils::createDateTime($this->periodCfg['dateEnd']);

		$this->periodHalf = Utils::createDateTime($this->periodCfg['dateHalf']);
	}

	protected function createOneInvoice($personNdx)
	{
		$workOrderNdx = 0;
		$this->woTitle = '';

		if ($this->needMemberInWorkorder)
		{
      $existInWO = $this->db()->query('SELECT woPersons.*, wo.title AS woTitle',
																			' FROM [e10mnf_core_workOrdersPersons] AS woPersons',
																			' LEFT JOIN [e10mnf_core_workOrders] AS wo ON woPersons.workOrder = wo.ndx',
																			' WHERE 1',
                                      ' AND wo.[usersPeriod] = %s', $this->sociPeriod,
                                      ' AND woPersons.person = %i', $personNdx)->fetch();
			if ($existInWO)
			{
				$workOrderNdx = $existInWO['workOrder'];
				$this->woTitle = $existInWO['woTitle'];
			}

			if (!$workOrderNdx)
			{
				return;
			}
		}

		$invoiceExist = $this->invoiceExist($personNdx, $workOrderNdx);
		if ($invoiceExist !== NULL)
		{
			//echo "### Invoice exist!".json_encode($invoiceExist)."\n";
			return;
		}

		$this->createHead($personNdx, $workOrderNdx);

		$r = [];
		$this->tableRows->checkNewRec($r);

		$r['item'] = $this->invItemNdx;
		$r['text'] =  'Členský příspěvek '.$this->periodCfg['fn'];
		$r['quantity'] = 1;

		if ($this->invItemRecData['itemKind'] == 2)
			$r['operation'] = 1099998; // acc item
		else
			$r['operation'] = 1010001; // service

		$r['priceItem'] = $this->invItemPrice;

		$this->invRows[] = $r;

		$this->saveInvoice();

		$this->cntCreatedInvoices++;
	}

	function createHead ($personNdx, $workOrderNdx)
	{
		$this->invHead = ['docType' => 'invno'];
		$this->tableHeads->checkNewRec($this->invHead);

		$this->invHead ['docState'] = 4000;
		$this->invHead ['docStateMain'] = 2;

		$this->invHead ['docType'] = 'invno';
		$this->invHead ['dbCounter'] = $this->invDbCounterNdx;
		$this->invHead ['datePeriodBegin'] = $this->periodBegin;
		$this->invHead ['datePeriodEnd'] = $this->periodEnd;
		$this->invHead ['person'] = $personNdx;

		$this->invHead ['workOrder'] = $workOrderNdx;

		$this->invHead ['dateIssue'] = Utils::today();
		$this->invHead ['dateTax'] = Utils::today();
		$this->invHead ['dateAccounting'] = Utils::today();
		$this->invHead ['dateDue'] = Utils::createDateTime($this->invHead ['dateAccounting']);
		$this->invHead ['dateDue']->add (new \DateInterval('P30D'));

		$this->invHead ['paymentMethod'] = '0';
		$this->invHead ['roundMethod'] = intval($this->app->cfgItem ('options.e10doc-sale.roundInvoice', 0));
		$this->invHead ['author'] = intval($this->app->cfgItem ('options.e10doc-sale.author', 0));

		$docTitle = 'Členský příspěvek '.$this->periodCfg['fn'];
		$docTitle .= ': '.$this->personFullName;
		//if ($this->woTitle !== '')
		//	$docTitle .= ', '.$this->woTitle;

		$this->invHead ['title'] = Str::upToLen($docTitle, 120);

		$this->invRows = [];
	}

	function saveInvoice ()
	{
		$docNdx = $this->tableHeads->dbInsertRec ($this->invHead);
		$this->invHead['ndx'] = $docNdx;

		$f = $this->tableHeads->getTableForm ('edit', $docNdx);

		forEach ($this->invRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);

		$f->checkAfterSave();
		$this->tableHeads->checkDocumentState ($f->recData);
		$this->tableHeads->dbUpdateRec ($f->recData);
		$this->tableHeads->checkAfterSave2 ($f->recData);
		$this->tableHeads->docsLog($f->recData['ndx']);
	}

	protected function invoiceExist($personNdx, $workOrderNdx)
	{
		$q = [];
		array_push($q, 'SELECT heads.*');
		array_push($q, ' FROM e10doc_core_heads AS heads');
		array_push($q, ' WHERE 1');
		if ($workOrderNdx)
			array_push($q, ' AND workOrder = %i', $workOrderNdx);
		array_push($q, ' AND person = %i', $personNdx);

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
			return $exist->toArray();

		return NULL;
	}

	protected function balanceInfo ($item)
	{
		$bi = new \e10doc\balance\BalanceDocumentInfo($this->app());
		$bi->setDocRecData ($item);
		$bi->run ();

		if (!$bi->valid)
			return;

    $balanceInfo = [];

		$line = [];
		$line[] = ['text' => utils::datef($item['dateDue']), 'icon' => 'system/iconStar'];

		if ($bi->restAmount < -1.0)
		{
			$balanceInfo['text'] = 'Přeplatek: '.Utils::nf(- $bi->restAmount, 2);
      $balanceInfo['icon'] = 'system/iconCheck';
      $balanceInfo['class'] = 'e10-warning1';
		}
		elseif ($bi->restAmount < 1.0)
		{
			$balanceInfo['text'] = 'Uhrazeno';
			if (!$this->forPrint && isset($bi->lastPayment['date']) && !Utils::dateIsBlank($bi->lastPayment['date']))
				$balanceInfo['suffix'] = Utils::datef($bi->lastPayment['date'], '%S');
      $balanceInfo['icon'] = 'system/iconCheck';
      $balanceInfo['class'] = 'e10-bg-t1';
		}
		else
    {
			if ($bi->restAmount == $item['toPay'])
			{
        if ($bi->daysOver > 0)
        {
          $balanceInfo['text'] = 'NEUHRAZENO';
          $balanceInfo['icon'] = 'system/iconWarning';
          $balanceInfo['class'] = 'e10-warning3';
        }
        else
        {
          $balanceInfo['text'] = 'Uhradit do: '.Utils::datef($item['dateDue'], '%S');
          $balanceInfo['icon'] = 'system/iconCheck';
          $balanceInfo['class'] = 'e10-bg-t4';
        }
			}
			else
			{
        $balanceInfo['text'] = 'NEDOPLATEK: '.Utils::nf($bi->restAmount, 2);
        $balanceInfo['icon'] = 'system/iconCheck';
        $balanceInfo['class'] = 'e10-warning1';
			}
    }

    return $balanceInfo;
	}

	public function generateAll()
	{
		if (!$this->invItemPrice)
			return;

		$q = [];
		array_push($q, 'SELECT [persons].*');
		array_push($q, ' FROM [e10_persons_persons] AS [persons]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [persons].[docState] = %i', 4000);
		array_push ($q, 'AND EXISTS (SELECT ndx FROM [e10_base_clsf] WHERE persons.ndx = e10_base_clsf.recid AND ',
			' e10_base_clsf.tableid = %s', 'e10.persons.persons', ' AND e10_base_clsf.group = %s', $this->tagMembersRecData['group'],
			' AND e10_base_clsf.clsfItem = %i) ', $this->tagMembersNdx);

		array_push($q, ' ORDER BY persons.lastName, persons.firstName, persons.ndx');

		$cnt = 0;
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->personFullName = $r['fullName'];

			if ($this->app()->debug)
				echo "# ".$r['fullName']."\n";

			$this->createOneInvoice($r['ndx']);

			$cnt++;

			if ($this->app()->debug && $this->cntCreatedInvoices > 3)
				break;
		}
	}
}
