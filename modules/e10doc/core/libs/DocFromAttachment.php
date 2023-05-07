<?php

namespace e10doc\core\libs;
use \e10\json, \e10\utils, e10\str;
use \Shipard\Base\Utility;


/**
 * class DocFromAttachment
 * @TODO: delete this class?
 */
class DocFromAttachment extends Utility
{
  var $attNdx = 0;
  var $attRecData = NULL;

  var $replaceDocumentNdx = 0;

  /** @var \e10\base\TableDocDataFiles $tableDocDataFiles */
  var $tableDocDataFiles;
	/** @var \e10doc\core\TableHeads */
	var $tableHeads;
  /** @var \lib\docDataFiles\DocDataFile $ddfObject */
  var $ddfObject;

  public function init()
  {
    $this->tableDocDataFiles = $this->app()->table('e10.base.docDataFiles');
    $this->tableHeads = $this->app()->table('e10doc.core.heads');
  }

  public function setAttNdx($attNdx)
  {
    $this->attNdx = $attNdx;
    $this->attRecData = $this->app()->loadItem($attNdx, 'e10.base.attachments');
  }

  public function import()
  {
    $this->ddfObject = $this->tableDocDataFiles->ddfObject(NULL, $this->attRecData['ddfNdx']);
    if (!$this->ddfObject)
    {
      return;
    }

    $nrd = [];
    $this->tableHeads->checkNewRec($nrd);
    $this->ddfObject->createDocument($nrd);
  }

  public function reset($documentNdx)
  {
    $this->ddfObject = $this->tableDocDataFiles->ddfObject(NULL, $this->attRecData['ddfNdx']);
    if (!$this->ddfObject)
    {
      return;
    }

    $this->ddfObject->resetDocument($documentNdx);
  }
}
