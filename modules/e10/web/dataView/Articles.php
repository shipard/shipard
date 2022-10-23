<?php

namespace e10\web\dataView;
use \lib\dataView\DataView, \e10\utils, \e10\web\webPages;


/**
 * Class Articles
 * @package e10\web\dataView
 */
class Articles extends DataView
{
	/** @var \e10\web\TableArticles */
	var $tableArticles;

	var $maxCount = 10;
	var $urlPrefix = '';
	var $today;

	var $buttonText = '';

	protected function init()
	{
		parent::init();
		$this->tableArticles = $this->app()->table('e10.web.articles');

		$this->maxCount = $this->requestParam ('maxCount', 6);
		$this->urlPrefix = $this->requestParam('urlPrefix');
		$this->buttonText = $this->requestParam('buttonText', 'Přečíst');

		$this->checkRequestParamsList('section');

		$this->today = utils::today('Y-m-d');
	}

	protected function loadData()
	{
		$texy = new \e10\web\E10Texy($this->app());
		$texy->headingModule->top = 3;

		$q [] = 'SELECT articles.*, persons.fullName AS authorFullName FROM [e10_web_articles] AS articles';
		array_push ($q, ' LEFT JOIN e10_persons_persons AS persons ON articles.author = persons.ndx');
		array_push ($q, ' WHERE 1');

		if (count($this->requestParams['section']))
			array_push($q, ' AND articles.[articleSection] IN %in', $this->requestParams['section']);

		if (webPages::$secureWebPage)
			array_push ($q, "AND articles.[docStateMain] != 4 ");
		else
			array_push ($q, 'AND articles.[docState] IN (4000, 8000) ',
				'AND (articles.[datePub] IS NULL OR articles.[datePub] <= %d)', $this->today,
				'AND (articles.[dateClose] IS NULL OR articles.[dateClose] >= %d)', $this->today);

		array_push ($q, ' ORDER BY articles.[onTop] DESC, articles.[datePub] DESC, articles.ndx DESC');
		array_push ($q, ' LIMIT 0, %i', $this->maxCount);

		$t = [];
		$pks = [];

		// -- articles
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'ndx' => $r['ndx'],
				'title' => $r['title'],
				'author' => $r['authorFullName'],
				'datePub' => $r['datePub'],
			];

			$texy->setOwner($r, 'e10.web.articles');
			$item['perex'] = $this->template->renderPagePart('content', $texy->process ($r['perex']));
			$item['htmlText'] = $this->template->renderPagePart('content', $texy->process ($r['text']));
			if ($this->urlPrefix !== '')
				$item['url'] = $this->urlPrefix.'/'.$r['ndx'];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- images
		$images = \e10\base\getDefaultImages ($this->app(), 'e10.web.articles', $pks, ['-q76'], TRUE);
		foreach ($images as $articleNdx => $articleImage)
		{
			$t[$articleNdx]['image'] = $articleImage;
		}

		$this->data['header'] = ['#' => '#', 'ndx' => 'ndx', 'title' => 'Titulek'];
		$this->data['table'] = $t;
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'list')
			return $this->renderDataAsList();
		if ($showAs === 'cards')
			return $this->renderDataAsCards();
		if ($showAs === 'carousel')
			return $this->renderDataAsCarousel();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsList()
	{
		$urlPrefix = $this->requestParam('urlPrefix');
		$c = '';

		$elementClass = $this->requestParam('elementClass', 'dataView-places-list');

		$c .= "<ul class='$elementClass'>";

		foreach ($this->data['table'] as $articleNdx => $article)
		{
			$c .= '<li>';
			$c .= "<a href='{$article['url']}'>".utils::es($article['title']).'</a>';
			if ($article['datePub'])
				$c .= "<br><small>".utils::datef($article['datePub']).'</small>';
			$c .= '</li>';
		}

		$c .= "</ul>";

		return $c;
	}

	protected function renderDataAsCards()
	{
		$c = '';

		$cardClass = utils::es($this->requestParam('elementClass', 'article-cards-a'));

		$c .= "<div class='card-deck $cardClass'>";
		foreach ($this->data['table'] as $article)
		{
			$imgSrc = '';
			if (isset($article['image']))
				$imgSrc = $article['image']['smallImage'];

			$c .= "<div class='card'>";
			if ($imgSrc !== '')
				$c .= "<div class='card-picture' style='background-image:url($imgSrc);'></div>";
			$c .= "<div class='card-body'>";
			$c .= '<h5>'.utils::es($article['title']).'</h5>';

			if (isset($article['perex']) && $article['perex'] !== '')
			{
			//	$c .= "<p class='card-text'>";
				$c .= $article['perex'];
			//	$c .= "</p>";
			}

			if (isset($article['url']))
			{
				$c .= "<a href='{$article['url']}' class='btn btn-primary'>";
				$c .= utils::es($this->buttonText);
				$c .= '</a>';
			}


			$c .= "</div>";
			$c .= "</div>";
		}
		$c .= '</div>';

		return $c;
	}


	protected function renderDataAsCarousel()
	{
		$c = '';

		$cardClass = utils::es($this->requestParam('elementClass', 'article-carousel-a'));
		$carouselId = 'carousel_'.time().mt_rand(100000, 9999999);

		$c .= "<div id='$carouselId' class='carousel slide $cardClass' data-ride='carousel'>";
		$c .= "<ol class='carousel-indicators'>";

		$ndx = 0;
		$active = " class='active'";
		foreach ($this->data['table'] as $article)
		{
			$c .= "<li data-target='#{$carouselId}' data-slide-to='{$ndx}'{$active}></li>";
			$ndx++;
			$active = '';
		}
		$c .= "</ol>";

		$c .= "<div class='carousel-inner'>";
		$ndx = 0;
		$active = " active";
		foreach ($this->data['table'] as $article)
		{
			$imgSrc = '';
			if (isset($article['image']))
				$imgSrc = $article['image']['smallImage'];

			$c .= "<div class='carousel-item{$active}'>";
			$c .= "<a href='{$article['url']}'>";
			if ($imgSrc !== '')
				$c .= "<div class='carousel-picture' style='background-image:url($imgSrc);'></div>";

				$c .= "<div class='carousel-caption'>";
				$c .= '<h5>'.utils::es($article['title']).'</h5>';
				//if (isset($article['perex']) && $article['perex'] !== '')
				//	$c .= $article['perex'];
				$c .= "</div>";
				$c.= '</a>';
			$c .= "</div>";

			$ndx++;
			$active = '';
		}

		$c .= "<a class='carousel-control-prev' href='#{$carouselId}' role='button' data-slide='prev'>";
		$c .= "<span class='carousel-control-prev-icon' aria-hidden='true'></span>";
		$c .= "<span class='sr-only'>Previous</span>";
		$c .= "</a>";
		$c .= "<a class='carousel-control-next' href='#{$carouselId}' role='button' data-slide='next'>";
		$c .= "<span class='carousel-control-next-icon' aria-hidden='true'></span>";
		$c .= "<span class='sr-only'>Next</span>";
		$c .= "</a>";

		$c .= '</div>';
		$c .= '</div>';

		return $c;
	}

}
