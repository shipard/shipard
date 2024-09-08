<?php

namespace e10pro\vendms\libs;
use \Shipard\Base\Utility;


/**
 * class ObjectValidateCode
 */
class ObjectValidateCode extends Utility
{
  var $requestParams = NULL;
  var $result = ['success' => 0];

  public function checkData()
  {
    $this->result ['validPerson'] = 0;

    $cardCode = $this->requestParams['cardCode'] ?? '';
    if ($cardCode === '')
    {
      $this->result ['msg'] = 'Invalid card code';
    }

    // -- check person
    $q = [];
    array_push ($q,'SELECT props.recid AS propRecId,');
    array_push ($q,' persons.fullName AS personName');
		array_push ($q,' FROM [e10_base_properties] AS props');
    array_push ($q,' LEFT JOIN [e10_persons_persons] AS persons ON props.recid = persons.ndx');
    array_push ($q,' WHERE 1');
		array_push ($q,' AND [tableid] = %s', 'e10.persons.persons');
		array_push ($q,' AND [group] = %s', 'chips', ' AND property = %s', 'chipid');
    array_push ($q,' AND [valueString] = %s', $cardCode);

    $person = $this->db()->query($q)->fetch();
    if ($person)
    {
      $this->result ['validPerson'] = 1;
      $this->result ['personNdx'] = $person['propRecId'];
      $this->result ['personName'] = $person['personName'];
      $this->result ['creditAmount'] = $this->personsCredit($person['propRecId']);

      $this->result ['success'] = 1;
    }
  }

  protected function personsCredit($personNdx)
  {
    $c = $this->db()->query('SELECT SUM(amount) AS totalCredit FROM [e10pro_vendms_credits] WHERE [person] = %i', $personNdx)->fetch();
    if ($c)
      return $c['totalCredit'];

    return 0;
  }

  public function run()
  {
    $this->checkData();
  }
}
