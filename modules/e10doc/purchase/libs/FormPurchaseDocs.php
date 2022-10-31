<?php

namespace e10doc\purchase\libs;


use e10doc\core\e10utils;
use E10\utils;
use \Shipard\Application\DataModel;
use \e10\base\libs\UtilsBase;


class FormPurchaseDocs extends \e10doc\core\FormHeads
{
	public function renderForm ()
	{
		$this->checkInfoPanelAttachments('20vw');

		$taxPayer = $this->recData['taxPayer'];

		$this->setFlag ('maximize', 1);
		$this->setFlag ('resetButtons', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);
		$this->setFlag ('sidebarWidth', '0.36');
		$this->setFlag ('terminal', 1);

		$dsn = $this->recData['docState'];
		if ($dsn === 1200  || $dsn === 1201 || $dsn === 1202 || $dsn === 1203 || $dsn === 1204 || $dsn === 1205 || $dsn === 1206)
		{
			$this->openForm (self::ltVertical);
				$this->addColumnInput ("title", self::coHidden);
				$this->renderForm_Recapitulation();
			$this->closeForm ();
			return;
		}

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Doklad', 'icon' => 'system/formHeader');
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'system/formAttachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'system/formSettings');
			$this->openTabs ($tabs, 'right');

			$this->openTab (self::ltNone);
				$this->layoutOpen (self::ltDocRows, 'e10-terminalForm');
					$this->addList ('rows', '', self::loRowsDisableMove);
				$this->layoutClose ();

				$this->layoutOpen (self::ltDocMain, 'e10-terminalForm');

					$this->layoutOpen (self::ltForm);
						$this->addPicturesWidget ($this->useAttInfoPanel);
						if ($this->recData['weighingMachine'] != 0)
						{
							$inpParams = array ('srcSensor' => $this->recData['weighingMachine']);

							$this->addColumnInput ("weightIn", 0, $inpParams);

							if ($this->table->app()->testGetParam ('__weightOut') !== '')
								$this->addColumnInput ("weightOut", 0, $inpParams);
							else
								$this->addColumnInput ("weightOut");
						}
					$this->layoutClose ();
					$this->layoutOpen (self::ltVertical);
						$this->addColumnInput ("title", DataModel::coSaveOnChange|self::coFocus);
						$this->addColumnInput ("person", DataModel::coSaveOnChange|self::coHeader);
					$this->layoutClose ();

					$regTitle = [['text' => 'Registrace', 'icon' => 'system/iconUser', 'class' => 'h2']];
					$regTitle[] = [
						'text' => 'Tisk', 'class' => 'pull-right', 'type' => 'action', 'action' => 'printdirect', 'printer' => '1',
						'data-report' => 'e10pro.custreg.RegistrationListReport', 'actionClass' => 'btn-sm',
						'data-table' => 'e10.persons.persons', 'data-pk' => $this->recData['person']
					];
					if (!$this->readOnly)
						$regTitle[] = [
								'text' => 'Upravit', 'class' => 'pull-right', 'docAction' => 'edit',
								'actionClass' => 'btn btn-primary btn-sm', 'type' => 'button', 'icon' => 'system/actionOpen',
								'table' => 'e10.persons.persons', 'pk' => $this->recData['person'],
								'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid
						];

					$this->layoutOpen (self::ltForm, 'xxe10-row-info default stripped');
						$this->addStatic($regTitle, self::coHeader);
						//$this->table->addFormPersonProperties ($this);
						$this->addFormPersonInfo();
					$this->layoutClose ();
					/*$this->addStatic('Adresa', self::coHeader);
					$this->layoutOpen (self::ltGrid);
						$this->table->addFormPersonAddress ($this);
					$this->layoutClose ();
*/

					if ($this->recData['personType'] == 2)
					{
						$this->addSeparator(self::coH2);
							$this->layoutOpen(self::ltVertical);
							$this->addColumnInput ('personHandover', self::coHeader);
							$this->addColumnInput ('cashPersonName', self::coColW12);
							$this->addColumnInput ('cashPersonID', self::coColW12);
						$this->layoutClose();
					}
				$this->layoutClose ();
      $this->closeTab ();

			$this->addAccountingTabContent();
			$this->addAttachmentsTabContent ();

			$this->openTab ();
				$this->addColumnInput ("dateIssue");
				$this->addColumnInput ("dateAccounting");
				$this->addColumnInput ('dateDue');
				//$this->addColumnInput ('bankAccount');
				$this->addColumnInput ('myBankAccount');


		if ($taxPayer)
				{
					$this->addColumnInput ("dateTax");
					$this->addColumnInput ("taxCalc");
					$this->addColumnInput ("taxType");
				}
				$this->addColumnInput ("paymentMethod");
				$this->addColumnInput ("symbol1");
				$this->addColumnInput ('symbol2');
				$this->addColumnInput ("roundMethod");
				$this->addColumnInput ("currency");
				$this->addColumnInput ("cashBox");
        $this->addColumnInput ("warehouse");
				$this->addColumnInput ("author");
				$this->addList ('clsf', '', self::loAddToFormLayout);
			$this->closeTab ();

      $this->closeTabs ();

    $this->closeForm ();

		$this->addInfoPanelAttachments();
	}

