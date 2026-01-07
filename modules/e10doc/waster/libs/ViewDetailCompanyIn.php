<?php
namespace e10doc\waster\libs;
use \Shipard\Viewer\TableViewDetail;


/**
 * class ViewDetailCompanyIn
 */
class ViewDetailCompanyIn extends TableViewDetail
{
  function createDetailContent ()
  {
    $this->addDocumentCard('e10doc.waster.libs.dc.DCCompanyIn');
  }
}

