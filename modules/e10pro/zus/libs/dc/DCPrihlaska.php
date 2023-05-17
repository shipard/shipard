<?php

namespace e10pro\zus\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCPrihlaska
 */
class DCPrihlaska extends \Shipard\Base\DocumentCard
{
  public function addCoreInfo()
  {
    $pobocka = $this->app()->loadItem($this->recData['misto'], 'e10.base.places');
    $obor = $this->app()->loadItem($this->recData['svpObor'], 'e10pro.zus.obory');
    $oddeleni = $this->app()->loadItem($this->recData['svpOddeleni'], 'e10pro.zus.oddeleni');

    $pidLabels = [['text' => $this->recData['rodneCislo'], 'class' => '']];
    $existedStudentNdx = $this->checkPID($this->recData['rodneCislo'], $pidLabels);

    $t = [];

    $studentInfo = [];

    $testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));

    if (!$this->recData['dstStudent'])
    {
      $studentInfo [] = ['text' => $this->recData['lastNameS'].' '.$this->recData['firstNameS'], 'class' => 'block'];
      if ($testNewPersons)
      {
        if (!$existedStudentNdx)
        {
          $studentInfo [] = [
            'type' => 'action', 'action' => 'addwizard',
            'text' => 'Vytvořit', 'data-class' => 'e10pro.zus.libs.WizardGenerateFromEntries',
            'icon' => 'cmnbkpRegenerateOpenedPeriod',
            'class' => 'pull-right'
          ];
        }
        else
        {
          $studentInfo [] = [
            'type' => 'action', 'action' => 'addwizard',
            'text' => 'Student existuje, propojit', 'data-class' => 'e10pro.zus.libs.WizardLinkEntryToStudent',
            'icon' => 'cmnbkpRegenerateOpenedPeriod',
            'class' => 'pull-right'
          ];
        }
      }
    }
    else
    {
      $studentInfo [] = [
        'text' => $this->recData['lastNameS'].' '.$this->recData['firstNameS'],
        'docAction' => 'edit',
        'table' => 'e10.persons.persons',
        'pk' => $this->recData['dstStudent'],
      ];
    }

    $t[] = ['t' => 'Student', 'v' => $studentInfo];

    $t[] = ['t' => 'Datum narození', 'v' => Utils::datef($this->recData['datumNarozeni'])];
    $t[] = ['t' => 'Rodné číslo', 'v' => $pidLabels];
    $t[] = ['t' => 'Bydliště', 'v' => $this->recData['street'].', '.$this->recData['city'].', '.$this->recData['zipcode']];
    $t[] = ['t' => 'Obor', 'v' => $obor['nazev']];
    $t[] = ['t' => 'Studijní zaměření', 'v' => $oddeleni['nazev']];
    $t[] = ['t' => 'Pobočka', 'v' => $pobocka['fullName']];

    $zz1 = [];
    $zz1[] = ['text' => $this->recData['fullNameM'], 'class' => 'e10-bold block'];
    $zz1[] = ['text' => $this->recData['emailM'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
    $zz1[] = ['text' => $this->recData['phoneM'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];
    if ($this->recData['useAddressM'])
    {
      $zz1[] = ['text' => $this->recData['streetM'].', '.$this->recData['cityM'].', '.$this->recData['zipcodeM'], 'class' => 'break'];
    }
    $t[] = ['t' => 'Zákonný zástupce 1', 'v' => $zz1];

    $zz2 = [];
    if ($this->recData['fullNameF'] !== '')
      $zz2[] = ['text' => $this->recData['fullNameF'], 'class' => 'e10-bold block'];
    if ($this->recData['emailF'] !== '')
      $zz2[] = ['text' => $this->recData['emailF'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
    if ($this->recData['phoneF'])
      $zz2[] = ['text' => $this->recData['phoneF'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];
    if ($this->recData['useAddressF'])
    {
      $zz2[] = ['text' => $this->recData['streetF'].', '.$this->recData['cityF'].', '.$this->recData['zipcodeF'], 'class' => 'break'];
    }
    if (count($zz2))
      $t[] = ['t' => 'Zákonný zástupce 2', 'v' => $zz2];


    $h = ['t' => '', 'v' => ''];

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);
  }

  protected function checkPID($pid, &$labels)
  {
    $studentNdx = 0;

		$q = [];
		array_push($q, 'SELECT props.*, persons.fullName AS fullNameS');
		array_push($q, ' FROM e10_base_properties AS props');
		array_push($q, ' LEFT JOIN e10_persons_persons AS persons ON props.recid = persons.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND props.[group] = %s', 'ids');
		array_push($q, ' AND props.[property] = %s', 'pid');
		array_push($q, ' AND props.[tableid] = %s', 'e10.persons.persons');
    array_push($q, ' AND props.[valueString] = %s', $pid);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      $labels[] = [
        'text' => $r['fullNameS'], 'class' => 'pull-right', 'icon' => 'system/iconUser',
        'docAction' => 'edit', 'pk' => $r['recid'], 'table' => 'e10.persons.persons'
      ];

      $studentNdx = $r['recid'];
    }

    return $studentNdx;
  }

  public function createContent ()
	{
    $this->addCoreInfo();
    $this->addContentAttachments ($this->recData ['ndx']);
	}
}
