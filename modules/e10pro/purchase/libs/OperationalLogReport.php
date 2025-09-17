<?php

namespace e10pro\purchase\libs;
use \Shipard\Utils\Utils;


/**
 * class OperationalLogReport
 */
class OperationalLogReport extends \e10doc\core\libs\reports\GlobalReport
{
  var $periodBegin = NULL;
  var $periodEnd = NULL;

	public function init ()
	{
		$this->addParam ('calendarMonth', 'calendarMonth', ['flags' => ['quarters', 'halfs', 'years']]);

		parent::init();

		if (!$this->periodBegin)
			$this->periodBegin = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateBegin']);

		if (!$this->periodEnd)
			$this->periodEnd = Utils::createDateTime($this->reportParams ['calendarMonth']['values'][$this->reportParams ['calendarMonth']['value']]['dateEnd']);
  }

  function createContent ()
	{
		$this->createContent_All ();
	}

  public function createContent_All()
  {
    $text = '';
    $textRenderer = new \lib\core\texts\Renderer($this->app());
    $textRenderer->firstHeaderSize = 3;

    $q = [];
    array_push ($q, 'SELECT [log].*');
		array_push ($q, ' FROM e10pro_purchase_operationalLog AS [log]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [log].[docState] = %i', 4000);
    if ($this->periodBegin)
      array_push ($q, ' AND [log].[date] >= %d', $this->periodBegin);
    if ($this->periodEnd)
      array_push ($q, ' AND [log].[date] <= %d', $this->periodEnd);
		array_push ($q, ' ORDER BY [log].[date], [log].ndx');

		$rows = $this->app->db()->query ($q);
		forEach ($rows as $r)
		{
      $text .= "<div class='pageText'>";
      $text .= "<h1>".$r['title'].'</h1>'."\n";
      $textRenderer->render($r['text']);
      $text .= $textRenderer->code;
      $text .= "</div>";

      $text .= "<div class='pageBreakAfter'></div>\n";
		}

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $text]);
		$this->setInfo('title', 'Provozní deník');
  }
}
