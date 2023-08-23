<?php

namespace Shipard\Api\v2;


class ApiResponseViewer extends \Shipard\Api\v2\ApiResponse
{
  /** @var \Shipard\Viewer\TableView */
  var $viewer;

  protected function checkResponseParams()
  {
  }

  public function run()
  {
    $table = $this->app->table ($this->requestParams['table'] ?? '');

    /** @var \Shipard\Viewer\TableView */
    $v = NULL;
    if ($table)
      $v = $table->getTableView ($this->requestParams['viewId'] ?? 'default', NULL, $this->requestParams);
    if ($v)
    {
      //$v->requestParams = $this->requestParams;
      $renderer = new \Shipard\UI\ng\renderers\TableViewRenderer($this->app());
      $renderer->uiRouter = $this->uiRouter;
      $renderer->setViewer($v);
      $v->renderViewerData ('');
      $renderer->render();

      $this->responseData['type'] = 'loadNextData';
      $this->responseData['hcRows'] = $v->rows();

      $this->responseData['rowsPageNumber'] = $v->objectData ['rowsPageNumber'];
      $this->responseData['rowsLoadNext'] = $v->objectData ['rowsLoadNext'];
    }
  }
}