	function addInfoPanelAttachments($startCode = '')
	{
		if (!$this->useAttInfoPanel)
			return;

		$sc = '';

		if (!isset ($this->recData['ndx']))
		{
			$addPicture = $this->app()->testGetParam ('addPicture');
			if ($addPicture === '' && isset ($this->app()->workplace['startDocumentCamera']))
			{
				if (isset ($this->postData['cameras'][$this->app()->workplace['startDocumentCamera']]))
					$addPicture = $this->postData['cameras'][$this->app()->workplace['startDocumentCamera']];
			}
			if (isset($addPicture))
			{
				$sc .= "<img src='{$addPicture}' style='width: 100%;'/>";
			}
		}

		parent::addInfoPanelAttachments($sc);
	}

	public function renderForm_Recapitulation ()
	{
		$q = "SELECT [rows].text AS rText, [rows].quantity AS rQuantity, [rows].unit AS rUnit, [rows].priceItem AS rPriceItem, [rows].priceAll AS rPriceAll
          FROM [e10doc_core_rows] AS [rows] WHERE [rows].document = %i ORDER BY ndx";

		$cfgUnits = $this->app()->cfgItem ('e10.witems.units');
		$rows = $this->table->db()->query($q, $this->recData ['ndx'])->fetchAll ();
		$list = array ();
		forEach ($rows as $r)
		{
			$unit = $cfgUnits[$r['rUnit']];
			$list[] = array ('text' => $r['rText'], 'quantity' => $r['rQuantity'], 'unit' => $unit['shortcut'],
												'priceItem' => $r['rPriceItem'], 'priceAll' => $r['rPriceAll']);
		}

		$h = array ('#' => '#', 'text' => 'text',
								'quantity' => ' Množství', 'unit' => ' Jed.', 'priceItem' => ' Cena/jed', 'priceAll' => ' Celkem');

		$params = array ('forceTableClass' => 'default pull-right');

		$currencyName = $this->table->app()->cfgItem ('e10.base.currencies.'.$this->recData['currency'].'.shortcut');

		$c = '';
		$c .= "<div class='docRecapitulation' style='padding: .2em;'>";
		$c .= "<h1 style='padding-bottom: .2em; text-align: right;'>";

		switch ($this->recData['paymentMethod'])
		{
			case	0: $c .= 'Bude uhrazeno na účet:'; break;
			case	1: $c .= 'Zaplatit v hotovosti:'; break;
			case	4: $c .= 'Celkem k fakturaci:'; break;
			case	6: $c .= 'Celkem k vyúčtování:'; break;
			case	8: $c .= 'Likvidační protokol:'; break;
			case	9: $c .= 'Vystavit šek:'; break;
			case	10: $c .= 'Bude uhrazeno poštovní poukázkou:'; break;
		}

		$c .= ' '.\E10\nf ($this->recData ['toPay']).' '.$currencyName.'</h1>';

		if ($this->recData['paymentMethod'] == 0)
		{
			$c .= "<h3 style='margin-top: -1em; text-align: right;'>";
			if ($this->recData['bankAccount'] == '')
				$c .= "<span class='e10-error'>".'Není zadáno číslo účtu!'.'</span>';
			else
				$c .= 'Účet: '.$this->recData['bankAccount'];
			$c .= '</h3>';
		}

		$c .= $this->app->ui()->renderTableFromArray ($list, $h, $params);

		$c .= '</div>';

		if (isset ($this->table->app()->workplace['endDocumentCamera']))
		{
			if (isset ($this->postData['cameras'][$this->table->app()->workplace['endDocumentCamera']]))
			{
				$addPicture = $this->postData['cameras'][$this->table->app()->workplace['endDocumentCamera']];
				$this->recData['_addPicture'] = $addPicture;
				$ip = $this->option ('inputPrefix', '');
				$c .= "<input type='hidden' name='{$ip}_addPicture' data-fid='{$this->fid}'/>";
			}
		}

		$this->layoutOpen(self::ltVertical);
			$this->appendCode($c);
		$this->layoutClose('padd5 number');

		$this->layoutOpen(self::ltHorizontal);
			$this->layoutOpen(self::ltForm);
				$this->table->addFormPrintAfterConfirm ($this);

				if ($this->recData['paymentMethod'] === 9)
				{
					$this->addColumnInput('symbol2', self::coFocus);
					$this->appendElement(uiutils::addScanToDocumentInputCode('e10doc.core.heads', $this->recData['ndx']), NULL, 'e10-wsh-h2b');
				}
			$this->layoutClose('width50 padd5 number');
		$this->layoutClose();
	}


	public function checkNewRec ()
	{
		parent::checkNewRec ($this->recData);

		if (isset ($this->table->app()->workplace['cashBox']))
			$this->recData ['cashBox'] = $this->table->app()->workplace['cashBox'];

		$this->recData ['roundMethod'] = intval($this->app()->cfgItem ('options.e10doc-buy.roundPurchase', 1));
		$this->recData ['taxCalc'] = 0;
		$this->recData ['dateDue'] = utils::today();
	}

