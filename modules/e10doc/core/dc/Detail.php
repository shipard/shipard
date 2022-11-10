<?php

namespace e10doc\core\dc;
use \e10\utils, wkf\core\TableIssues;
use \e10doc\core\libs\E10Utils;

/**
 * Class Detail
 * @package e10doc\core\dc
 */
class Detail extends \e10\DocumentCard
{
	protected $linkedAttachments = [];
	protected $docTypes, $currencies, $balances, $usedNdxs;

	var $tablePersons;
	var $personRecData;
	var $docType;
	/** @var \e10doc\invoicesout\InvoiceReport */
	var $report = NULL;

	public function attachments ()
	{
		$this->addContentAttachments ($this->recData ['ndx']);

		foreach ($this->linkedAttachments as $la)
			$this->addContentAttachments ($la ['recid'], $la ['tableid'], $la ['title'], $la ['downloadTitle']);
	}

	public function balanceInfo ()
	{
		$bi = new \e10doc\balance\BalanceDocumentInfo($this->app());
		$bi->setDocRecData ($this->recData);
		$bi->run ();

		if (!$bi->valid)
			return;

		$line = [];
		$line[] = ['text' => utils::datef($this->recData['dateDue']), 'icon' => 'system/iconStar'];

		if ($bi->restAmount < 1.0)
		{
			$line[] = ['text' => 'UHRAZENO v plné výši', 'icon' => 'icon-check-square'];
		}
		else
			if ($bi->restAmount == $this->recData['toPay'])
			{
				$line[] = ['text' => 'NEUHRAZENO', 'icon' => ($bi->daysOver > 0) ? 'icon-exclamation' : 'system/iconCheck', 'class' => 'e10-linePart h1'];
			}
			else
			{
				$line[] = ['text' => '', 'icon' => 'system/iconCheck', 'class' => 'e10-linePart h1'];
				$partialAmount = (isset($this->recData['toPay']) && $this->recData['toPay']) ? ($bi->paymentTotal / $this->recData['toPay']) * 100 : 0;
				$line[] = ['text' => 'ČÁSTEČNĚ UHRAZENO', 'prefix' => utils::nf($partialAmount, 0).' %', 'class' => 'e10-none'];
			}

		foreach ($bi->tools as $t)
			$line[] = $t;

		$this->addContent('body', ['pane' => 'padd5 e10-pane-info '.$bi->stateClass, 'type' => 'line', 'line' => $line]);
	}

