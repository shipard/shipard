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
    array_push($q, ' EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person');
      array_push($q, ' AND ([valid] = %i', 0, ' OR ([valid] = %i', 1, ' AND [revalidate] = %i))', 1);
      array_push($q, ')');
    array_push($q, ' OR NOT EXISTS (SELECT ndx FROM e10_persons_personsValidity WHERE persons.ndx = person)');
    array_push($q, ')');

		array_push($q, ' LIMIT 0, %i', $this->maxCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      if ($this->debug)
        echo "* ".$r['fullName']."; ";
      $pv = new \e10\persons\libs\register\Validator($this->app());
      $pv->setPersonNdx($r['ndx']);
      $pv->checkPerson();

      if ($this->debug)
        echo "\n";

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

  public function revalidate()
  {
    $minDate = new \DateTime('3 months ago');

    $q = [];
    array_push($q, 'SELECT [validity].*, [persons].fullName AS [personName]');
    array_push($q, ' FROM [e10_persons_personsValidity] AS [validity]');
    array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [validity].[person] = [persons].[ndx]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [validity].[valid] = %i', 1);
    array_push($q, ' AND [validity].[revalidate] = %i', 0);
    array_push($q, ' AND [validity].[updated] < %d', $minDate);
    array_push($q, ' AND [persons].[docState] = %i', 4000);
    array_push($q, ' ORDER BY [validity].[updated]');
    array_push($q, ' LIMIT 0, %i', $this->maxCount);
    $rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
      if ($this->app()->debug)
        echo "* ".$r['personName']."\n";

      $this->db()->query('UPDATE [e10_persons_personsValidity] SET [revalidate] = %i', 1, ' WHERE [ndx] = %i', $r['ndx']);
    }
  }
}