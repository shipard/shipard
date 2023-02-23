<?php

namespace e10pro\zus\libs\dc;


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



    $t = [];

    $t[] = ['t' => 'Student', 'v' => $this->recData['lastNameS'].' '.$this->recData['firstNameS']];
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

  public function createContent ()
	{
    $this->addCoreInfo();
    $this->addContentAttachments ($this->recData ['ndx']);
	}
}
