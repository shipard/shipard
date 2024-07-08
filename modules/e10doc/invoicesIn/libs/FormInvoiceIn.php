<?php

namespace e10doc\invoicesIn\libs;
use \Shipard\Utils\Utils;

class FormInvoiceIn extends \e10doc\core\FormHeads
{
	var $useAttInfoPanel = 0;

	public function renderForm ()
	{
		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

		$this->checkInfoPanelAttachments();
		$taxPayer = $this->recData['taxPayer'];
		$paymentMethod = $this->table->app()->cfgItem ('e10.docs.paymentMethods.' . $this->recData['paymentMethod'], 0);
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->table->app()->cfgItem ('e10.docs.dbCounters.'.$this->recData['docType'].'.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = Utils::param ($dbCounter, 'useDocKinds', 0);
		}

		$testDocsInboxFirst = intval($this->app()->cfgItem ('options.experimental.testDocsInboxFirst', 0));
		$usePropertyExpenses = $this->table->app()->cfgItem ('options.property.usePropertyExpenses', 0);

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', self::SIDEBAR_POS_RIGHT);

		$this->openForm (self::ltNone);
			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			$this->addAccountingTab ($tabs['tabs']);
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
			if ($testDocsInboxFirst)
			{
				$this->layoutOpen (self::ltGrid);
					$this->openRow();
					if (!$this->addImportButtons())
						$this->addList ('inbox', '', self::loAddToFormLayout|self::coColW12|self::coFocus);
					$this->closeRow();
				$this->layoutClose();
				$this->addSeparator(self::coH2);
				$this->addInboxListDone = 1;
			}

			$this->layoutOpen (self::ltHorizontal);
				$this->layoutOpen (self::ltForm);
					$this->addColumnInput ("person");
					$this->addColumnInput ("paymentMethod");

					if ($paymentMethod ['cash'])
						$this->addColumnInput ("cashBox");

					if ($testNewPersons)
						$this->addColumnInput ('bankAccount');
					else
						$this->addColumnInput ('bankAccount', self::coDisableCombo);

					$this->addColumnInput ("symbol1");
					$this->addColumnInput ("symbol2");
					$this->addColumnInput ("dateIssue");
					$this->addColumnInput ("dateDue");
          $this->addColumnInput ("dateAccounting");
					if ($taxPayer)
					{
						if ($this->recData['taxCalc'])
						{
							$this->openRow();
								$this->addColumnInput("dateTax");
								$this->addColumnInput('dateTaxDuty');
							$this->closeRow();
						}
						$this->addColumnInput ('docId');
					}
				$this->layoutClose ();

				$this->layoutOpen (self::ltForm);
					if ($taxPayer)
					{
						if ($this->useMoreVATRegs())
						{
							$this->addColumnInput ('vatReg');
							if ($this->vatRegs[$this->recData['vatReg']]['payerKind'] === 1)
								$this->addColumnInput ('taxCountry');
						}

						$this->addColumnInput ("taxCalc");
						if ($this->recData['taxCalc'])
						{
							$this->addColumnInput ("taxMethod");
							$this->addColumnInput ("taxType");
						}
					}
					$this->addCurrency();

          $this->addColumnInput ("roundMethod");

					if ($useDocKinds === 2)
						$this->addColumnInput ("docKind");

					$this->addSeparator();

					if ($this->table->app()->cfgItem ('options.core.useCentres', 0))
						$this->addColumnInput ('centre');
					if ($this->table->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
						$this->addColumnInput ('workOrder');
					if ($usePropertyExpenses)
						$this->addColumnInput ('property');
					if ($this->table->app()->cfgItem ('options.core.useProjects', 0))
						$this->addColumnInput ('project');
					if ($this->table->warehouses())
						$this->addColumnInput ('warehouse');
				$this->layoutClose ();

			$this->layoutClose ();

			$this->addRecapitulation ();

    	$this->closeTab ();

			$this->openTab ();
					$this->addList ('rows');
			$this->closeTab ();

			$this->addAccountingTabContent();
			$this->addAttachmentsTabContent ();

			$this->openTab ();
				if ($paymentMethod['askPersonBalance'] ?? 0)
				{
					$this->addColumnInput('askPersonBalance');
					if ($this->recData['askPersonBalance'])
						$this->addColumnInput('personBalance');
				}
				$this->addColumnInput ("author");
				if ($taxPayer)
					$this->addVatSettingsIn ();
				if ($useDocKinds !== 2)
					$this->addColumnInput ("docKind");
			$this->closeTab ();

			$this->closeTabs ();

		$this->closeForm ();

		$this->addInfoPanelAttachments();
  }

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		if (!isset($this->recData ['dateDue']) || Utils::dateIsBlank($this->recData ['dateDue']))
		{
			$this->recData ['dateDue'] = new \DateTime ();
			$this->recData ['dateDue']->add (new \DateInterval('P' . $this->app()->cfgItem ('e10.options.dueDays', 14) . 'D'));
		}
	}

