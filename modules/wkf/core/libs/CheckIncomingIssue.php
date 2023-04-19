<?php
namespace wkf\core\libs;

use Shipard\Base\Utility;


/**
 * class CheckIncomingIssue
 */
class CheckIncomingIssue extends Utility
{
  var $issueNdx = 0;
  var $attsDocs = [];

  public function setIssue($issueNdx)
  {
    $this->issueNdx = $issueNdx;
  }

  protected function loadAttachments()
  {
    $q = [];
    array_push($q, 'SELECT * FROM [e10_attachments_files] ');
    array_push($q, ' WHERE [recid] = %i', $this->issueNdx,  ' AND tableid = %s', 'wkf.core.issues');
    array_push($q, ' AND [fileKind] != %i', 0);
    array_push($q, ' AND [ddfId] != %i', 0);
    array_push($q, ' AND [ddfNdx] != %i', 0);
    array_push($q, ' AND [deleted] = %i', 0);
    array_push($q, ' ORDER BY ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $this->attsDocs[] = ['ddfNdx' => $r['ddfNdx'], 'ddfId' => $r['ddfId']];
    }
  }

  protected function doIt()
  {
    /** @var \e10\base\TableDocDataFiles $tableDocDataFiles */
    $tableDocDataFiles = $this->app()->table('e10.base.docDataFiles');

    foreach ($this->attsDocs as $one)
    {
			/** @var \lib\docDataFiles\DocDataFile $ddfObject */
			$ddfObject = $tableDocDataFiles->ddfObject(NULL, $one['ddfNdx']);
			if ($ddfObject)
			{
        $ddfObject->inboxNdx = $this->issueNdx;
        $ddfObject->automaticImport = 1;

        $nrd = ['inboxNdx' => $this->issueNdx, 'ddfNdx' => $one['ddfNdx'], 'ddfId' => $one['ddfId']];
				$ddfObject->createDocument($nrd, TRUE);
			}

      break;
    }
  }

  public function run()
  {
    $this->loadAttachments();
    $this->doIt();
  }
}
