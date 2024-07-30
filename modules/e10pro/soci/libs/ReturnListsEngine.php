<?php

namespace e10pro\soci\libs;
use \Shipard\Utils\Utils;


/**
 * class ReturnListsEngine
 */
class ReturnListsEngine extends \Shipard\Base\Utility
{
  /** @var \e10pro\soci\TableEntries */
  var $tableEntries;

  var $woDocKind = 0;
  var $srcPeriod = 1;
  var $dstPeriod = 2;

  public function setWODocKind($woDocKind)
  {
    $this->$woDocKind = $woDocKind;
  }

  public function setSrcPeriod($srcPeriod)
  {
    $this->srcPeriod = $srcPeriod;
  }

  protected function doOne($srcEntry)
  {
    $exist = $this->db()->query('SELECT * FROM [e10pro_soci_entries] WHERE [dstPerson] = %i', $srcEntry['dstPerson'],
                                ' AND [entryTo] = %i', $srcEntry['followUpWorkOrder'])->fetch();
    if ($exist)
      return;

    $newEntry = [
      'entryKind' => $srcEntry['entryKind'],
      'dateIssue' => Utils::today(),
      'docNumber' => '',
      'entryTo' => $srcEntry['followUpWorkOrder'],
      'entryPeriod' => $this->dstPeriod,
      'entryState' => 0,
      'source' => 4,
      'firstName' => $srcEntry['firstName'],
      'lastName' => $srcEntry['lastName'],
      'fullName' => $srcEntry['fullName'],
      'birthday' => $srcEntry['birthday'],
      'phone' => $srcEntry['phone'],
      'email' => $srcEntry['email'],
      'saleType' => $srcEntry['saleType'],
      'paymentPeriod' => $srcEntry['nextYearPayment'] == 1 ? 0 : 1,
      'dstPerson' => $srcEntry['dstPerson'],
      'docState' => 4000,
      'docStateMain' => 2,
    ];

    $newNdx = $this->tableEntries->dbInsertRec($newEntry);
    $this->tableEntries->docsLog($newNdx);
  }

  public function doAll()
  {
    $this->tableEntries = $this->app()->table('e10pro.soci.entries');

    $q = [];
    array_push($q, 'SELECT [entries].*, ');
    array_push($q, ' srcEvents.followUpWorkOrder');
    array_push($q, ' FROM [e10pro_soci_entries] AS [entries]');
    array_push($q, ' LEFT JOIN [e10mnf_core_workOrders] AS srcEvents ON [entries].entryTo = srcEvents.ndx');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [entryPeriod] = %i', $this->srcPeriod);
    array_push($q, ' AND [dstPerson] != %i', 0);
    array_push($q, ' AND srcEvents.[followUpWorkOrder] != %i', 0);
    array_push($q, ' AND [entries].[docState] = %i', 4000);
    array_push($q, ' AND [nextYearContinue] = %i', 1);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->doOne($r->toArray());
    }
  }

  public function run()
  {
    $this->doAll();
  }
}