	public function createContentHeader ()
	{
		$recData = $this->recData;

		$this->docType = $this->app->cfgItem ('e10.docs.types.' . $recData['docType']);
		$docType = $this->docType;

		$headerStyle = isset ($docType ['headerStyle']) ? $docType ['headerStyle'] : 'taxes';

		$this->tablePersons = $this->app->table ('e10.persons.persons');
		$this->personRecData = $this->tablePersons->loadItem ($recData['person']);
		$hdr = $this->table->createPersonHeaderInfo ($this->personRecData, $recData);
		$hdr ['icon'] = $this->table->tableIcon ($recData);
		$hdr ['class'] = 'e10-pane-header '.$this->docStateClass();

		$docInfo [] = ['text' => $recData ['docNumber'] . ' ▪︎ ' . $docType ['shortName'], 'icon' => 'icon-file'];
		$hdr ['info'][] = ['class' => 'title', 'value' => $docInfo];

		if (isset ($this->recData ['ndx']))
		{
			$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');

			if ($headerStyle == 'taxes')
			{
				if ($recData['taxPayer'])
				{
					$hdr ['sum'][] = ['class' => 'big', 'value' => '' . utils::nf ($this->recData ['sumBase'], 2), 'prefix' => $currencyName];
					if ($recData['taxCalc'] !== 0)
						$hdr ['sum'][] = ['class' => 'normal',
								'value' => [
										['text' => utils::nf ($recData['sumTax'], 2), 'prefix' => '+ dph'],
										['text' => utils::nf ($recData['toPay'], 2), 'prefix' => (($recData['rounding'] != 0.0)? ' ≐' : ' =')]
								]
						];
					else
						$hdr ['sum'][] = ['class' => 'normal', 'value' => '', 'prefix' => 'nedaňový doklad'];
				}
				else
					$hdr ['sum'][] = ['class' => 'big', 'value' => utils::nf ($recData['toPay'], 2), 'prefix' => $currencyName];
			}
			else
			if ($headerStyle == 'toPay')
			{
				if ($this->recData ['taxPayer'])
				{
					$hdr ['sum'][] = ['class' => 'big', 'value' => '' . utils::nf ($recData['toPay'], 2), 'prefix' => $currencyName];
					if ($recData['taxCalc'] !== 0)
						$hdr ['sum'][] = ['class' => 'normal', 'value' => utils::nf ($recData['sumBase'], 2), 'prefix' => 'bez DPH'];
				}
				else
					$hdr ['sum'][] = ['class' => 'big', 'value' => utils::nf ($recData['toPay'], 2), 'prefix' => $currencyName];
			}
			if ($headerStyle == 'toPay' && $recData['docType'] === 'purchase')
			{
				$wght = utils::nf ($recData['weightNet'], 2);
				if ($recData['weightIn'] != 0 && $recData['weightOut'] != 0)
				{
					$ww = $this->recData ['weightIn'] - $this->recData ['weightOut'];
					$miss =  $ww - $this->recData ['weightNet'];
					if ($miss != 0)
						$wght .= '; má být '.utils::nf ($ww, 2).', zbývá ' . utils::nf ($miss, 2);
				}
				$hdr ['sum'][] = ['class' => 'normal', 'value' => $wght, 'suffix' => 'kg'];
			}
			else
			if ($headerStyle === 'mnf')
			{
				if (isset ($options['lists']) && $options['lists']['rows'][0]['unit'] === 'kg')
				{
					$wght = $options['lists']['rows'][0]['quantity'];
					$hdr ['sum'][] = ['class' => 'big', 'value' => utils::nf ($wght, 2), 'suffix' => 'kg'];
					$used = $recData['weightNet'] - $wght;
					$miss =  $wght - $used;
					if ($miss != 0)
					{
						$wght = 'chybí ' . utils::nf ($miss, 2);
						$hdr ['sum'][] = ['class' => 'normal', 'value' => $wght, 'suffix' => 'kg'];
					}
				}
				else
				{
					$hdr ['sum'][] = ['class' => 'big', 'value' => utils::nf ($this->recData ['weightGross'], 2), 'suffix' => 'kg'];
				}
			}
			else
			if ($headerStyle === 'cmnbkp')
			{
				if ($this->recData ['credit'] === $this->recData ['debit'])
				{
					$hdr ['sum'][] = ['class' => 'big', 'value' => '' . utils::nf ($recData['credit'], 2), 'prefix' => $currencyName];
				}
				else
				{
					$hdr ['sum'][] = ['prefix' => $currencyName, 'value' => [
							['class' => 'normal e10-error', 'text' => utils::nf ($recData['debit'], 2), 'prefix' => ' MD'],
							['class' => 'normal e10-error', 'text' => utils::nf ($recData['credit'], 2), 'prefix' => '≠ DAL'],
					]];
					$hdr ['sum'][] = ['class' => 'normal e10-error', 'value' => utils::nf ($recData['debit'] - $recData['credit'], 2), 'prefix' => 'rozdíl:'];
				}
			}
			else
			if ($headerStyle === 'bank')
			{
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-plus-square', 'text' => utils::nf ($recData['credit'], 2)], 'prefix' => $currencyName];
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-minus-square', 'text' => utils::nf ($recData['debit'], 2)]];
			}
			else
			if ($headerStyle === 'bankorder')
			{
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-minus-square', 'text' => utils::nf ($recData['debit'], 2), 'prefix' => $currencyName]];
				if ($recData['credit'])
					$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-plus-square', 'text' => utils::nf ($recData['credit'], 2)]];
			}
		}
		else
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Nový doklad'];
		}