  function columnLabel ($colDef, $options)
  {
    switch ($colDef ['sql'])
    {
      case	'person': return 'Dodavatel';
			case	'personVATIN': return 'DIČ dodavatele';
		}
    return parent::columnLabel ($colDef, $options);
  }

	protected function addImportButtons()
	{
		$testDocsInboxImport = intval($this->app()->cfgItem ('options.experimental.testDocsInboxImport', 0));
		if (!$testDocsInboxImport)
			return FALSE;

		if (!isset($this->recData['ndx']) || $this->recData['ndx'] == 0)
			return FALSE;
		if ($this->recData['docState'] !== 1000)
			return FALSE;
		if (!isset($this->recData['importedFromAtt']) || $this->recData['importedFromAtt'] !== 0)
			return FALSE;
		if (!isset($this->recData['importedFromIssue']) || $this->recData['importedFromIssue'] !== 0)
			return FALSE;

		// -- load inbox
		$inboxPks = [];
		$q = [];
		array_push($q, 'SELECT dstRecId FROM e10_base_doclinks WHERE 1');
		array_push($q, ' AND srcTableId = %s', 'e10doc.core.heads', ' AND srcRecId = %i', $this->recData['ndx']);
		array_push($q, ' AND dstTableId = %s', 'wkf.core.issues', 'AND [linkId] = %s', 'e10docs-inbox');
		array_push($q, ' ORDER BY ndx');
		$rows = $this->table->db()->query ($q);
		foreach ($rows as $r)
		{
			$inboxPks[] = $r['dstRecId'];
		}

		// -- attachments with ddf
		$qa = [];
		array_push($qa, 'SELECT * FROM [e10_attachments_files]');
		array_push($qa, ' WHERE [recid] IN %in', $inboxPks);
		array_push($qa, ' AND [tableid] = %s', 'wkf.core.issues');
		array_push($qa, ' AND [deleted] = %i', 0);
		array_push($qa, ' AND [ddfNdx] != %i', 0);
		$rows = $this->table->db()->query ($qa);
		foreach ($rows as $r)
		{
			$btnTxt = 'Importovat';
			$btnCode = '';
			$btnCode .= "<div class='btn-group'>";
			$btnCode .= "<button class='btn btn-large btn-primary df2-action-trigger' data-action='saveform' data-noclose='1'";
			$btnCode .= " data-save-import-attNdx='{$r['ndx']}'";
			$btnCode .= " data-save-import-issueNdx='{$r['recid']}'";
			$btnCode .= " data-fid='".$this->fid."' data-form='{$this->fid}' data-docstate='99001'>";
			$btnCode .= $this->app()->ui()->icons()->icon('system/iconImport');
			$btnCode .= ' '.Utils::es($btnTxt);
			$btnCode .= "</button>";

			$btnCode .= "<button class='btn df2-action-trigger btn-primary' data-action='editform' data-table='e10.base.docDataFiles'";
			$btnCode .= " data-pk='{$r['ddfNdx']}'>";
			$btnCode .= $this->app()->ui()->icons()->icon('system/actionOpen');
			$btnCode .= "</i></button>";

			$btnCode .= "</div>";

			$this->addList ('inbox', '', self::loAddToFormLayout|self::coColW9|self::coFocus);
			$this->appendElement ($btnCode, NULL, 'e10-gl-col3 e10-right');

			return TRUE;
		}

		return FALSE;
	}
}

