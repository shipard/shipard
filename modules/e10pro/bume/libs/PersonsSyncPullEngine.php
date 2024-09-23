<?php

namespace e10pro\bume\libs;


/**
 * class PersonsSyncPullEngine
 */
class PersonsSyncPullEngine extends \e10sync\libs\SyncPullClient
{
  var $contactsListNdx = 0;
  var $url = '';
  var $apiKey = '';

  /** @var \e10\persons\TablePersons */
  var $tablePersons;

  var \lib\objects\ClientEngine $ce;

  protected function doIt()
  {
    $params = [
      'listNdx' => $this->contactsListNdx,
      'style' => 'list',
      'pageSize' => 10,
      'firstRowNumber' => 0,
    ];

		$this->ce = new \lib\objects\ClientEngine($this->app());
		$this->ce->apiKey = $this->apiKey;

    $batchNumber = 1;

    while (1)
    {
		  $result = $this->ce->apiCall2($this->url, $this->apiCallClassId(), $params);
      //echo Json::lint($result)."\n";

      if (!isset($result['response']['data']))
      { // error
        break;
      }

      if (!count($result['response']['data']))
      { // no data
        break;
      }

      if ($this->app()->debug)
      {
        echo "### batch ".$batchNumber.'; cnt: '.count($result['response']['data']).';';
        $ndxs = [];
        foreach ($result['response']['data'] as $oneItem)
          $ndxs[] = strval($oneItem['ndx']);
        echo ' ndxs: '.implode(', ', $ndxs);
        echo " ###\n";
      }

      foreach ($result['response']['data'] as $oneItem)
      {
        $this->doOnePerson($oneItem);
      }

      $batchNumber++;
      $params['firstRowNumber'] += $params['pageSize'];

      //if ($batchNumber > 1)
      //  break;
    }
  }

  protected function doOnePerson($personInfo)
  {
    $params = [
      'listNdx' => $this->contactsListNdx,
      'style' => 'person',
      'personNdx' => $personInfo['ndx'],
    ];
    $result = $this->ce->apiCall2($this->url, $this->apiCallClassId(), $params);
    //echo "    - ".$result['response']['person']['rec']['fullName']."\n";
    //echo Json::lint($result['response']['person'])."\n";

    $personNdx = $this->doImport($this->tablePersons, $result['response']['person']);
    if ($personNdx)
    {
      $this->addLabels($this->tablePersons, $personNdx);
      $this->checkOnePerson($personNdx, $result['response']['person']);
    }
  }

  protected function checkOnePerson($personNdx, $personInfo)
  {
  }

  protected function apiCallClassId()
  {
    return 'persons-sync-pull';
  }

  public function run()
  {

    $this->tablePersons = $this->app()->table('e10.persons.persons');
    $this->doIt();
  }
}