		$this->addContent('header', ['type' => 'tiles', 'tiles' => [$hdr], 'class' => 'panes']);
	}

	public function createTitle ()
	{
		$title = ['text' => $this->recData ['docNumber'], 'suffix' => $this->docType ['shortName'], 'icon' => $this->table->tableIcon($this->recData)];

		$this->addContent('title', ['type' => 'line', 'line' => $title]);

		if ($this->recData['person'])
			$subTitle = [['icon' => $this->tablePersons->tableIcon ($this->personRecData), 'text' => $this->personRecData['fullName']]];

		if ($this->recData['title'] !== '')
			$subTitle[] = ['text' => $this->recData['title'], 'class' => 'e10-off block'];
		$this->addContent('subTitle', ['type' => 'line', 'line' => $subTitle]);
	}

	protected function docTitle ($r)
	{
		$docTitle = $this->docTypes[$r['docType']]['fullName'];
		if ($r['title'] != '')
			$docTitle .= ': '.$r['title'];

		if ($r['payment'])
			$docTitle .= ' / '.utils::nf ($r['payment'], 2).' '.$this->currencies[$r['currency']]['shortcut'];
		else
			if ($r['request'])
				$docTitle .= ' / '.utils::nf ($r['request'], 2).' '.$this->currencies[$r['currency']]['shortcut'];

		return $docTitle;
	}

	public function linkedDocuments ()
	{
		$this->docTypes = $this->table->app()->cfgItem ('e10.docs.types');
		$this->currencies = $this->app()->cfgItem ('e10.base.currencies');
		$this->balances = $this->app()->cfgItem ('e10.balance');

		$docsFrom = [];
		$docsTo = [];

		// -- contract
		if ($this->recData ['contract'] != 0)
		{
			$tableContracts = $this->app()->table ('e10doc.contracts.core.heads');
			$linkedItem = $tableContracts->loadItem ($this->recData ['contract']);
			$docsFrom[] = ['icon' => $tableContracts->tableIcon ($linkedItem), 'text' => $linkedItem['contractNumber'], 'class' => 'tag tag-contact',
					'docAction' => 'edit', 'table' => 'e10doc.contracts.core.heads', 'pk' => $this->recData ['contract'],
					'title' => 'Smlouva prodejní: '.$linkedItem['title']];
		}

		// -- inbox/outbox
		$this->linkedInboxOutbox($docsFrom, $docsTo);

		// -- balance - head
		$this->usedNdxs = [$this->recData['ndx']];
		$this->linkedDocuments_Balance ($docsFrom, $docsTo, $this->recData['symbol1'], $this->recData['symbol2'], $this->recData['person']);
		// -- balance - rows
		$rows = $this->db()->query ('SELECT [rows].symbol1, [rows].symbol2, [rows].person FROM [e10doc_core_rows] AS [rows] WHERE [rows].document = %i AND [symbol1] != %s ORDER BY ndx',
				$this->recData['ndx'], '');
		foreach ($rows as $r)
		{
			$this->linkedDocuments_Balance ($docsFrom, $docsTo, $r['symbol1'], $r['symbol2'], ($r['person']) ? $r['person'] : $this->recData['person']);
		}

		// -- compose table
		if (count ($docsFrom) || count($docsTo))
		{
			if ($this->app->mobileMode)
			{
				$docs = ['class' => 'TEST123'];

				if (count($docsFrom))
					$docs['info'][] = ['value' => $docsFrom];

				$docs['info'][] = ['value' => ['icon' => 'icon-arrow-down', 'text' => ' ', 'class' => 'block']];

				if (count($docsTo))
					$docs['info'][] = ['value' => $docsTo];

				$this->addContent('body', ['pane' => 'e10-pane-doc-trace', 'type' => 'tiles', 'tiles' => [$docs], 'class' => 'panes']);
			}
			else
			{
				$tr = [];

				$tr ['doc'] = [];

				if (count($docsFrom))
				{
					$tr['from'] = $docsFrom;
					$tr ['doc'][] = ['icon' => 'iconArrowRight', 'text' => ' '];
				}
				$tr ['doc'][] = ['icon' => 'iconFileText', 'text' => ' '];
				if (count($docsTo))
				{
					$tr['to'] = $docsTo;
					$tr ['doc'][] = ['icon' => 'iconArrowRight', 'text' => ' '];
				}
				$tr ['_options'] = ['cellClasses' => ['from' => 'width30 number', 'to' => 'xxwidth50', 'doc' => 'nowrap docLinkIcon']];

				$table[] = $tr;

				if ($this->dstObjectType !== 'issue')
					$this->addContent('body', [
							'pane' => 'padd5', 'type' => 'table', 'header' => ['from' => 'from', 'doc' => 'doc', 'to' => 'to'],
							'table' => $table, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth']
					]);
			}
		}
	}

	function linkedInboxOutbox(&$docsFrom, &$docsTo)
	{
		if (!isset($this->recData['ndx']) || !$this->recData['ndx'])
			return;

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');

		$rows = $this->db()->query (
			'SELECT * FROM [wkf_core_issues] WHERE (tableNdx = %i', 1078, ' AND recNdx = %i', $this->recData['ndx'], ') ',
			' OR ',
			'EXISTS (SELECT ndx FROM [e10_base_doclinks] AS l WHERE linkId = %s', 'e10docs-inbox', ' AND srcTableId = %s', 'e10doc.core.heads',
			' AND srcRecId = %i', $this->recData['ndx'], ' AND l.dstRecId = wkf_core_issues.ndx)',
			' ORDER BY dateCreate DESC, ndx DESC'
		);

		foreach ($rows as $r)
		{
			if ($r['docState'] === 9800)
				continue; // deleted
			$dateStr = $r['dateIncoming'] ? utils::datef ($r['dateIncoming']) : utils::datef ($r['date']);
			$msgItem = ['icon' => $tableIssues->tableIcon ($r), 'text' => '#'.$r['ndx'], 'class' => 'tag tag-contact',
				'prefix' => $dateStr,
				'docAction' => 'edit', 'table' => 'wkf.core.issues', 'pk' => $r['ndx']];
			if ($r['issueType'] === TableIssues::mtInbox)
			{
				$msgItem['title'] = 'Došlá pošta: '.$r['subject'];
				$docsFrom[] = $msgItem;
			}
			elseif ($r['issueType'] === TableIssues::mtOutbox)
			{
				$msgItem['title'] = 'Odeslaná pošta: '.$r['subject'];
				$docsTo[] = $msgItem;
			}
			else
			{
				$msgItem['title'] = 'TEST: '.$r['subject'];
				$docsTo[] = $msgItem;
			}

			$laTitleLeft = ['icon' => 'system/formAttachments', 'text' => 'Přílohy'];
			$laTitleRight = $msgItem;
			$laTitleRight ['class'] = 'pull-right';

			$laDownloadTitleLeft = ['icon' => 'system/actionDownload', 'text' => 'Soubory ke stažení'];
			$laDownloadTitleRight = $msgItem;
			$laDownloadTitleRight ['class'] = 'pull-right';

			$this->linkedAttachments[] = [
				'tableid' => 'wkf.core.issues', 'recid' => $r['ndx'],
				'title' => [$laTitleLeft, $laTitleRight], 'downloadTitle' => [$laDownloadTitleLeft, $laDownloadTitleRight]
			];
		}
	}

	public function linkedDocuments_Balance (&$docsFrom, &$docsTo, $symbol1, $symbol2, $person)
	{
		if ($this->app()->model()->module ('e10doc.balance') === FALSE)
			return;
		if ($symbol1 == '')
			return;

		$q [] = 'SELECT heads.docNumber, heads.docType as docType, heads.activity as activity, heads.title, heads.dateAccounting, saldo.*';

		array_push ($q, ' FROM e10doc_balance_journal as saldo');
		array_push ($q, '	LEFT JOIN e10doc_core_heads as heads ON saldo.docHead = heads.ndx');
		array_push ($q, ' WHERE saldo.[fiscalYear] = %i', $this->recData ['fiscalYear']);
		array_push ($q, ' AND saldo.[symbol1] = %s', $symbol1, ' AND saldo.[symbol2] = %s', $symbol2);
		array_push ($q, ' AND saldo.[person] = %i', $person);
		array_push ($q, ' ORDER BY saldo.[date] DESC, pairId');

		$rows = $this->app()->db()->query ($q);

		forEach ($rows as $r)
		{
			if (in_array($r['docHead'], $this->usedNdxs))
				continue;
			$this->usedNdxs[] = $r['docHead'];

			$docItem = ['icon' => $this->table->tableIcon ($r), 'text' => $r['docNumber'], 'class' => 'tag tag-contact',

					'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $r['docHead'], 'title' => $this->docTitle($r)];
			if (isset($docItem['prefix']))
				$docItem['prefix'] = utils::datef ($r['date']);
			else
				$docItem['prefix'] = utils::datef ($r['dateAccounting']);

			if ($r['docType'] === 'stockin' || $r['docType'] === 'purchase')
				$docsFrom[] = $docItem;
			else
				$docsTo[] = $docItem;
		}
	}

	public function docsRows ()
	{
		$q = [];
		array_push($q, 'SELECT [rows].text AS rText, [rows].quantity AS rQuantity, [rows].unit AS rUnit, [rows].priceItem AS rPriceItem,');
		array_push($q, ' [rows].priceAll AS rPriceAll, [rows].item,');
		array_push($q, ' [items].[id] AS [itemId]');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows] ');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows].[item] = [items].[ndx]');
		array_push($q, ' WHERE [rows].document = %i', $this->recData ['ndx']);
		array_push($q, ' AND [rowType] = %i', 0);
		array_push($q, ' ORDER BY [rows].rowOrder, [rows].ndx');

		$itemCodesInfo = [];
		$cfgUnits = $this->app->cfgItem ('e10.witems.units');
		$rows = $this->table->db()->query($q);
		$list = [];
		$totalPriceAll = 0.0;
		forEach ($rows as $r)
		{
			$unit = (isset($cfgUnits[$r['rUnit']])) ? $cfgUnits[$r['rUnit']]['shortcut'] : '';
			$rowItem = [
				'text' => [['text' => $r['rText'], 'class' => 'block']],
				'item' => ['text' => $r['itemId'], 'docAction' => 'edit', 'pk' => $r['item'], 'table' => 'e10.witems.items'],
				'quantity' => $r['rQuantity'],
				'unit' => $unit,
				'priceItem' => $r['rPriceItem'],
				'priceAll' => $r['rPriceAll']
			];

			$this->table->loadDocRowItemsCodes($this->recData, $this->personRecData['personType'], $r->toArray(), NULL, $rowItem, $itemCodesInfo);

			if (isset($rowItem['rowItemCodesData']))
			{
				foreach ($rowItem['rowItemCodesData'] as $rci)
				{
					$icl = ['text' => $rci['itemCodeName'].': '.$rci['itemCodeText'], 'class' => 'label label-default'];
					if (isset($rci['itemCodeTitle']))
						$icl['title'] = $rci['itemCodeTitle'];
					$rowItem['text'][] = $icl;
					//$rowItem['text'][] = ['text' => json_encode ($rci)];
				}
			}

			//$rowItem['text'][] = ['text' => 'AHOJ!'];

			$list[] = $rowItem;
			$totalPriceAll += $r['rPriceAll'];
		}

		if (count ($list))
		{
			//if ($withPrices)
			{
				$h = [
					'#' => '#',
					'item' => 'Pol.',
					'text' => 'Text řádku',
					'quantity' => ' Množství',
					'unit' => 'Jedn.',
					'priceItem' => ' Cena/Jedn.',
					'priceAll' => ' Cena celkem',
				];
				if (count ($list) > 1)
				{
					$list[] = ['text' => 'Celkem', 'priceAll' => $totalPriceAll, '_options' => ['class' => 'sum']];
				}
			}
			//else
			//	$h = ['#' => '#', 'text' => 'Text řádku', 'quantity' => ' Množství', 'unit' => 'Jedn.'];
			return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'], 'header' => $h, 'table' => $list];
		}
		return FALSE;
	}

	public function createContentBody ()
	{
		$docType = $this->app->cfgItem ('e10.docs.types.' . $this->recData['docType']);
		$detailStyle = isset ($docType ['detailStyle']) ? $docType ['detailStyle'] : 'taxes';

		if ($this->dstObjectType !== 'issue')
		{
			$this->balanceInfo();
		}
		$this->linkedDocuments();
		$this->advances();

		if ($detailStyle === 'taxes')
		{
			if ($docType['useTax'])
			{
				if ($this->recData['taxPayer'] && $this->recData['taxCalc'] != 0)
				{
					$vs = $this->table->summaryVAT ($this->recData);
					$vs['title'] = [['icon' => 'docType/taxes', 'text' => 'Rekapitulace DPH']];

					$taxReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$this->recData['vatReg'], NULL);
					if ($taxReg)
					{
						$vs['title'][] = ['text' => $taxReg['title'], 'class' => 'pull-right e10-small label label-default'];
						if ($taxReg['payerKind'] === 1)
						{ // OSS
							$countryId = E10Utils::docTaxCountryId($this->app(), $this->recData);
							$vs['title'][] = ['text' => strtoupper($countryId), 'class' => 'pull-right e10-small label label-info'];
						}
					}
					$vs['pane'] = 'e10-pane e10-pane-table';
					$this->addContent ('body', $vs);
				}
			}
		}

		$this->addContent ('body', $this->docsRows ());

		$this->addDiaryPinnedContent();

		$this->attachments();
	}

	function advances()
	{
		$list = [];

		foreach ($this->report->data['rowsAdvance'] as $r)
		{
			$item = ['text' => $r['text'], 'quantity' => $r['quantity'], 'priceAll' => $r['priceAll']];
			if ($r['isTaxAdvance'])
			{
				$item['taxBase'] = $r['tax'];
				$item['tax'] = $r['tax'];
			}
			else
			{
				$item['taxBase'] = 'nedaňová záloha';
				$item['_options'] = ['colSpan' => ['taxBase' => 2], 'cellClasses' => ['taxBase' => 'center e10-off']];
			}
			$list[] = $item;
		}

		if (!count($list))
			return;

		$h = ['#' => '#', 'text' => 'Text řádku', 'quantity' => ' Množství', 'priceAll' => ' Cena celkem', 'taxBase' => ' Základ', 'tax' => ' Daň'];
		$this->addContent('body',
			[
				'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
				'title' => ['icon' => 'icon-money', 'text' => 'Odpočet záloh'], 'header' => $h, 'table' => $list
			]
		);
	}

	public function createContent ()
	{
		$this->report = new \e10doc\core\libs\reports\DocReport($this->table, $this->recData);
		$this->report->init ();
		//$this->report->renderReport ();

		$this->createContentHeader ();
		$this->createContentBody ();
		$this->createTitle();
	}
}
