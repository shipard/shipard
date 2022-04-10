<?php

namespace services\persons\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Json;

/** 
 * class LogRecord
 */
class LogRecord extends Utility
{
  var array $info = [];
  var array $items = [];
  var int $timeStart = 0;
  var int $timeStop = 0;

  CONST liInitialImport = 1, liDownloadRegisterData = 2, liImportRegisterData = 3;
  CONST lrsOK = 0, lrsWarning = 1, lrsError = 2;
  CONST lstInfo = 0, lstError = 1, lstRecDataChanged = 2;

  public function init(int $logItemType, string $tableId, int $recId)
  {
    $this->timeStart = hrtime(TRUE);
    $this->info['logItemType'] = $logItemType;
    $this->info['tableId'] = $tableId;
    $this->info['recId'] = $recId;
    $this->info['created'] = new \DateTime();
  }

  public function setStatus(int $logResultStatus, bool $save = FALSE)
  {
    $this->info['logResultStatus'] = $logResultStatus;
    if ($save)
      $this->save();
  }

  public function addItem(string $itemType, string $title, array $data)
  {
    $newItem = ['itemType' => $itemType, 'title' => $title, 'data' => $data];
    $this->items[] = $newItem;
  }

  public function save()
  {
    $this->timeStop = hrtime(TRUE);

    $timeLen = intval(($this->timeStop - $this->timeStart) / 1e+6); // convert nanoseconds to milliseconds
    $this->info['timeLen'] = $timeLen;
    if (count($this->items))
      $this->info['logData'] = Json::lint($this->items);
    
    $this->db()->query('INSERT INTO [services_persons_log]',$this->info);
  }
}
