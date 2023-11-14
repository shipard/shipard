<?php

namespace Shipard\Api\v2;


class ApiResponseDocumentCard extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Viewer\TableView */
  var $viewer;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    $table = $this->app->table ($this->requestParams['table'] ?? '');
    $classId = $this->requestParam('class-id');
    $docNdx = intval($this->requestParam('pk'));
    $docRecData = NULL;

    /** @var \Shipard\Base\DocumentCard */
    $dc = NULL;
    if ($classId)
      $dc = $this->app()->createObject($classId);

    if ($dc && $table)
    {
      $dc->uiTemplate = $this->uiRouter->uiTemplate;
      $docRecData = $table->loadItem($docNdx);

      if ($docRecData)
      {
        $dc->setDocument($table, $docRecData);
        $dc->createContent();

        $this->responseData['hcFull'] = $dc->createCodeNG ();//"<h3>ahoj2...</h3>";

        $this->responseData['type'] = $this->requestParam('actionId') ?? 'INVALID';
        $this->responseData['objectType'] = 'documentCard';
        $this->responseData['objectId'] = $dc->widgetId;
      }
    }
  }
}
