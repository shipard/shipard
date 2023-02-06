<?php

namespace e10doc\purchase\libs;


use e10doc\core\e10utils;
use E10\utils;
use \Shipard\Application\DataModel;
use \e10\base\libs\UtilsBase;


class FormPurchaseDocs extends \e10doc\core\FormHeads
{
	var $testNewPersons = 0;

	public function renderForm ()
	{
		$this->testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

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

					if ($this->recData['person'])
					{
						$regTitle = [['text' => 'Registrace', 'icon' => 'system/iconUser', 'class' => 'h2']];
						$regTitle[] = [
							'text' => 'Tisk', 'class' => 'pull-right', 'type' => 'action', 'action' => 'printdirect', 'printer' => '1',
							'data-report' => 'e10pro.custreg.RegistrationListReport', 'actionClass' => 'btn-sm',
							'data-table' => 'e10.persons.persons', 'data-pk' => $this->recData['person']
						];
						if (!$this->readOnly)
						{
							$regTitle[] = [
									'text' => 'Upravit', 'class' => 'pull-right', 'docAction' => 'edit',
									'actionClass' => 'btn btn-primary btn-sm', 'type' => 'button', 'icon' => 'system/actionOpen',
									'table' => 'e10.persons.persons', 'pk' => $this->recData['person'],
									'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid
							];
						}
						$this->layoutOpen (self::ltForm, 'xxe10-row-info default stripped');
							$this->addStatic($regTitle, self::coHeader);
							$this->addFormPersonInfo();
						$this->layoutClose ();

						if ($this->recData['personType'] == 2)
						{
							$this->addSeparator(self::coH2);
								$this->layoutOpen(self::ltVertical);
								$this->addColumnInput ('personHandover', self::coHeader);
								$this->addColumnInput ('cashPersonName', self::coColW12);
								$this->addColumnInput ('cashPersonID', self::coColW12);
							$this->layoutClose();
						}
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
				$this->addColumnInput ('owner');
				$this->addColumnInput ('ownerOffice');
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
		if ($this->recData['personType'] == 1)
		{ // people
			if ($this->readOnly)
			{
				if ($this->recData['bankAccount'] !== '')
					$this->addStatic(['text' => $this->recData['bankAccount'], 'icon' => 'tables/e10doc.base.bankaccounts']);
			}
			else
			{
				$bankAccounts = [];

				if ($this->testNewPersons)
				{
					$qba [] = 'SELECT [ba].* ';
					array_push ($qba, ' FROM [e10_persons_personsBA] AS [ba]');
					array_push ($qba, ' WHERE 1');
					array_push ($qba, ' AND [ba].[person] = %i', $personNdx);
					$baRows = $this->app()->db()->query($qba);
					foreach ($baRows as $ba)
					{
						$bankAccounts[$ba['bankAccount']] = [['text' => $ba['bankAccount']]];
						if (!$this->readOnly)
						{
							$bankAccounts[$ba['bankAccount']][] = [
								'text' => '', 'class' => 'pull-right', 'docAction' => 'edit',
								'_actionClass' => 'pull-right', 'type' => 'span', 'icon' => 'system/actionOpen',
								'table' => 'e10.persons.personsBA', 'pk' => $ba['ndx'],
								'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid
							];
						}
					}
				}
				else
				{
					if (isset($properties[$personNdx]['payments']))
					{
						foreach ($properties[$personNdx]['payments'] as $ba)
						{
							$bankAccounts[$ba['text']] = ['text' => $ba['text']];
							if (isset($ba['prefix']))
								$bankAccounts[$ba['text']]['suffix'] = $ba['prefix'];
						}
					}
				}
				if ($this->recData['bankAccount'] === '' || !isset($bankAccounts[$this->recData['bankAccount']]))
				{
					$this->recData['bankAccount'] = key($bankAccounts);
					if (!$this->recData['bankAccount'])
						$this->recData['bankAccount'] = '';
				}
				$baLabel = [['text' => 'Účet pro úhradu:', 'icon' => 'tables/e10doc.base.bankaccounts', 'class' => 'h4']];
				if ($this->testNewPersons)
				$baLabel[] = [
					'text' => '', 'class' => 'pull-right', 'docAction' => 'new',
					'type' => 'span', 'icon' => 'system/actionAdd',
					'table' => 'e10.persons.personsBA',
					'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
					'addParams' => '__person='.$this->recData['person']
				];
				if (count($bankAccounts))
				{
					$this->addStatic($baLabel);
					$this->addInputEnum2('bankAccount', NULL, $bankAccounts);
				}
				else
				{
					$baLabel[] = ['text' => 'Není zadán žádný účet', 'icon' => 'system/iconWarning', 'class' => 'e10-error break'];
					$this->addStatic($baLabel);
				}
			}
		}

		if ($this->recData['personType'] == 1)
		{ // human
			$this->recData['otherAddress1Mode'] = 0;
		}
		else
		{
			$this->addInputEnum2('otherAddress1Mode', NULL, ['0' => 'Provozovna', '1' => 'ORP'], self::INPUT_STYLE_RADIO, DataModel::coSaveOnChange|self::coInline);
		}

		// -- address
		if ($this->recData['otherAddress1Mode'] == 0)
		{
			$addrPosts = [];
			$addrOffices = [];
			$suggestedAddrPost = 0;
			$suggestedAddrOffice = 0;

			$q = [];
			array_push($q, 'SELECT [addrs].*');
			array_push($q, ' FROM [e10_persons_personsContacts] AS [addrs]');
			array_push($q, ' WHERE [addrs].[person] = %i', $personNdx);
			array_push($q, ' AND [addrs].[docState] = %i', 4000);
			array_push($q, ' AND [addrs].[flagAddress] = %i', 1);
			array_push($q, ' ORDER BY [addrs].[onTop], [addrs].[systemOrder], [addrs].[adrCity]');
			$rows = $this->app()->db()->query($q);
			foreach ($rows as $r)
			{
				$title = [];
				if (!$this->readOnly)
				{
					$title[] = [
							'text' => '', 'class' => 'pull-right', 'docAction' => 'edit',
							'_actionClass' => 'pull-right', 'type' => 'span', 'icon' => 'system/actionOpen',
							'table' => 'e10.persons.personsContacts', 'pk' => $r['ndx'],
							'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid
					];
				}
				$ap = [];
				if ($r['adrSpecification'] !== '')
					$ap[] = $r['adrSpecification'];
				if ($r['adrStreet'] !== '')
					$ap[] = $r['adrStreet'];
				if ($r['adrCity'] !== '')
					$ap[] = $r['adrCity'];
				if ($r['adrZipCode'] !== '')
					$ap[] = $r['adrZipCode'];

				$title[] = ['text' => implode(', ', $ap), 'class' => ''];

				if ($r['flagOffice'])
					$title[] = ['text' => 'IČP: '.$r['id1'], 'class' => 'label label-default'];
				if ($r['flagMainAddress'])
					$title[] = ['text' => 'Sídlo', 'class' => 'label label-default'];

				$addrPosts[$r['ndx']] = [$title];
				$suggestedAddrPost = $r['ndx'];

				if ($r['flagOffice'] || $r['flagMainAddress'])
				{
					$addrOffices[$r['ndx']] = [$title];
					$suggestedAddrOffice = $r['ndx'];
				}
			}
			if ($this->recData['personType'] == 1)
			{
				if ($this->readOnly)
				{
					$addrTitle = 'Doručovací adresa';
				}
				else
				{
					$this->recData['otherAddress1'] = 0;
					$addrTitle = [
						['text' => 'Doručovací adresa:', 'icon' => 'system/iconHome', 'class' => 'h4'],
						[
							'text' => '', 'class' => 'pull-right', 'docAction' => 'new',
							'type' => 'span', 'icon' => 'system/actionAdd',
							'table' => 'e10.persons.personsContacts',
							'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
							'addParams' => '__person='.$this->recData['person'].'&__flagAddress=1&__flagPostAddress=1'
						]
					];
				}
				$this->addFormPersonInfo_Address ($addrPosts, 'deliveryAddress', $suggestedAddrPost, $addrTitle);
			}
			if ($this->recData['personType'] == 2)
			{
				if ($this->readOnly)
				{
					$addrTitle = 'Provozovna';
				}
				else
				{
					$this->recData['deliveryAddress'] = 0;
					$addrTitle = [
						['text' => 'Provozovna:', 'icon' => 'system/iconHome', 'class' => 'h4'],
						[
							'text' => '', 'class' => 'pull-right', 'docAction' => 'new',
							'type' => 'span', 'icon' => 'system/actionAdd',
							'class' => 'pull-right',
							'table' => 'e10.persons.personsContacts',
							'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
							'addParams' => '__person='.$this->recData['person'].'&__flagAddress=1&__flagOffice=1'
						],
					];

					if (1)
					{
						$companyIds = Utils::searchArray($properties[$personNdx]['ids'], 'pid', 'oid');
						$companyId = '';
						if (isset($companyIds['text']))
							$companyId = trim($companyIds['text']);
						if ($companyId !== '')
						{
							$addrTitle[] = [
								'text' => '', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'user/wifi',
								'title' => 'Načíst provozovny',
								'class' => 'pull-right',
								'element' => 'span',
								'btnClass' => 'pull-right',
								'data-class' => 'e10.persons.libs.register.AddOfficesWizard',
								'table' => 'e10.persons.persons',
								'data-addparams' => 'personId='.$companyId.'&personNdx='.$personNdx,
								'data-srcobjecttype' => 'form-to-save', 'data-srcobjectid' => $this->fid,
							];
						}
					}
				}
				if (count($addrOffices) > 1)
				{
					$fk = key($addrOffices);
					unset($addrOffices[$fk]);
				}

				$this->addFormPersonInfo_Address ($addrOffices, 'otherAddress1', $suggestedAddrOffice, $addrTitle);
			}
		}
		else
		{
			$this->addColumnInput('personNomencCity', self::coNoLabel);
		}
	}

	public function addFormPersonInfo_Address ($addresses, $columnId, $suggestedAddressNdx, $labelText)
	{
		$classification = UtilsBase::loadClassification ($this->table->app(), 'e10.persons.personsContacts', array_keys($addresses), 'ml1 label');
		foreach ($classification as $pcNdx => $clsf)
		{
			forEach ($clsf as $clsfGroup)
			{
				$addresses[$pcNdx] = array_merge ($addresses[$pcNdx], $clsfGroup);
			}
		}

		if ($this->readOnly)
		{
			if ($this->recData[$columnId])
			{
				if (is_string($labelText))
					$this->addStatic(['text' => $labelText, 'icon' => 'system/iconHome', 'class' => 'block']);
				else
					$this->addStatic($labelText);
				$a = $addresses[$this->recData[$columnId]];
				$this->addStatic($a);
			}
		}
		else
		{
			if (is_string($labelText))
				$this->addStatic(['text' => $labelText, 'icon' => 'system/iconHome', 'class' => 'block']);
			else
				$this->addStatic($labelText);
			$this->addInputEnum2($columnId, NULL, $addresses, self::INPUT_STYLE_RADIO, self::coBorder);
			if ($this->recData[$columnId] === 0 || !isset($addresses[$this->recData[$columnId]]))
				$this->recData[$columnId] = ($suggestedAddressNdx) ? $suggestedAddressNdx : key($addresses);
		}
	}

	public function checkBeforeSave (&$saveData)
	{
		parent::checkBeforeSave($saveData);

		if ($saveData ['recData']['personType'] == 1)
		{ // human
			$saveData ['recData']['otherAddress1'] = 0;
		}
		elseif ($saveData ['recData']['personType'] == 2)
		{ // company
			$saveData ['recData']['deliveryAddress'] = 0;
		}
	}
}
