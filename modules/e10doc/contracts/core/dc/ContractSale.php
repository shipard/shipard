<?php

namespace e10doc\contracts\core\dc;
use e10\utils, e10pro\wkf\TableMessages, wkf\core\TableIssues;


/**
 * Class ContractSale
 * @package e10doc\contracts\core\dc
 */
class ContractSale extends \e10\DocumentCard
{
	var $contractKind = NULL;

	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;

	public function attachments ()
	{
		$this->addContentAttachments ($this->recData ['ndx']);
	}

	public function createContentHeader ()
	{
	}

	public function createContentCoreInfo ()
	{
		$dstDocTypes = $this->app()->cfgItem('e10doc.contracts.dstDocTypes');
		$this->contractKind = $this->app()->cfgItem('e10doc.contracts.kinds.'.$this->recData['docKind'], NULL);
		// -- docNumber
		$dn = [['text' => $this->recData['docNumber'], 'class' => 'label label-default']];
		if ($this->recData['contractNumber'] !== '')
			$dn[] = ['text' => $this->recData['contractNumber'], 'class' => 'label label-default'];
		$i = ['t1' => 'Číslo smouvy', 'v1' => $dn, 't2' => '', 'v2' => ''];
		if ($this->contractKind)
		{
			$i['t2'] = 'Druh smlouvy';
			$i['v2'] = $this->contractKind['fn'];
		}
		$t[] = $i;

		// -- date from/to & paymentMethod
		$i = ['t1' => 'Platnost', 'v1' => '', 't2' => 'Způsob úhrady', 'v2' => $this->table->columnInfoEnumStr ($this->recData, 'paymentMethod')];
		if (!utils::dateIsBlank($this->recData['start']))
			$i['v1'] .= utils::datef ($this->recData['start'], '%D');
		$i['v1'] .= ' → ';
		if (!utils::dateIsBlank($this->recData['end']))
			$i['v1'] .= utils::datef ($this->recData['end'], '%D');

		$t[] = $i;

		// -- period & currency
		$i = [
			't1' => 'Periodicita', 'v1' => $this->table->columnInfoEnumStr ($this->recData, 'period'),
			't2' => 'Měna', 'v2' => $this->table->columnInfoEnumStr ($this->recData, 'currency')];
		$t[] = $i;


		// -- invoicingDay & taxCalc
		$i = [
			't1' => 'Fakturace k', 'v1' => $this->table->columnInfoEnumStr ($this->recData, 'invoicingDay'),
			't2' => 'Výpočet daně', 'v2' => $this->table->columnInfoEnumStr ($this->recData, 'taxCalc')];
		$t[] = $i;

		// -- dueDays & dstDocType
		$i = [
			't1' => 'Dnů splatnosti', 'v1' => $this->recData['dueDays'],
			't2' => 'Doklad', 'v2' => $dstDocTypes[$this->recData['dstDocType']]['fn'],
		];
		$t[] = $i;



		$h = ['t1' => '', 'v1' => '', 't2' => '', 'v2' => ''];
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $t,
			'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
	}

