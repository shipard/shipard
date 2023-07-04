<?php

namespace e10doc\gen\libs;
use \Shipard\Base\Utility;


/**
 * class DocGenEngine
 */
class DocGenEngine extends Utility
{
  //var $gens = NULL;
  var $srcDocNdx = 0;
  var $srcDocRecData = NULL;

	/** @var \e10doc\core\TableHeads */
	var $tableDocsHeads;

	/** @var \e10doc\gen\TableRequests */
	var $tableRequests;

  public function init()
  {
    $this->tableDocsHeads = $this->app->table ('e10doc.core.heads');
    $this->tableRequests = $this->app->table ('e10doc.gen.requests');
  }

  public function setSrcDocumentNdx($srcDocNdx)
  {
    $this->srcDocNdx = $srcDocNdx;
    $this->srcDocRecData = $this->tableDocsHeads->loadItem($srcDocNdx);
  }

  public function setSrcDocument($srcDocRecData)
  {
    $this->srcDocRecData = $srcDocRecData;
    $this->srcDocNdx = $srcDocRecData['ndx'];
  }

  public function generateDoc($docGenCfgNdx)
  {
    $exist = $this->db()->query('SELECT * FROM [e10doc_gen_requests] WHERE',
              ' [srcDocument] = %i', $this->srcDocNdx,
              ' AND [cfg] = %i', $docGenCfgNdx)->fetch();

    if ($exist)
    {

    }
    else
    {
      $dc = new \e10doc\gen\libs\DocGenDocumentCreator($this->app());
      $dc->init();
      $dc->setParams($docGenCfgNdx, $this->srcDocRecData);
      $dc->generate();

      if ($dc->newDocNdx)
      {
        $item = [
          'cfg' => $docGenCfgNdx,
          'srcType' => 0,
          'srcDocument' => $this->srcDocNdx,
          'dstDocument' => $dc->newDocNdx,
        ];

        $this->db()->query('INSERT INTO [e10doc_gen_requests] ', $item);

        return $dc->newDocNdx;
      }
    }
    return 0;
  }
}
