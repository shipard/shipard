<?php

namespace E10\Share;

use \E10\utils, \E10\Utility, \E10\TableViewDetail, \E10\TableForm, \E10\web\webPages, \E10\DbTable;


/**
 * Class ShareWebPage
 * @package E10\Share
 */
class ShareWebPage extends \E10\utility
{
	var $page = [];
	var $shareRecData;

	var $folders = [];
	var $folderId = 0;
	var $itemId = 0;

	public function init ()
	{
		$this->page['status'] = 200;
		$this->page['shareId'] = $this->app->requestPath (1);
		$this->folderId = intval($this->app->requestPath (2));
		$this->itemId = intval($this->app->requestPath (3));
	}

	public function pageNotFound ()
	{
		$this->page['status'] = 404;
		$this->page['title'] = 'Stránka nenalezena';
		$this->page['text'] = 'Stránka nenalezena';

		return FALSE;
	}

	public function loadShare ()
	{
		$q = 'SELECT * FROM e10_share_shares WHERE id = %s AND docState = 4000';
		$this->shareRecData = $this->db()->query ($q, $this->page['shareId'])->fetch();
		if (!$this->shareRecData)
			return $this->pageNotFound();

		if ($this->folderId !== 0)
		{
			$q = 'SELECT * FROM e10_share_sharesfolders WHERE share = %i AND ndx = %i';
			$folder = $this->db()->query ($q, $this->shareRecData['ndx'], $this->folderId)->fetch();
			if (!$folder)
				return $this->pageNotFound();
		}

		$this->page['title'] = $this->shareRecData['name'];
		return TRUE;
	}

	public function loadFolders ()
	{
		$q = 'SELECT * FROM e10_share_sharesfolders WHERE share = %i';
		$rows = $this->db()->query ($q, $this->shareRecData['ndx']);
		foreach ($rows as $r)
		{
			if ($this->folderId === 0)
				$this->folderId = intval($r['ndx']);

			$folder = $r->toArray ();
			if ($this->folderId == $r['ndx'])
				$folder['active'] = 1;

			$folder['url'] = $this->app->urlRoot.'/share/'.$this->page['shareId'].'/'.strval ($r['ndx']);

			$this->folders[] = $folder;
		}

		if (count ($this->folders))
		{
			$this->page['folders'] = $this->folders;
			$this->page['hasFolders'] = 1;
		}

		$this->page['shareUrl'] = $this->app->urlRoot.'/share/'.$this->page['shareId'].'/'.$this->folderId.'/';
	}

	public function loadItems ()
	{
		$q[] = 'SELECT * FROM e10_share_sharesitems  ';
		array_push($q, ' WHERE share = %i', $this->shareRecData['ndx']);
		array_push($q, ' AND folder = %i', $this->folderId);
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray ();
			$this->page['items'][] = $item;
		}
	}

	public function loadItemContent ()
	{
		$this->page ['itemContent'] = 1;

		$attachments = \E10\Base\loadAttachments ($this->app, [$this->itemId], 'e10.share.sharesitems');
		$this->page ['files'] = $attachments[$this->itemId];
	}

	public function run ()
	{
		$this->init ();

		if (!$this->loadShare())
			return;

		$this->loadFolders();

		if ($this->itemId)
			$this->loadItemContent();
		else
			$this->loadItems();
	}
}

function createSharePage ($app, $params = NULL)
{
	$engine = new ShareWebPage ($app);
	$engine->run();
	return $engine->page;
}

/**
 * @param $app
 * @param null $params
 * @return array
 */
function createShare ($app, $params = NULL)
{
	$engine = new \e10\web\WebPages($app);
	$engine->setServerInfo (['templateId' => 'app.shares', 'function' => 'e10.share.createSharePage', 'urlStart' => '']);
	$engine->setPageType (webPages::wptExtranet);
	return $engine->run();
}
