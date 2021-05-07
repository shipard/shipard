<?php

namespace e10pro\kb\libs;
use \e10\Utility;


/**
 * Class PageMoveEngine
 * @package e10pro\kb\libs
 */
class PageMoveEngine extends Utility
{
	var $moveType = '';
	var $srcPageNdx= 0;
	var $dstNdx = 0;
	var $dstSectionNdx;

	function init()
	{
	}

	public function movePage($srcPageNdx, $moveToId)
	{
		$this->srcPageNdx = intval($srcPageNdx);

		if ($moveToId[0] === 's')
		{ // to section
			$this->moveType = 's';
			$this->dstNdx = intval(substr($moveToId, 1));
			$this->dstSectionNdx = $this->dstNdx;
		}
		elseif ($moveToId[0] === 'p')
		{ // to page
			$this->moveType = 'p';
			$this->dstNdx = intval(substr($moveToId, 1));
			$dstPage = $this->db()->query('SELECT [section] FROM [e10pro_kb_texts] WHERE ndx = %i', $this->dstNdx)->fetch();
			$this->dstSectionNdx = $dstPage['section'];
		}

		if ($this->moveType === '')
			return;

		if ($moveToId[0] === 's')
		{ // to section
			$this->db()->query('UPDATE [e10pro_kb_texts] SET [ownerText] = 0, [section] = %i', $this->dstNdx, ' WHERE ndx = %i', $this->srcPageNdx);
			$this->updateSectionInTree($this->srcPageNdx);
		}
		elseif ($moveToId[0] === 'p')
		{ // to page
			$this->db()->query('UPDATE [e10pro_kb_texts] SET [ownerText] = %i', $this->dstNdx, ', [section] = %i', $this->dstSectionNdx,  ' WHERE ndx = %i', $this->srcPageNdx);
			$this->updateSectionInTree($this->srcPageNdx);
		}
	}

	function updateSectionInTree($pageNdx)
	{
		$this->db()->query('UPDATE [e10pro_kb_texts] SET [section] = %i', $this->dstSectionNdx,  ' WHERE ownerText = %i', $pageNdx);

		$q = [];
		array_push($q, 'SELECT ndx FROM [e10pro_kb_texts]');
		array_push($q, ' WHERE [ownerText] = %i', $pageNdx);
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->updateSectionInTree($r['ndx']);
		}
	}

	public function resetWikiPagesSections()
	{
		$allWikies = $this->app()->cfgItem ('e10pro.kb.wikies', NULL);
		if ($allWikies === NULL)
			return;

		$this->db()->begin();
		foreach ($allWikies as $wikiNdx => $w)
		{
			$qs = [];
			array_push ($qs, 'SELECT sections.ndx FROM [e10pro_kb_sections] AS [sections]');
			array_push ($qs, ' WHERE 1');
			array_push ($qs, ' AND [wiki] = %i', $wikiNdx);
			$sections = $this->db()->query($qs);
			foreach ($sections as $s)
			{
				$this->dstSectionNdx = $s['ndx'];
				$pages = $this->db()->query('SELECT [ndx] FROM [e10pro_kb_texts] WHERE [section] = %i', $this->dstSectionNdx);
				foreach ($pages as $p)
				{
					$this->updateSectionInTree($p['ndx']);
				}
			}
		}
		$this->db()->commit();
	}
}
