<?php

namespace e10mnf\core;

use \e10\utils;


/**
 * Class WorkOrderCard
 * @package e10mnf\core
 */
class WorkOrderCard extends \e10\DocumentCard
{
	var $dko = NULL;

	protected $linkedAttachments = [];
	protected $docTypes, $currencies, $balances, $usedNdxs;

	var $tablePersons;
	var $personRecData;
	var $tableDocsHeads;

	var $tableAddress;
	var $addresses;
	var $addressesAll;

	var $docType;

	var $invoices = [];
	var $invoicesAmounts = [];

	public function attachments ()
	{
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	function loadDataAddresses()
	{
		$this->addresses = [];
		$this->addressesAll = $this->tableAddress->loadAddresses($this->table, $this->recData['ndx'], FALSE);

		foreach ($this->addressesAll as $a)
		{
			if (!count($a))
				continue;
			$address = ['text' => $a['text'], 'class' => 'block'];

			$this->addresses[] = $address;
		}
	}

	function loadDataInvoices()
	{
		$q[] = 'SELECT [rows].document, [rows].workOrder, [rows].priceAll,';
		array_push($q, ' heads.docNumber, heads.docState AS docDocState, heads.docStateMain AS docDocStateMain,');
		array_push($q, ' heads.currency AS headCurrency, heads.dateAccounting AS headDateAccounting');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] AS heads ON rows.document = heads.ndx');
		array_push($q, ' WHERE [rows].workOrder = %i', $this->recData['ndx'], ' AND heads.docType = %s', 'invno');
		array_push($q, ' ORDER BY heads.dateAccounting, heads.docNumber');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$docNumber = $r['docNumber'];
			$hc = $r['headCurrency'];
			if (isset($this->invoices[$hc][$docNumber]))
				$this->invoices[$hc][$docNumber]['amount'] += $r['priceAll'];
			else
			{
				$this->invoices[$hc][$docNumber]['amount'] = $r['priceAll'];
				$this->invoices[$hc][$docNumber]['date'] = $r['headDateAccounting'];
				$this->invoices[$hc][$docNumber]['ndx'] = $r['document'];

				$docItem = ['docState' => $r['docDocState'], 'docStateMain' => $r['docDocStateMain']];
				$docDocState = $this->tableDocsHeads->getDocumentState ($docItem);
				$docDocStateClass = $this->tableDocsHeads->getDocumentStateInfo ($docDocState['states'], $docItem, 'styleClass');
				$this->invoices[$hc][$docNumber]['docStateClass'] = $docDocStateClass;
			}
			if (!isset($this->invoicesAmounts[$hc]))
				$this->invoicesAmounts[$hc] = 0.0;
			if ($r['docDocStateMain'] === 2 && $r['docDocState'] !== 4100)
				$this->invoicesAmounts[$hc] += $r['priceAll'];
		}
	}

	public function createContentHeader ()
	{

		$recData = $this->recData;


		$this->tablePersons = $this->app->table ('e10.persons.persons');
		$this->personRecData = $this->tablePersons->loadItem ($recData['customer']);
		$hdr = [];//$this->table->createPersonHeaderInfo ($this->personRecData, $recData);
		$hdr ['icon'] = $this->table->tableIcon ($recData);
		$hdr ['class'] = 'e10-pane-header '.$this->docStateClass();

		$docInfo [] = ['text' => $recData ['docNumber'], 'icon' => 'icon-file'];
		$hdr ['info'][] = ['class' => 'title', 'value' => $docInfo];

		if (isset ($this->recData ['ndx']))
		{
			$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');

		}
		else
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Nová zakázka'];
		}

		$this->addContent('header', ['type' => 'tiles', 'tiles' => [$hdr], 'class' => 'panes']);

		$this->header = $this->table->createHeader($this->recData, NULL);
	}

	public function createTitle ()
	{
		$title = ['text' => $this->recData ['docNumber'], 'suffix' => 'test123', 'icon' => $this->table->tableIcon($this->recData)];

		$this->addContent('title', ['type' => 'line', 'line' => $title]);

		if ($this->recData['customer'])
			$subTitle = [['icon' => $this->tablePersons->tableIcon ($this->personRecData), 'text' => $this->personRecData['fullName']]];

		if ($this->recData['title'] !== '')
			$subTitle[] = ['text' => $this->recData['title'], 'class' => 'e10-off block'];
		$this->addContent('subTitle', ['type' => 'line', 'line' => $subTitle]);
	}

	protected function docTitle ($r)
	{
		$docTitle = '';
		if ($r['title'] != '')
			$docTitle .= ': '.$r['title'];

		return $docTitle;
	}

	public function createContent_Rows ()
	{
		$q[] = 'SELECT [rows].[text], [rows].quantity, [rows].unit, ';
		array_push($q, ' [rows].priceItem, [rows].priceAll');
		array_push($q, ' FROM [e10mnf_core_workOrdersRows] AS [rows]');
		array_push($q, ' WHERE [rows].workOrder = %i', $this->recData ['ndx']);
		array_push($q, ' ORDER BY ndx');

		$cfgUnits = $this->app->cfgItem ('e10.witems.units');
		$rows = $this->table->db()->query($q);
		$list = [];
		$totalPriceAll = 0.0;
		forEach ($rows as $r)
		{
			$unit = (isset($cfgUnits[$r['unit']])) ? $cfgUnits[$r['unit']]['shortcut'] : '';
			$list[] = ['text' => $r['text'], 'quantity' => $r['quantity'], 'unit' => $unit, 'priceItem' => $r['priceItem'], 'priceAll' => $r['priceAll']];
			$totalPriceAll += $r['priceAll'];
		}

		if (count ($list))
		{
			$h = ['#' => '#', 'text' => 'Text řádku', 'quantity' => ' Množství', 'unit' => 'Jedn.', 'priceItem' => ' Cena/Jedn.', 'priceAll' => ' Cena celkem'];
			if (count ($list) > 1)
			{
				$list[] = ['text' => 'Celkem', 'priceAll' => $totalPriceAll, '_options' => ['class' => 'sum']];
			}

			return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'], 'header' => $h, 'table' => $list];
		}
		return FALSE;
	}

	public function createContent_Info ()
	{
		$h = ['txt' => ' Vlastnost', 'v' => 'Hodnota'];
		$t = [];

		//if ($this->dko['useDateIssue'])
		//	$t[] = ['txt' => $this->dko['labelDateIssue'], 'v' => utils::datef($this->recData['dateIssue'])];
		if ($this->dko['useRefId1'])
			$t[] = ['txt' => $this->dko['labelRefId1'], 'v' => $this->recData['refId1']];
		if ($this->dko['useRefId2'])
			$t[] = ['txt' => $this->dko['labelRefId2'], 'v' => $this->recData['refId2']];
		if ($this->dko['useDateContract'])
			$t[] = ['txt' => $this->dko['labelDateContract'], 'v' => utils::datef($this->recData['dateContract'])];

		if ($this->dko['useDateBegin'] && $this->dko['useDateDeadlineConfirmed'])
			$t[] = ['txt' => 'Termín realizace', 'v' => utils::datef($this->recData['dateBegin']).' - '.utils::datef($this->recData['dateDeadlineConfirmed'])];

		// -- addresses
		if (count($this->addresses))
		{
			$t [] = [
				'txt' => 'Adresa',
				'v' => $this->addresses,
			];
		}

		// -- invoices
		if ($this->dko['invoicesInDetail'] !== 0 && count($this->invoices))
		{
			foreach ($this->invoices as $currencyId => $currencyInvoices)
			{
				$currencyName = $this->currencies[$currencyId]['shortcut'];
				$invoicesTotal = $this->invoicesAmounts[$currencyId];

				$item = ['txt' => ['text' => 'Vyfakturováno', ]];
				$item ['v'] = [['text' => utils::nf($invoicesTotal, 2), 'suffix' => $currencyName]];

				if ($this->recData['currency'] === $currencyId)
				{
					$percents = 0;
					if ($this->recData['sumPrice'])
					{
						$percents = round($invoicesTotal / $this->recData['sumPrice'] * 100, 0);
						$item['txt']['suffix'] = $percents.' %';
					}

					if ($percents >= 100)
						$item['_options']['class'] = 'e10-row-plus';
					elseif ($percents >= 50)
						$item['_options']['class'] = 'e10-row-pause';
				}

				if ($this->dko['invoicesInDetail'] === 2)
				{
					foreach ($currencyInvoices as $docNumber => $invoice)
					{
						$item ['v'][] = [
							'text' => $docNumber, 'suffix' => utils::nf($invoice['amount'], 2),
							'prefix' => utils::datef($invoice['date']),
							'class' => 'pull-right label label-default e10-ds ' . $invoice['docStateClass'],
							'icon' => 'e10-docs-invoices-out',
							'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $invoice['ndx']
						];
					}
				}
				$t[] = $item;
			}
		}

		$this->addContent ('body',
			[
				'pane' => 'e10-pane e10-pane-top', 'type' => 'table',
				'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth dcInfo dcInfoB']
			]);
	}

	public function createContentBody ()
	{
		$this->createContent_Info();

		if (isset($this->dko['mainDetail']))
		{
			foreach ($this->dko['mainDetail']['parts'] as $partType)
				$this->createContentBodyPart($partType);
		}
		else
		{
			if (!$this->dko['disableRows'])
				$this->createContentBodyPart(100);

			$this->createContentBodyPart(10);
			$this->createContentBodyPart(20);
			$this->createContentBodyPart(110);
		}
	}

	protected function createContentBodyPart ($partType)
	{
		if ($partType == 0)
			return;

		if ($partType == 10)
		{
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'paneTitle' => ['text' => 'Rozbor zakázky', 'class' => 'h2', 'icon' => 'system/iconMoney'],
				'sumTable' => [
					'objectId' => 'e10doc.debs.SumTableJournalDebsBS',
					'queryParams' => ['work_order' => $this->recData['ndx']]
				]
			]);
		}
		elseif ($partType == 11)
		{
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'paneTitle' => ['text' => 'Rozbor zakázky', 'class' => 'h2', 'icon' => 'icon-money'],
				'sumTable' => [
					'objectId' => 'e10doc.debs.SumTableJournalDebs',
					'queryParams' => ['work_order' => $this->recData['ndx']]
				]
			]);
		}
		elseif ($partType == 12)
		{
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'paneTitle' => ['text' => 'Rozbor zakázky', 'class' => 'h2', 'icon' => 'icon-money'],
				'sumTable' => [
					'objectId' => 'e10doc.debs.SumTableJournalDebsBSInt',
					'queryParams' => ['work_order' => $this->recData['ndx']]
				]
			]);
		}
		elseif ($partType == 20)
		{
			$this->addContent ('body', [
				'pane' => 'e10-pane e10-pane-table',
				'paneTitle' => ['text' => 'Práce na zakázce', 'class' => 'h2', 'icon' => 'system/iconCogs'],
				'sumTable' => [
					'objectId' => 'e10mnf.core.SumTableWorks',
					'queryParams' => ['work_order' => $this->recData['ndx']]
				]
			]);
		}
		elseif ($partType == 30)
		{
			$e = $this->table->analysisEngine();
			$e->setWorkOrder($this->recData['ndx']);
			$e->doIt();
			$e->createCardContent($this);
		}
		elseif ($partType == 100)
		{
			$this->addContent('body', $this->createContent_Rows());
		}
		elseif ($partType == 110)
		{
			$this->attachments();
		}
	}

	public function createContent ()
	{
		$this->dko =  $this->table->docKindOptions ($this->recData);
		$this->tableDocsHeads = $this->app()->table ('e10doc.core.heads');
		$this->tableAddress = $this->app()->table('e10.persons.address');
		$this->currencies = $this->app()->cfgItem ('e10.base.currencies');

		$this->loadDataAddresses();
		$this->loadDataInvoices();

		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createTitle();
	}
}
