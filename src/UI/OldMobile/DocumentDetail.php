<?php

namespace ui\mobile;

use E10\ContentRenderer;


/**
 * Class Viewer
 * @package mobileui
 */
class DocumentDetail extends \Shipard\UI\OldMobile\PageObject
{
	var $documentCard = NULL;
	var $documentNdx = 0;

	public function createContent ()
	{
		$second = intval($this->app->requestPath(2));
		if ($second)
			$this->documentNdx = $second;

		if ($this->documentNdx)
		{
			$this->documentCard = $this->app->documentCard($this->definition['table'], $second, 0);
			if ($this->documentCard)
				$this->documentCard->createContent();
		}
	}

	public function createContentCodeInside ()
	{
		$c = '';

		if ($this->documentNdx)
		{
			if ($this->documentCard)
			{
				$cr = new ContentRenderer($this->app);
				$cr->mobile = TRUE;
				$cr->setDocumentCard($this->documentCard);
				$c .= $cr->createCode('header');
				$c .= $cr->createCode('body');
			}
			else
			{
				/*
				$table = $this->app->table($this->definition['table']);
				$detailId = 'detail';
				$detail = $table->getDetailData ($this->definition['viewer'], $detailId, $this->documentNdx);
				$detail->createDetailContent ();

				$c .= $detail->createHeaderCode ();
				$c .= $detail->createDetailCode ();
				*/
				$c .= '--- coming soon ---';
			}
		}

		return $c;
	}

	public function createContentCodeBegin ()
	{
		$c = '';
		return $c;
	}

	public function createContentCodeEnd ()
	{
		$c = "<div class='e10-page-end'><i class='fa fa-chevron-up'></i></div>";

		return $c;
	}

	public function title1 ()
	{
		return $this->definition['t1'];
	}

	public function leftPageHeaderButton ()
	{
		$lmb = ['icon' => self::backIcon, 'path' => $this->definition['itemId']];
		return $lmb;
	}

	public function rightPageHeaderButtons ()
	{
		if ($this->app->clientType [1] === 'cordova')
		{
			$b = [
					'icon' => 'icon-camera', 'action' => 'detail-add-photo',
					'data' => ['table' => $this->definition['table'], 'pk' => $this->documentNdx]
			];
			$rmbs[] = $b;

			return $rmbs;
		}

		return FALSE;
	}

	public function pageType () {return 'document';}
}
