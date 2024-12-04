<?php

namespace e10pro\emps\libs;
use \Shipard\Utils\Utils;

/**
 * class OrgsInfo
 */
class OrgsInfo extends \Shipard\Base\Utility
{
  var $orgsNdx = 0;
  var $orgsRecData = NULL;

  var $orgsPersons = [];
  var $orgsPersonsContent = NULL;

  public function setOrgs($orgsNdx)
  {
    $this->orgsNdx = $orgsNdx;
    $this->orgsRecData = $this->app()->loadItem($orgsNdx, 'e10pro.emps.orgs');
  }

  public function loadData()
  {
		$q = [];
		array_push($q, 'SELECT [orgsPersons].*, [persons].[fullName] AS [personName], [persons].[id] AS [personId]');
		array_push($q, ' FROM [e10pro_emps_orgsPersons] AS [orgsPersons]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [orgsPersons].[person] = [persons].ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [orgsPersons].[orgs] = %i', $this->orgsNdx);
		array_push($q, ' ORDER BY [orgsPersons].[superior] DESC, [orgsPersons].[rowOrder]');
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->orgsPersons[] = $r->toArray();
		}

    $this->createOrgsContent();
  }

  protected function createOrgsContent()
  {
    $table = [];
    $header = ['person' => 'Osoba', 'function' => 'Funkce'];
    $needBreak = 1;
    foreach ($this->orgsPersons as $op)
    {
      $item = [
        'person' => ['text' => $op['personName'], 'suffix' => $op['personId'], 'class' => ''],
        'function' => $op['function'],
      ];

      if (!$op['superior'] && $needBreak)
      {
        $needBreak = 0;
        $item['_options'] = ['beforeSeparator' => 'separator'];
      }

      $table[] = $item;
    }

    $this->orgsPersonsContent = ['table' => $table, 'header' => $header];
  }
}