	function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'person': return 'Dodavatel';
			case	'personHandover': return 'Předávající';
			case	'bankAccount': return 'Bankovní účet pro úhradu';
			case	'symbol2': if ($this->recData['paymentMethod'] === 9) return 'Číslo šeku'; break;
			case	'cashPersonName': return 'Jméno';
			case	'cashPersonID': return 'OP';
    }
    return parent::columnLabel ($colDef, $options);
  }

	public function comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId = 'default')
	{
		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'person')
			return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, 'e10pro.purchase.ViewSuppliers');
		return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId);
	}


	public function addFormPersonInfo ()
	{
		$personNdx = $this->recData['person'];

		// -- properties
		$properties = e10utils::loadProperties($this->app(), $personNdx);
		if (isset($properties[$personNdx]['contacts']))
			$this->addStatic($properties[$personNdx]['contacts']);
		if (isset($properties[$personNdx]['ids']))
			$this->addStatic($properties[$personNdx]['ids']);
		// -- bank accounts
		if ($this->readOnly)
		{
			if ($this->recData['bankAccount'] !== '')
				$this->addStatic(['text' => $this->recData['bankAccount'], 'icon' => 'tables/e10doc.base.bankaccounts']);
		}
		else
		{
			$bankAccounts = [];
			if (isset($properties[$personNdx]['payments']))
			{
				foreach ($properties[$personNdx]['payments'] as $ba)
				{
					$bankAccounts[$ba['text']] = ['text' => $ba['text']];
					if (isset($ba['prefix']))
						$bankAccounts[$ba['text']]['suffix'] = $ba['prefix'];
				}
				if ($this->recData['bankAccount'] === '')
					$this->recData['bankAccount'] = key($bankAccounts);
				if (!isset($bankAccounts[$this->recData['bankAccount']]))
					$bankAccounts[$this->recData['bankAccount']] = $this->recData['bankAccount'];
			}
			if (count($bankAccounts))
			{
				$this->addStatic(['text' => 'Účet pro úhradu:', 'icon' => 'tables/e10doc.base.bankaccounts', 'class' => 'block']);
				$this->addInputEnum2('bankAccount', NULL, $bankAccounts);
			}
		}

		// -- address
		$addresses = [];
		$deliveryAddress = 0;
		$addressesOther1 = [];
		$other1Address = 0;

		$q = [];
		array_push($q, 'SELECT * FROM [e10_persons_address]');
		array_push($q, ' WHERE tableid = %s ', 'e10.persons.persons', ' AND recid = %i', $personNdx);
		array_push($q, ' AND [docState] != %i', 9800);

		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$title = [];
			if ($r['specification'] !== '')
				$title[] = ['text' => $r['specification']];
			if ($r['street'] !== '')
				$title[] = ['text' => $r['street']];
			$title[] = ['text' => $r['city'].' '.$r['zipcode']];

			if ($r['type'] !== 99)
			{
				$addresses[$r['ndx']] = [$title];
				if ($r['type'] == 4)
					$deliveryAddress = $r['ndx'];
			}
			if ($r['type'] === 99)
			{
				$addressesOther1[$r['ndx']] = [$title];
				$other1Address = $r['ndx'];
			}

			$classification = UtilsBase::loadClassification ($this->table->app(), 'e10.persons.address', [$r['ndx']], 'ml1 label');
			if (isset ($classification [$r['ndx']]))
			{
				forEach ($classification [$r['ndx']] as $clsfGroup)
				{
					if ($r['type'] == 99)
						$addressesOther1[$r['ndx']] = array_merge ($addressesOther1[$r['ndx']], $clsfGroup);
					if ($r['type'] != 99)
						$addresses[$r['ndx']] = array_merge ($addresses[$r['ndx']], $clsfGroup);
				}
			}
		}
		$this->addFormPersonInfo_Address ($addresses, 'deliveryAddress', $deliveryAddress, 'Doručovací adresa');
		$this->addFormPersonInfo_Address ($addressesOther1, 'otherAddress1', $other1Address, 'Provozovna');
	}

	public function addFormPersonInfo_Address ($addresses, $columnId, $suggestedAddressNdx, $labelText)
	{
		if ($this->readOnly)
		{
			if ($this->recData[$columnId])
			{
				$this->addStatic(['text' => $labelText, 'icon' => 'system/iconHome', 'class' => 'block']);
				$a = $addresses[$this->recData[$columnId]];
				$this->addStatic($a);
			}
		}
		else
		{
			$this->addStatic(['text' => $labelText, 'icon' => 'system/iconHome', 'class' => 'block']);
			$this->addInputEnum2($columnId, NULL, $addresses);
			if ($this->recData[$columnId] === 0 || !isset($addresses[$this->recData[$columnId]]))
				$this->recData[$columnId] = ($suggestedAddressNdx) ? $suggestedAddressNdx : key($addresses);
		}
	}
}