	public function listRows ()
	{
		$item = $this->recData;

		$q[] = 'SELECT r.text, r.quantity, r.unit, r.priceItem, r.priceAll, r.start as rowStart, r.end as rowEnd, h.start as headStart, h.end as headEnd, h.docState';
		array_push ($q, ' FROM e10doc_contracts_rows as r LEFT JOIN e10doc_contracts_heads as h ON r.contract = h.ndx');
		array_push ($q, ' WHERE r.contract = %i ORDER BY r.ndx', $item ['ndx']);

		$cfgUnits = $this->app()->cfgItem ('e10.witems.units');
		$rows = $this->app()->db()->query($q);
		$list = array ();
		$totalPriceAll = 0.0;
		$now = time ();
		forEach ($rows as $r)
		{
			$rowIsActive = 1;
			if ($r['rowStart'])
				if (strtotime($r['rowStart']->format('Ymd')) > $now)
					$rowIsActive = 2;
			if ($r['rowEnd'])
				if (strtotime($r['rowEnd']->format('Ymd')) < $now)
					$rowIsActive = 0;
			if ($r['headStart'])
				if (strtotime($r['headStart']->format('Ymd')) > $now)
					$rowIsActive = 2;
			if ($r['headEnd'])
				if (strtotime($r['headEnd']->format('Ymd')) < $now)
					$rowIsActive = 0;
			if ($r['docState'] == 9000 || $r['docState'] == 9800)
				$rowIsActive = 0;

			$unit = (isset($cfgUnits[$r['unit']])) ? $cfgUnits[$r['unit']]['shortcut'] : '';
			$item = array ('text' => $r['text'], 'quantity' => $r['quantity'], 'unit' => $unit, 'priceItem' => $r['priceItem'], 'priceAll' => $r['priceAll']);
			switch ($rowIsActive)
			{
				case 0:
					$icon = 'system/actionStop';
					$class = 'e10-row-stop';
					break;
				case 1:
					$icon = 'system/actionPlay';
					$class = 'e10-row-play';
					break;
				case 2:
					$icon = 'icon-pause';
					$class = 'e10-row-pause';
					break;
			}
			$item['state'] = ['icon' => $icon, 'text' => ''];
			$options['cellClasses'] = ['state' => 'e10-icon '.$class];
			$validity = 'Platnost';
			if ($r['rowStart'])
				$validity .= ' od '.\E10\df ($r['rowStart'], '%D');
			if ($r['rowEnd'])
				$validity .= ' do '.\E10\df ($r['rowEnd'], '%D');
			if (!$r['rowStart'] && !$r['rowEnd'])
				$validity .= ' neomezená';
			$options['cellTitles'] = ['state' => $validity];
			$item['_options'] = $options;
			$list[] = $item;
			if ($rowIsActive == 1)
				$totalPriceAll += $r['priceAll'];
		}

		if (count ($list))
		{
			$h = array ('#' => '#', 'state' => ['icon' => 'icon-bolt', 'text' => ''], 'text' => 'Text řádku', 'quantity' => ' Množství', 'unit' => 'Jedn.',
				'priceItem' => ' Cena/Jedn.', 'priceAll' => ' Cena celkem', '_options' => ['cellClasses' => ['state' => 'e10-icon']]);
			if (count ($list) > 1)
			{
				$list[] = array ('state' => ['icon' => 'system/actionPlay', 'text' => ''], 'text' => 'Celkem aktuálně platné', 'priceAll' => $totalPriceAll, '_options' => ['class' => 'sum', 'cellClasses' => ['state' => 'e10-icon']]);
			}
			return array ('pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky smlouvy'], 'header' => $h, 'table' => $list);
		}
		return FALSE;
	}

	protected function loadInvoices()
	{
		$t = [];

		$q = [];
		array_push ($q, 'SELECT [heads].*');
		array_push ($q, ' FROM [e10doc_core_heads] AS [heads]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [heads].[docType] IN %in', ['invno', 'invpo']);
		array_push ($q, ' AND [heads].[docState] != %i', 9800);
		array_push ($q, ' AND [heads].[contract] = %i', $this->recData['ndx']);
		array_push ($q, ' ORDER BY [heads].[dateAccounting] DESC');
		array_push ($q, ' LIMIT 10');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$balanceInfo = $this->docBalanceInfo($r);
			unset($balanceInfo[0]);

			$docStates = $this->tableDocsHeads->documentStates($r);
			$docStateClass = $this->tableDocsHeads->getDocumentStateInfo($docStates, $r, 'styleClass');

			$item = [
				'docNumber' => [
					'text' => $r['docNumber'], 'icon' => $this->tableDocsHeads->tableIcon($r),
					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['ndx'],
					],
				'dateAccounting' => utils::datef($r['dateAccounting']),
				'dateDue' => utils::datef($r['dateDue']),
				'sumBase' => $r['sumBase'], 'sumTotal' => $r['sumTotal'],
				'balance' => $balanceInfo,
			];

			$item['_options']['cellClasses']['docNumber'] = $docStateClass;

			$period = '';
			if (!utils::dateIsBlank($r['datePeriodBegin']))
				$period .= utils::datef ($r['datePeriodBegin'], '%D');
			$period .= ' → ';
			if (!utils::dateIsBlank($r['datePeriodEnd']))
				$period .= utils::datef ($r['datePeriodEnd'],'%D');
			$item['period'] = $period;

			$t[] = $item;
		}

		$h = [
			'docNumber' => ' Doklad', 'dateAccounting' => ' Datum', 'period' => 'Období',
			'sumBase' => ' Základ daně', 'sumTotal' => ' Cena celkem',
			'dateDue' => ' Splatnost',
			'balance' => 'Úhrada',
		];
		$content = ['pane' => 'e10-pane e10-pane-table', 'type' => 'table',
			'title' => ['icon' => 'system/iconMoney', 'text' => 'Fakturace'],
			'header' => $h, 'table' => $t
		];
		$this->addContent ('body', $content);
	}

	protected function docBalanceInfo ($docRecData)
	{
		$bi = new \e10doc\balance\BalanceDocumentInfo($this->app());
		$bi->setDocRecData ($docRecData);
		$bi->run ();

		if (!$bi->valid)
			return NULL;

		$line = [];
		$line[] = ['text' => utils::datef($docRecData['dateDue']), 'class' => ''];

		if ($bi->restAmount < 1.0)
		{
			$line[] = ['text' => 'Uhrazeno', 'icon' => 'icon-check-square', 'class' => 'e10-linePart'];
		}
		else
		if ($bi->restAmount == $docRecData['toPay'])
		{
			$line[] = ['text' => 'NEUHRAZENO', 'icon' => ($bi->daysOver > 0) ? 'icon-exclamation' : 'system/iconCheck', 'class' => 'e10-linePart e10-error'];
		}
		else
		{
			$line[] = ['text' => '', 'icon' => 'system/iconCheck', 'class' => 'e10-linePart h1'];
			$line[] = ['text' => 'Částečná úhrada', 'prefix' => utils::nf($bi->paymentTotal / $docRecData['toPay'] * 100, 0).' %', 'class' => 'e10-none'];
		}

		foreach ($bi->tools as $t)
			$line[] = $t;

		if (!count($line))
			return NULL;

		return $line;
	}

	public function createContentBody ()
	{
		$this->addContent ('body', $this->listRows());
		$this->loadInvoices();

		//$this->addDiaryPinnedContent();

		$this->attachments();
	}

	public function createContent ()
	{
		$this->tableDocsHeads = $this->app()->table('e10doc.core.heads');

		$this->table->applyContractKind($this->recData);

		$this->createContentCoreInfo();
		$this->createContentHeader ();
		$this->createContentBody ();
	}
}
