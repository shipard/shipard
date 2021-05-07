<?php

namespace lib\core\texts;

use e10\utils;


/**
 * Class E10Texy
 * @package lib\core\texts
 */
class Texy extends \Texy
{
	public $app;
	public $owner = NULL;
	public $externalLinks = FALSE;
	public $wikiWidgetMode = FALSE;

	public function __construct($app, &$owner = NULL, $unsafe = FALSE)
	{
		parent::__construct();
		$this->app = $app;
		if ($owner)
			$this->owner = &$owner;

		$this->imageModule->root = $app->urlRoot . '/';
		$this->imageModule->linkedRoot = $app->urlRoot . '/';
		$this->linkModule->root = $app->urlRoot . '/';

		$this->addHandler ('script', '\lib\core\texts\texyScriptHandler');
		$this->addHandler ('phrase', '\lib\core\texts\texyPhraseHandler');
		$this->addHandler ('linkURL', '\lib\core\texts\texyLinkURLHandler');

		if ($unsafe === FALSE)
			unset ($this->allowedTags['script']);

		$this->allowedTags['article'] = TRUE;
		$this->allowedTags['section'] = TRUE;
		$this->allowedTags['details'] = TRUE;
		$this->allowedTags['summary'] = TRUE;
		$this->allowedTags['video'] = TRUE;
		$this->allowedTags['source'] = TRUE;

		$this->headingModule->top = 2;
	}

	public function setOwner (&$owner, $tableId = FALSE)
	{
		$this->owner = &$owner;
		if ($tableId !== FALSE)
			$this->owner['tableId'] = $tableId;
	}

	public function setParams ($markup)
	{
		$parts = explode (';', $markup);
		$m = array_shift ($parts);

		forEach ($parts as $param)
		{
			$prm = explode (':', $param);
			if (count($prm) >= 2)
				$this->owner['params'][trim($prm[0])] = $prm[1];
		}
	}
}


function texyScriptHandler ($invocation, $cmd, $args, $raw)
{
	$markup = $cmd.$raw;
	if ($markup[0] === '_')
	{
		$html = '&#123;&#123;'.substr($cmd.$raw, 1).'&#125;&#125;';
		return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
	}

	if (substr($markup, 0, 9) == 'setParams')
	{
		$invocation->texy->setParams ($markup);
		return $invocation->texy->protect ('', Texy::CONTENT_BLOCK);
	}

	$subparams = ";tableId:{$invocation->texy->owner['tableId']};ndx:{$invocation->texy->owner['ndx']}";

	if ($markup[0] === '$')
	{
		$str = utils::cfgInfo ($invocation->texy->app, substr($markup, 1));
		return $invocation->texy->protect ($str, Texy::CONTENT_BLOCK);
	}

	if ($markup[0] === '&')
	{
		$html = '{{{'.$markup.$subparams.'}}}';
		return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
	}

	$html = '{{{@'.$markup.$subparams.'}}}';
	return $invocation->texy->protect ($html, Texy::CONTENT_BLOCK);
}


function texyPhraseHandler ($invocation, $phrase, $content, $modifier, $link)
{
	if ($link && $invocation->texy->wikiWidgetMode)
	{
		$wikiPageId = intval($link->raw);
		$el = \TexyHtml::el();
		$el->parseLine($invocation->texy, "<span class='e10-widget-trigger' data-action='load-page' data-action-params='pageId=$wikiPageId'>" . strval($content) . "</span>");

		return $el;
	}

	if ($link && $invocation->texy->externalLinks && substr($link->raw, 0, 4) === 'http')
	{
		$link->modifier->attrs['target'] = '_blank';
		$link->modifier->attrs['rel'] = 'noopener';
	}

	return $invocation->proceed();
}


function texyLinkURLHandler($invocation, $link)
{
	if ($link && $invocation->texy->externalLinks)
	{
		$link->modifier->attrs['target'] = '_blank';
		$link->modifier->attrs['rel'] = 'noopener';
	}
	return $invocation->proceed();
}
