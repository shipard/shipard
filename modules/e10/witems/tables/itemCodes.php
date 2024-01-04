<?php

namespace e10\witems;
use \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableItemCodes
 */
class TableItemCodes extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.witems.itemCodes', 'e10_witems_itemCodes', 'Kódy položek');
	}

  public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

    $codeKind = $this->app()->cfgItem('e10.witems.codesKinds.'.$recData['codeKind']);
    $refType = $codeKind['refType'] ?? 0;
    $askDir = $codeKind['askDir'] ?? 0;
    $askPerson = $codeKind['askPerson'] ?? 0;
    $askPersonsGroup = $codeKind['askPersonsGroup'] ?? 0;
    $askAddressLabel = $codeKind['askAddressLabel'] ?? 0;
    $askPersonType = $codeKind['askPersonType'] ?? 0;

    if ($refType == 1)
    {
      $ni = $this->db()->query('SELECT * FROM [e10_base_nomencItems] WHERE [ndx] = %i', $recData['itemCodeNomenc'])->fetch();
      if ($ni)
      {
        $recData['itemCodeText'] = $ni['itemId'];
      }
    }
    else
      $recData['itemCodeNomenc'] = 0;

    if (!$askPerson)
      $recData['person'] = 0;
    if (!$askPersonType)
      $recData['personType'] = 0;
    if (!$askDir)
      $recData['codeDir'] = 0;
    if (!$askPersonsGroup)
      $recData['personsGroup'] = 0;
    if (!$askAddressLabel)
      $recData['addressLabel'] = 0;

    $recData ['systemOrder'] = 99;

    if ($recData['codeDir'])
      $recData ['systemOrder']--;

    if ($recData['personType'])
      $recData ['systemOrder']--;

    if ($recData['person'])
      $recData ['systemOrder'] -= 10;

    if ($recData['personsGroup'] != 0)
      $recData ['systemOrder']--;

    if ($recData['addressLabel'] != 0)
      $recData ['systemOrder']--;
  }
}


/**
 * Class FormItemCode
 */
class FormItemCode extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

    $codeKind = $this->app()->cfgItem('e10.witems.codesKinds.'.$this->recData['codeKind']);

    $refType = $codeKind['refType'] ?? 0;
    $askDir = $codeKind['askDir'] ?? 0;
    $askPerson = $codeKind['askPerson'] ?? 0;
    $askPersonsGroup = $codeKind['askPersonsGroup'] ?? 1;
    $askAddressLabel = $codeKind['askAddressLabel'] ?? 0;
    $askPersonType = $codeKind['askPersonType'] ?? 0;

		$this->openForm (TableForm::ltGrid);
			$this->openRow();
				$this->addColumnInput ('codeKind', self::coColW4);
				//$this->addColumnInput ('itemCodeText', self::coColW8);
        //$this->addInputIntRef ('itemCodeRef', 'e10.base.nomencItems', 'Test', self::coColW8);
        if ($refType === 1)
          $this->addColumnInput ('itemCodeNomenc', self::coColW8);
        else
          $this->addColumnInput ('itemCodeText', self::coColW8);
			$this->closeRow();

      if ($askDir || $askPerson || $askPersonsGroup || $askAddressLabel || $askPersonType)
      {
        if ($askPerson && $askAddressLabel && $askPersonsGroup && $askPersonType)
        {
          $this->openRow();
            $this->addColumnInput ('codeDir', self::coColW2);
            $this->addColumnInput ('personType', self::coColW2);
            $this->addColumnInput ('person', self::coColW8);
          $this->closeRow();
          $this->openRow();
            $this->addColumnInput ('addressLabel', self::coColW6);
            $this->addColumnInput ('personsGroup', self::coColW6);
          $this->closeRow();
        }
        elseif ($askDir && $askPerson && $askPersonsGroup && !$askPersonType)
        {
          $this->openRow();
            $this->addColumnInput ('codeDir', self::coColW2);
            $this->addColumnInput ('personsGroup', self::coColW5);
            $this->addColumnInput ('person', self::coColW5);
          $this->closeRow();
        }
        elseif ($askDir && $askAddressLabel && $askPersonsGroup && $askPersonType)
        {
          $this->openRow();
          $this->addColumnInput ('codeDir', self::coColW2);
          $this->addColumnInput ('personType', self::coColW2);
          $this->addColumnInput ('addressLabel', self::coColW4);
          $this->addColumnInput ('personsGroup', self::coColW4);
          $this->closeRow();
        }
        elseif ($askDir && $askAddressLabel && $askPersonsGroup)
        {
          $this->openRow();
            $this->addColumnInput ('codeDir', self::coColW2);
            $this->addColumnInput ('addressLabel', self::coColW5);
            $this->addColumnInput ('personsGroup', self::coColW5);
          $this->closeRow();
        }
        elseif ($askDir && $askPerson)
        {
          $this->openRow();
            $this->addColumnInput ('codeDir', self::coColW2);
            $this->addColumnInput ('person', self::coColW10);
          $this->closeRow();
      }
        elseif ($askDir && !$askPerson)
        {
          $this->openRow();
            $this->addColumnInput ('codeDir', self::coColW4);
          $this->closeRow();
      }
        elseif (!$askDir && $askPerson)
        {
          $this->openRow();
            $this->addColumnInput ('person', self::coColW12);
          $this->closeRow();
        }
      }

      $this->openRow();
        $this->addColumnInput ('validFrom', self::coColW4);
        $this->addColumnInput ('validTo', self::coColW4);
      $this->closeRow();
		$this->closeForm ();
	}
}
