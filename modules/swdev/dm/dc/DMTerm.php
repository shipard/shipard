<?php

namespace swdev\dm\dc;

use e10\utils, e10\json;


/**
 * Class DMTable
 * @package swdev\dm\dc
 */
class DMTerm extends \e10\DocumentCard
{
	function addHead()
	{
	}

	function addText()
	{
		$textRenderer = new \lib\core\texts\Renderer($this->app());
		$textRenderer->render($this->recData ['text']);

		$this->addContent ('body',
			['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'pageText e10-pane-table e10-pane-vitem']);


		$allLinks = [];
		$this->table->getSeeAlsoLinks($this->recData,$allLinks, TRUE);
		$linksTable = [];
		$linksHeader = ['#' => '#', 'ndx' => 'id', 'l' => 'Odkaz'];
		foreach ($allLinks as $linkType)
		{
			$linksTable[] = [
				'ndx' => $linkType['linkTitle'],
				'_options' => ['class' => 'subheader', 'colSpan' => ['ndx' => 2], 'cellClasses' => ['ndx' => 'e10-left']]
			];
			foreach ($linkType['links'] as $link)
			{
				$detailCode = '<details>';
				$detailCode .= '<summary>'.$this->app()->ui()->composeTextLine ($link['title']).'</summary>';
				$detailCode .= "<div class='pageText'>".$link['textCode'].'</div>';
				$detailCode .= '</details>';

				$linksTable[] = [
					'ndx' => ['text' => '#'.$link['ndx'], 'docAction' => 'edit', 'table' => 'swdev.dm.terms', 'pk' => $link['ndx']],
					'l' => ['code' => $detailCode],//$link['title'],
					'_options' => ['cellClasses' => ['l' => 'width90']]
				];
			}
		}

		if (count($linksTable))
		{
			$this->addContent('body',
				[
					'pane' => 'e10-pane', 'type' => 'table',
					'header' => $linksHeader, 'table' => $linksTable,
					'params' => ['hideHeader' => 1, '_forceTableClass' => 'fullWidth']
				]);
		}
	}

	public function createContentBody ()
	{
		$this->addHead();
		$this->addText();
	}


	public function createContent ()
	{
		$this->createContentBody ();
	}
}
