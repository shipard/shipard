<?php

namespace e10pro\bcards\libs\dataView;
use \lib\dataView\DataView;

/**
 * class WebCard
 */
class WebCard extends DataView
{
  var $bcardId = '';
  var $bcardNdx = 0;
  var $showAsVCARD = 0;

  var \e10pro\bcards\libs\BCardEngine $bcardEngine;

	protected function init()
	{
		parent::init();

    if ($this->requestParams['bcardId'] === 'URL')
    {
      $this->requestParams['bcardId'] = $this->app()->requestPath(count($this->app()->requestPath) - 1);

      if (str_ends_with($this->requestParams['bcardId'], '.vcf'))
      {
        $this->requestParams['bcardId'] = substr($this->requestParams['bcardId'], 0, -4);
        $this->showAsVCARD = 1;
      }
    }

    $this->bcardId = $this->requestParams['bcardId'];

    if ($this->bcardId !== '')
    {
      $rows = $this->db()->query('SELECT ndx FROM [e10pro_bcards_cards] WHERE [id1] = %s', $this->bcardId,
                                 ' AND [docState] IN %in', [4000, 8000]);
      if ($rows)
      {
        foreach ($rows as $r)
        {
          $this->bcardNdx = $r['ndx'];
          break;
        }
      }
		}
	}

	protected function loadData()
	{
    $this->bcardEngine = new \e10pro\bcards\libs\BCardEngine ($this->app());
    $this->bcardEngine->setBCard($this->bcardNdx);
    $this->bcardEngine->createData();
	}

	protected function renderDataAs($showAs)
	{
    if ($this->showAsVCARD)
      return $this->renderDataAsVCARD();

    return $this->renderDataAsWebCard();
	}

	protected function renderDataAsVCARD()
	{
    if ($this->bcardNdx)
    {
      $this->template->data['forceCode'] = $this->bcardEngine->bcardData['vcard'];
      $this->template->data['forceMimeType'] = 'text/vcard';
      return;
    }

    $this->template->data['forceCode'] = 'vizika neexistuje / bussines card not found'.';'.$this->bcardId;
    $this->template->data['forceMimeType'] = 'text/plain';
    $this->template->data['forceStatus'] = 404;
	}

	protected function renderDataAsWebCard()
	{
    if ($this->bcardNdx)
    {
      $this->bcardEngine->createWebCard();
      $this->template->data['forceCode'] = $this->bcardEngine->webCardHtml;
      $this->template->data['forceMimeType'] = 'text/html';

      return;
    }
    $this->template->data['forceCode'] = 'vizika neexistuje / bussines card not found'.';'.$this->bcardId;
    $this->template->data['forceMimeType'] = 'text/plain';
    $this->template->data['forceStatus'] = 404;
	}
}
