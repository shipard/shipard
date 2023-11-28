<?php

namespace e10pro\zus\libs\ezk;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
use \Shipard\Utils\World;


/**
 * class AppSettings
 */
class AppSettings extends \Shipard\UI\ng\AppSettings
{
  var $students = [];
  var $contacts = [];

  protected function loadDataContacts($studentNdx)
  {
    $q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $studentNdx);
    array_push ($q, ' AND [contacts].[contactEmail] = %s', $this->app()->uiUser['email'] ?? '!');
    array_push ($q, ' AND [contacts].[docState] = %i', 4000);
		array_push ($q, ' ORDER BY [contacts].[onTop], [contacts].[systemOrder]');
    $rows = $this->db()->query($q);
    foreach ($rows as $item)
    {
      if ($item['flagAddress'])
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
        $ap[] = $country['t'];
        $addressText = implode(', ', $ap);
        $addressId = str_replace(' ', '', $addressText);
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

        $this->addresses[$addressId] = $address;
      }

      if ($item['flagContact'])
      {
        if ($item['contactEmail'] != '')
        {
          $cid = $item['contactEmail'];
          $this->contacts[$cid] = ['text' => $item['contactEmail'], 'icon' => 'system/iconEmail'];
        }
        if ($item['contactPhone'] != '')
        {
          $cid = $item['contactPhone'];
          $this->contacts[$cid] = ['text' => $item['contactPhone'], 'icon' => 'system/iconPhone'];
        }
      }
    }
  }

  protected function loadDataBalance()
  {
    if (count($this->students))
    {
      $pbi = new \e10doc\balance\libs\PersonsBalance($this->app());
      $pbi->setPersons(array_keys($this->students));
      $pbi->run();

      $this->uiTemplate->data['flags']['showBalance'] = 1;
      $this->uiTemplate->data['balance'] = $pbi->data;
      $this->uiTemplate->data['balance_json'] = json_encode($pbi->data);
    }
  }

  protected function loadData()
  {
    parent::loadData();

    $personNdx = $this->app->userNdx();
    if ($personNdx)
    { // -- student
      $this->loadPersonInfo($personNdx);
    }
    else
    { // -- user
      $userContexts = $this->app()->uiUserContext ();

      if (isset($userContexts['ezk']) && isset($userContexts['ezk']['students']))
      {
        foreach ($userContexts['ezk']['students'] as $studentNdx => $student)
        {
          if (!isset($student['studia']) || !count($student['studia']))
            continue;

          $this->students[$studentNdx] = $student;
          $this->loadDataContacts($studentNdx);

          $this->loadDataPersonProperties ($studentNdx);

          if (isset($this->uiTemplate->data['personsContacts']) && count($this->uiTemplate->data['personsContacts']))
          {
            $this->students[$studentNdx]['personsContacts'] = $this->uiTemplate->data['personsContacts'];
            $this->uiTemplate->data['personsContacts'] = [];
            $this->properties[$studentNdx]['contacts'] = [];
          }

          if (isset($this->uiTemplate->data['personsIds']) && count($this->uiTemplate->data['personsIds']))
          {
            $this->students[$studentNdx]['personsIds'] = $this->uiTemplate->data['personsIds'];
            $this->uiTemplate->data['personsIds'] = [];
            $this->properties[$studentNdx]['ids'] = [];
          }
        }
        $this->uiTemplate->data['students'] = array_values($this->students);
      }

      $this->uiTemplate->data['personsContacts'] = array_values($this->contacts);
      $this->uiTemplate->data['personsAddress'] = array_values($this->addresses);

      $this->loadDataBalance();
    }
  }

  public function run()
  {
    $this->uiSubTemplate = 'modules/e10pro/zus/libs/ezk/subtemplates/appSettings';
    parent::run();
  }
}
