<?php

namespace e10doc\base\libs;
use \Shipard\Base\Utility;
use \e10doc\templates\TableHeads;


/**
 * class SendReportsFromQueue
 */
class SendReportsFromQueue extends Utility
{
  protected function sendFromQueue()
  {
    $now = new \DateTime();

    $q = [];
    array_push($q, 'SELECT [queue].*');
    array_push($q, ' FROM [e10doc_base_sendReportsQueue] AS [queue]');
    array_push($q, ' WHERE [sendState] = %i', 0);
    array_push($q, ' AND [sendAfter] < %t', $now);
    array_push($q, ' ORDER BY ndx');

    $cnt = 1;
    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {

      if ($this->app()->debug)
        echo $cnt.': '.$r['docNdx']."\n";

      $formReportEngine = new \Shipard\Report\FormReportEngine($this->app());
      $formReportEngine->setParam('documentTable', 'e10doc.core.heads');
      $formReportEngine->setParam('reportClass', 'e10doc.invoicesOut.libs.InvoiceOutReport');
      $formReportEngine->setParam('documentNdx', $r['docNdx']);

      $formReportEngine->createReport();
      $formReportEngine->createMsg();

      $formReportEngine->sendMsg(/*TableHeads::asmOutboxSendLater*/TableHeads::asmFullSend);

      $update = [
        'sendState' => 1,
        'sentDateTime' => new \DateTime(),
      ];

      $this->db()->query('UPDATE [e10doc_base_sendReportsQueue] SET ', $update, ' WHERE ndx = %i', $r['ndx']);

      $cnt++;

      if ($this->app()->debug && $cnt > 2)
        break;

      sleep(2);
    }
  }

  public function run()
  {
    $this->sendFromQueue();
  }
}
