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
    $tableId = $this->requestParams['table'] ?? '';
    $table = $this->app->table ($tableId);
    if (!$table)
    {
      error_log("--INVALID-TABLE-- `{$tableId}`");
    }
    $isModal = ($this->requestParam('modal-type') !== NULL) ? 1 : 0;
    $fullCode = intval($this->requestParam('full-code'));

    /** @var \Shipard\Viewer\TableView */
    $v = NULL;
    $viewId = $this->requestParams['viewId'] ?? $this->requestParams['view-id'] ?? 'default';
    if ($table)
      $v = $table->getTableView ($viewId, NULL, $this->requestParams);
    if ($v)
    {
      $renderer = new \Shipard\UI\ng\renderers\TableViewRenderer($this->app());
      $renderer->uiRouter = $this->uiRouter;
      $renderer->isModal = $isModal;
      $renderer->setViewer($v);
      $v->renderViewerData ('');
      $renderer->render();

      if ($isModal)
      {
        $this->responseData['hcFull'] = $renderer->renderedData['hcFull'];
      }
      elseif ($fullCode)
      {
        $this->responseData['hcFull'] = $renderer->renderedData['hcFull'];
      }
      else
      {
        $this->responseData['hcRows'] = $v->rows();

        $this->responseData['rowsPageNumber'] = $v->objectData ['rowsPageNumber'];
        $this->responseData['rowsLoadNext'] = $v->objectData ['rowsLoadNext'];
      }

      $this->responseData['type'] = $this->requestParam('actionId') ?? 'INVALID';
      $this->responseData['objectType'] = 'dataView';
      $this->responseData['objectId'] = $v->vid;
    }
    else
    {
      error_log("--INVALID-VIEWER-- `{$viewId}`");
    }
  }
}
