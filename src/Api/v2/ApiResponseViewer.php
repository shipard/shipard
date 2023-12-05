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
    /** @var \Shipard\Table\DbTable */
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
        $this->responseData['hcBackIcon'] = $renderer->renderedData['hcBackIcon'];
        $this->addTitle($table, $v);
      }
      elseif ($fullCode)
      {
        $this->responseData['hcFull'] = $renderer->renderedData['hcFull'];
        $this->addTitle($table, $v);
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

  protected function addTitle(\Shipard\Table\DbTable $table, ?\Shipard\Viewer\TableView $v)
  {
    if (!$v)
      return;

    if ($v->toolbarTitle)
    {
      $this->responseData['hcTitle'] = $this->app()->ui()->composeTextLine($v->toolbarTitle);
    }
    else
    {
      $icon = $table->tableIcon(NULL);
      $titleText = $table->tableName();

      $title = ['text' => $titleText, 'icon' => $icon];
      $this->responseData['hcTitle'] = $this->app()->ui()->composeTextLine($title);
    }
  }
}
