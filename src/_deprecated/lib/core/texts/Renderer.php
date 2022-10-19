<?php

namespace lib\core\texts;

use e10\utils, \e10\Utility;
use \e10\web\WebTemplateMustache;

/**
 * Class Renderer
 * @package lib\core\texts
 */
class Renderer extends Utility
{
	/** @var  \lib\core\texts\Texy */
	protected $texy = NULL;
	var $linkRoot = NULL;

	var $wikiWidgetMode = FALSE;

	var $code = '';

	public function init ()
	{
		$this->texy = new \lib\core\texts\Texy($this->app());
		$this->texy->externalLinks = TRUE;
		if ($this->wikiWidgetMode)
			$this->texy->wikiWidgetMode = TRUE;

		if ($this->linkRoot !== NULL)
			$this->texy->linkModule->root = $this->linkRoot;
	}

	public function setLinkRoot($linkRoot)
	{
		$this->linkRoot = $linkRoot;
		//$this->texy->linkModule->root = $linkRoot;
	}

	function replaceHashtags($str, $outputType = null)
	{
		$hashtagsArray = [];
		$strArray = explode(' ',$str);

		//$pattern = '%(\A#([\w\.\w|:]|[\w|:]|(\p{L}\p{M}?)|-)+\b)|((?<=\s)#(\w|(\p{L}\p{M}?)|-)+\b)|((?<=\[)#.+?(?=\]))%u';
		$pattern = '%\#(\d+\.\d+)%';

		foreach ($strArray as $b)
		{
			preg_match_all($pattern, ($b), $matches);
			$hashtag	= implode(', ', $matches[0]);

			if (!empty($hashtag) || $hashtag != "")
				array_push($hashtagsArray, $hashtag);
		}

		foreach ($hashtagsArray as $c)
		{
			$hashtagTitle = ltrim($c,"#");

			$hashtagParts = explode (':', $hashtagTitle);
			$valid = 0;
			if (count($hashtagParts) === 1)
			{
				$icon = 'fa fa-hashtag';
				$tableId = 'wkf.core.issues';
				$pk = '@issueId:'.utils::es($hashtagParts[0]);
				$valid = 1;
			}
			else
			{
				//$hashtagTitle = $hashtagParts[1];
				$icon = $this->app()->ui()->icon ('system/iconFile');
			}

			if ($valid)
				$str = str_replace($c,"<a href='#' class='e10-document-trigger' data-action='edit' data-table='$tableId' data-pk='$pk'>#".$hashtagTitle.'</a>',$str);
			else
				$str = str_replace($c,'#'.$hashtagTitle, $str);
		}

		return $str;
	}

	public function setOwner (&$ownerInfo)
	{
		if  (!$this->texy)
			$this->init();

		$this->texy->setOwner($ownerInfo);
	}

	function codeEncode($srcText)
	{
		$dstText = '';
		$rows = preg_split("/\\r\\n|\\r|\\n/", $srcText);
		$rn = 0;
		while(1)
		{
			if ($rn >= count($rows))
				break;
			$r = $rows[$rn];
			$rn++;
			if ($r === '[[[---code---')
			{
				$subText = '';
				while (1)
				{
					if ($rn >= count($rows))
						break;
					$r = $rows[$rn];
					$rn++;
					if ($r === '---code---]]]')
						break;
					if ($subText !== '')
						$subText .= "\n";
					$subText .= $r;
				}

				$dstText .= "/---code\n[[[!!!base64decode!!!:";
				$dstText .= base64_encode(utils::es($subText));
				$dstText .= "!!!]]]\n\---\n";

				continue;
			}

			$dstText .= "\n".$r;
		}
		return $dstText;
	}

	public function render($text)
	{
		if  (!$this->texy)
			$this->init();

		$text2 = $this->codeEncode($text);
		$text2 = $this->replaceHashtags ($text2);

		$this->code = $this->texy->process($text2);
	}

	public function renderAsArticle($text, $ownerTable)
	{
		$template = new WebTemplateMustache ($this->app());
		$page = ['tableId' => $ownerTable->tableId()];
		$this->setOwner ($page);
		$this->render($text);
		$this->code = $template->renderPagePart('content', $this->code);
	}
}

