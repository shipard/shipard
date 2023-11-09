<?php

namespace Shipard\UI\ng;
use \Shipard\Utils\World;


/**
 * class AppSettings
 */
class AppSettings extends \Shipard\Base\Utility
{
  var ?\Shipard\UI\ng\TemplateUI $uiTemplate = NULL;
  var $uiSubTemplate = '';

  /** @var \e10\persons\TablePersons */
  var $tablePersons;

  var $addresses = [];
  var $properties = [];

  var $resultData = [];

  protected function createCode()
  {
    $this->renderSubtemplate();
  }

	function loadDataPersonsBankAccounts($personNdx)
	{
		$q = [];
		array_push($q, 'SELECT * FROM [e10_persons_personsBA]');
		array_push($q, ' WHERE [person] = %i', $personNdx);
    array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' ORDER BY bankAccount');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->uiTemplate->data['personsBankAccounts'][] = [
				'bankAccount' => $r['bankAccount'],
			];
		}
  }

  function loadDataPersonProperties ($personNdx)
	{
		$this->properties = $this->tablePersons->loadProperties ($personNdx);

		if (isset ($this->properties[$personNdx]['contacts']))
		{
			$this->uiTemplate->data['personsContacts'] = $this->properties[$personNdx]['contacts'];
		}

		if (isset ($this->properties[$personNdx]['ids']))
		{
      $this->uiTemplate->data['personsIds'] = $this->properties[$personNdx]['ids'];
		}
	}

  function loadDataPersonAddresses($personNdx)
	{
		$this->addresses = [];

    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $personNdx);
    array_push ($q, ' AND [contacts].[flagAddress] = %i', 1);
		array_push ($q, ' ORDER BY [contacts].[onTop], [contacts].[systemOrder]');
    $rows = $this->db()->query($q);
    foreach ($rows as $item)
    {
      $address = [];

      $ap = [];

      if ($item['adrSpecification'] != '')
        $ap[] = $item['adrSpecification'];
      if ($item['adrStreet'] != '')
        $ap[] = $item['adrStreet'];
      if ($item['adrCity'] != '')
        $ap[] = $item['adrCity'];
      if ($item['adrZipCode'] != '')
        $ap[] = $item['adrZipCode'];

      $country = World::country($this->app(), $item['adrCountry']);
      $ap[] = /*$country['f'].' '.*/$country['t'];
      $addressText = implode(', ', $ap);

      $address = [
        'icon' => 'system/iconHome',
        'addressText' => $addressText,
      ];


      if ($item['flagMainAddress'])
        $address['type'][] = ['text' => 'Sídlo'];
      if ($item['flagPostAddress'])
        $address['type'][] = ['text' => 'Korespondenční'];
      if ($item['flagOffice'])
        $address['type'][] = ['text' => 'Provozovna'];

			$this->addresses[] = $address;
    }

    if (count($this->addresses))
      $this->uiTemplate->data['personsAddress'] = $this->addresses;
	}

  protected function loadPersonInfo($personNdx)
  {
    $personRecData = $this->tablePersons->loadItem($personNdx);
    $this->uiTemplate->data['userPerson'] = $personRecData;

    $this->loadDataPersonProperties ($personNdx);
    $this->loadDataPersonAddresses ($personNdx);
    $this->loadDataPersonsBankAccounts($personNdx);
  }

  protected function loadData()
  {
    $this->tablePersons = $this->app()->table('e10.persons.persons');
  }

  protected function renderSubtemplate()
  {
    $templateStr = $this->uiTemplate->subTemplateStr($this->uiSubTemplate);
		$code = $this->uiTemplate->render($templateStr);
    $this->resultData[] = [
      'code' => $code,
    ];
  }

  public function run()
  {
    $this->loadData();
    $this->createCode();
  }
}

