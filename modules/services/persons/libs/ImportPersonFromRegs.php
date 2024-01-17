<?php

namespace services\persons\libs;
use \services\persons\libs\PersonData;
use \services\persons\libs\LogRecord;

/**
 * @class ImportPersonFromRegs
 */
class ImportPersonFromRegs extends \services\persons\libs\CoreObject
{
  var $personNdx = 0;
  var ?\services\persons\libs\PersonData $personDataCurrent = NULL;
  var ?\services\persons\libs\PersonData $personDataImport = NULL;
  var $debug = 0;
  var \services\persons\libs\LogRecord $logRecord;

  var array $regsData = [];

  public function setPersonNdx($personNdx)
  {
    if ($this->app()->debug)
      echo "* setPersonNdx\n";

    $this->personNdx = $personNdx;

    $this->personDataCurrent = new PersonData($this->app());
    $this->personDataCurrent->setPersonNdx($this->personNdx);
    $this->personDataCurrent->load();

    $this->personDataImport = new PersonData($this->app());
  }

  function regData($regType, $subId)
  {
    if (isset($this->regsData[$regType][$subId]))
      return $this->regsData[$regType][$subId];

    //error_log("Invalid regData for regType `$regType` and subId `$subId`");

    //print_r($this->regsData);

    return NULL;
  }

  protected function loadRegsData()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [services_persons_regsData]');
    array_push($q, ' WHERE [person] = %i', $this->personNdx);

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $regType = $r['regType'];
      $subId = $r['subId'];
      $this->regsData[$regType][$subId] = $r->toArray();
    }
  }

  public function run()
  {
    $this->logRecord = $this->log->newLogRecord();
    $this->logRecord->init(LogRecord::liImportRegisterData, 'services.persons.persons', $this->personNdx);

    $this->loadRegsData();
  }
}
