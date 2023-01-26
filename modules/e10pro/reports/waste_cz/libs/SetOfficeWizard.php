<?php

namespace e10pro\reports\waste_cz\libs;
use \Shipard\Form\Wizard;
use \e10pro\reports\waste_cz\libs\WasteReturnEngine;


/**
 * class SetOfficeWizard
 */
class SetOfficeWizard extends Wizard
{
	public function doStep ()
	{
		if ($this->pageNumber == 1)
		{
			$this->doIt ();
		}
	}

	public function createHeader ()
	{
		$hdr = [];
		$hdr ['icon'] = 'system/actionPlay';

		$hdr ['info'][] = ['class' => 'title', 'value' => 'Nastavit provozovnu'];

		return $hdr;
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->recData['personNdx'] = $this->app->testGetParam('personNdx');
		$dir = $this->app->testGetParam('dir');
		if ($dir == WasteReturnEngine::rowDirIn)
			$this->recData['resetPurchases'] = 1;
		elseif ($dir == WasteReturnEngine::rowDirOut)
			$this->recData['resetInvoices'] = 1;

		$this->openForm ();
			$this->addInput('personNdx', '', self::INPUT_STYLE_STRING, self::coHidden, 30);
			$this->addCheckBox('resetPurchases', 'Výkupy', '1', self::coRightCheckbox);
			$this->addCheckBox('resetInvoices', 'Faktury', '1', self::coRightCheckbox);
			$this->addCheckBox('resetAllDocs', 'Nastavit na VŠECH dokladech, nejen na těch BEZ provozovny', '1', self::coRightCheckbox);
			$this->initWelcome();
		$this->closeForm ();
	}

	function initWelcome ()
	{
    $this->addViewerWidget ('e10.persons.personsContacts', 'e10.persons.libs.viewers.ViewPersonContactsOffices',
                            ['personNdx' => strval($this->recData['personNdx'])],
                            TRUE, TRUE);
	}

	public function doIt ()
	{
		$personNdx = intval($this->recData['personNdx']);
		$resetAllDocs = intval($this->recData['resetAllDocs'] ?? 0);
		$officeNdx = intval($this->recData['viewersPks'][0] ?? 0);

    if (!$personNdx || !$officeNdx)
    {
      return;
    }

		$docTypes = [];
		if ($this->recData['resetInvoices'])
			$docTypes[] = 'invno';
		if ($this->recData['resetPurchases'])
			$docTypes[] = 'purchase';

    $wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);

    $q = ['SELECT * FROM [e10doc_core_heads]'];
    array_push($q, ' WHERE [person] = %i', $personNdx);
    array_push($q, ' AND [dateAccounting] >= %d', '2022-01-01');
		array_push($q, ' AND [docType] IN %in', $docTypes);

		array_push($q, ' AND [otherAddress1Mode] = %i', 0);
		if (!$resetAllDocs)
    	array_push($q, ' AND ([otherAddress1] IS NULL OR [otherAddress1] = %i)', 0);
    $rows = $this->app()->db()->query($q);
    foreach ($rows as $r)
    {
      $this->app()->db()->query('UPDATE [e10doc_core_heads] SET otherAddress1 = %i', $officeNdx, ' WHERE [ndx] = %i', $r['ndx']);
      $wre->year = 2022;

      $wre->resetDocument($r['ndx']);
    }

		$this->stepResult ['close'] = 1;
	}
}
