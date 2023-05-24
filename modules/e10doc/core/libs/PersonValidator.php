<?php

namespace e10doc\core\libs;
use \Shipard\Base\Utility;

/**
 * class PersonValidator
 */
class PersonValidator extends Utility
{
  var $maxCount = 5;
  var $debug = 0;

  public function batchCheck()
  {
		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
    if (!$testNewPersons)
      return;

    $q[] = 'SELECT * FROM [e10_persons_persons] as persons ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' AND [company] = %i', 1);
    array_push($q, ' AND [disableRegsChecks] = %i', 0);

    array_push($q, ' AND (');
    array_push($q, ' EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND [valid] = %i)', 0);
    array_push($q, ' OR NOT EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person)');
    array_push($q, ')');

		array_push($q, ' LIMIT 0, %i', $this->maxCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      if ($this->debug)
        echo "* ".$r['fullName']."\n";
      $pv = new \e10\persons\libs\register\Validator($this->app());
      $pv->setPersonNdx($r['ndx']);
      $pv->checkPerson();

      sleep(1);
		}
  }

  public function batchRepair()
  {
		$testNewPersons = intval($this->app()->cfgItem ('options.persons.testNewPersons', 0));
    if (!$testNewPersons)
      return;

    $q[] = 'SELECT * FROM [e10_persons_persons] as persons ';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' AND [company] = %i', 1);
    array_push($q, ' AND [disableRegsChecks] = %i', 0);

    array_push($q, ' AND (');
    array_push($q, ' EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person AND [valid] = %i)', 2);
    array_push($q, ')');

		array_push($q, ' LIMIT 0, %i', $this->maxCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      if ($this->debug)
        echo "* ".$r['fullName']."\n";
      $pv = new \e10\persons\libs\register\Validator($this->app());
      $pv->setPersonNdx($r['ndx']);
      $pv->checkPerson(1);
		}
  }
}