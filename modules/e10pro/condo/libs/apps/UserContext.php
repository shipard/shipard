<?php

namespace e10pro\condo\libs\apps;


/**
 * class UserContext
 */
class UserContext extends \e10\users\libs\UserContext
{
  var $flats = [];

  protected function loadFlats()
  {
		$q [] = 'SELECT workOrders.*, ';
		array_push ($q, ' customers.fullName as customerFullName ');
		array_push ($q, ' FROM [e10mnf_core_workOrders] as workOrders');
		array_push ($q, ' LEFT JOIN e10_persons_persons as customers ON workOrders.customer = customers.ndx');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND workOrders.[docStateMain] IN (0, 1)');
    array_push ($q, ' AND EXISTS (SELECT person FROM e10mnf_core_workOrdersPersons WHERE workOrders.ndx = e10mnf_core_workOrdersPersons.workOrder',
                          ' AND [person] = %i', $this->contextCreator->userRecData['person'], ')');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      if (!isset($this->flats[$r['ndx']]))
      {
        $this->flats[$r['ndx']] = [
          'fullName' => $r['title'],
          'customerNdx' => $r['customer'],
          //'firstName' => $r['personFirstName'],
          //'lastName' => $r['personLastName'],
        ];
      }
    }
  }

  protected function loadAll()
  {
    $this->loadFlats();

    $this->contextCreator->contextData['condo']['flats'] = $this->flats;

    foreach ($this->flats as $flatNdx => $flatInfo)
    {
      $cid = 'condo-f-'.$flatNdx;
      $uc = [
        'id' => $cid,
        'title' => $flatInfo['fullName'],
        'shortTitle' => $flatInfo['fullName'],//$studentInfo['firstName'],
        'flatNdx' => $flatNdx,
        'customerNdx' => $flatInfo['customerNdx'],
      ];

      $this->contextCreator->contextData['contexts'][$cid] = $uc;
    }
  }

  public function run()
  {
    $this->loadAll();
  }
}


