<?php

namespace services\nomenc\dataView;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;
use \lib\dataView\DataView;
use \Shipard\Utils\TableRenderer;


/**
 * @class DataViewNomenclature
 */
class DataViewNomenclature extends DataView
{
	var $maxCount = 10;
	var $urlPrefix = '';
  var \services\nomenc\libs\NomenclatureExport $export;

	var $nomeclatures = [];

	var $viewTypes = [
    'table' => ['title' => 'Tabulka'], 
    'json-flat' => ['title' => 'JSON1'],
    'json-tree' => ['title' => 'JSON2'],
  ];
	var $enabledShowAs = ['html', 'json'];

  var $nomencData;

	protected function init()
	{
		parent::init();

		$this->requestParams['showAs'] = strval($this->app()->requestPath(3));
		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

		if (!in_array($this->requestParams['showAs'], $this->enabledShowAs))
		{
			$this->addMessage('Nepodporovaný formát `'.$this->requestParams['showAs'].'`');
			$this->requestParams['showAs'] = 'html';
			return;
		}

		$this->requestParams['nomencId'] = $this->app()->requestPath(1);
    if ($this->requestParams['nomencId'] === '')
      $this->requestParams['nomencId'] = 'ALL';

		$this->requestParams['viewType'] = $this->app()->requestPath(2);
	}

	protected function loadData()
	{
    if ($this->requestParams['nomencId'] === 'ALL')
    {
      $q = [];
      array_push($q, 'SELECT * FROM [e10_base_nomencTypes]');
      array_push($q, ' WHERE 1');
      array_push($q, ' AND [docState] IN %in', [4000, 8000]);
      array_push($q, ' ORDER BY [fullName]');
      $rows = $this->db()->query($q);
      foreach ($rows as $r)
      {
        $item = [
          'id' => $r['id'],
          'title' => $r['fullName'],
          'url' => $this->app()->urlRoot.'/data-sets/'.$r['id'].'/'.'table',
        ];

        $this->nomenclatures[] = $item;
      }
    }
    else
    {
      $this->export = new \services\nomenc\libs\NomenclatureExport($this->app);
      $this->export->nomencId = $this->requestParams['nomencId'];
      $this->export->run();

      if (!$this->export->nomencTypeRecData)
      {
        $this->addMessage('Chybné id `'.$this->requestParams['nomencId'].'`');
      }
    }
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'html')
    	return $this->renderDataAsHtml();
		if ($showAs === 'json')
    	return $this->renderDataAsJson();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsHtml()
	{
		$c = '';

    if (count($this->messagess))
    {

      $c .= $this->errorsHtml();
      return;
    }
    if ($this->requestParams['nomencId'] === 'ALL')
    {
      $c .= "<ul>";
      foreach ($this->nomenclatures as $n)
      {
        $c .= "<li>";
        $c .= "<a href='{$n['url']}'>".Utils::es($n['title']).'</a>';
        $c .= '</li>';
      }

      $c .= '</ul>';
      return $c;
    }

    $c .= '<h3>'.Utils::es($this->export->nomencTypeRecData['fullName']).'</h3>';
    $c .= "<ul class='nav nav-tabs pt-1 mb-1' id='information-tabs' role='tablist'>";
    foreach ($this->viewTypes as $vtId => $vt)
    {
      $active = ($this->requestParams['viewType'] === $vtId) ? ' active' : '';
      $url = $this->app()->urlRoot.'/data-sets/'.$this->requestParams['nomencId'].'/'.$vtId;
      $c .= "<li class='nav-item' role='presentation'>";
      $c .= "<a class='nav-link{$active}' id='{$vtId}-tab' href='$url' data-x-bs-toggle='tab' data-x-bs-target='#{$vtId}' type='button' role='tab' aria-controls='{$vtId}' aria-selected='false'>".Utils::es($vt['title'])."</a>";
      $c .= "</li>";
    }
    $c .= "</ul>";

  
    if ($this->requestParams['viewType'] === 'table')
    {
      $h = ['#' => '#', 'id' => 'ID', 'fullName' => 'Název'];
      $table = [];
      foreach ($this->export->exportedData as $item)
      {
        $row = [
          'id' => $item['id'], 'fullName' => $item['fullName'],
        ];
        $table[] = $row;
      }        
      $tr = new TableRenderer($table, $h, [], $this->app());
      $c .= $tr->render();
    }
    elseif ($this->requestParams['viewType'] === 'json-flat')
    {
      $c .= '<pre><code>';
      $c .= Json::lint($this->export->exportedData);
      $c .= '</code></pre>';
    } 
    elseif ($this->requestParams['viewType'] === 'json-tree')
    {
      $c .= '<pre><code>';
      $c .= Json::lint($this->export->exportedDataTree);
      $c .= '</code></pre>';
    } 

		return $c;
	}

	protected function renderDataAsJson()
	{
		if (count($this->messagess))
    {
			$data = array_merge(['status' => 0], ['errors' => array_values($this->messagess)]);
			$this->template->data['forceCode'] = Json::lint($data);
    }
		elseif ($this->requestParams['viewType'] === 'json-flat')
		{
			$this->template->data['forceCode'] = Json::lint($this->export->exportedData);
		}
		elseif ($this->requestParams['viewType'] === 'json-tree')
		{
			$this->template->data['forceCode'] = Json::lint($this->export->exportedDataTree);
		}

    $this->template->data['forceMimeType'] = 'application/json';
    return '';
	}
}
