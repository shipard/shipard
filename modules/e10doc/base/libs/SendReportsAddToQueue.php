<?php

namespace e10doc\base\libs;
use \Shipard\Base\Utility;


/**
 * class SendReportsAddToQueue
 */
class SendReportsAddToQueue extends Utility
{
  var $dbCounterNdx = 0;
  var $dataTimeLimit = NULL;
  var $formReport = 0;

  protected function addToQueue()
  {
    $q = [];
    array_push($q, 'SELECT docs.ndx, docs.docNumber ');
    array_push($q, ' FROM [e10doc_core_heads] AS docs');
    array_push($q, ' WHERE [dbCounter] = %i', $this->dbCounterNdx);
    array_push($q, ' AND [activateTimeFirst] > %t', $this->dataTimeLimit);
    array_push ($q,' AND NOT EXISTS (',
      ' SELECT ndx FROM e10doc_base_sendReportsQueue ',
      ' WHERE docs.ndx = docNdx AND formReport = %i', $this->formReport, ')',
    );
    array_push($q, ' ORDER BY docNumber, ndx');

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $newItem = [
        'docNdx' => $r['ndx'],
        'formReport' => $this->formReport,
        'sendState' => 0,
        'sendAfter' => new \DateTime(),
      ];

      $this->db()->query('INSERT INTO [e10doc_base_sendReportsQueue] ', $newItem);

      if ($this->app()->debug)
        echo $cnt.': '.$r['docNumber']."\n";

      $cnt++;

      if ($this->app()->debug && $cnt > 10)
        break;
    }
  }

  public function run()
  {
    if (!$this->dbCounterNdx)
      return;

    $this->dataTimeLimit = new \DateTime('3 days ago');

    $this->addToQueue();
  }
}
