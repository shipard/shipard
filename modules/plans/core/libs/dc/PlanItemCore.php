<?php


namespace plans\core\libs\dc;
use \Shipard\Base\DocumentCard;
use \e10\web\WebTemplateMustache;

/**
 * class PlanItemCore
 */
class PlanItemCore extends DocumentCard
{
  public function createContentBody ()
	{
		$planCfg = $this->app()->cfgItem('plans.plans.'.$this->recData['plan'], NULL);
		$useWorkOrders = $planCfg['useWorkOrders'] ?? 0;
		$useProjectId = $planCfg['useProjectId'] ?? 0;
		$usePrice = $planCfg['usePrice'] ?? 0;
		$useAnnots = $planCfg['useAnnots'] ?? 0;
		$useText = $planCfg['useText'] ?? 0;

    // -- text
    if ($useText && $this->recData ['text'] && $this->recData ['text'] !== '')
    {
      $textRenderer = new \lib\core\texts\Renderer($this->app());

      $template = new WebTemplateMustache ($this->app());
			$page = ['tableId' => $this->table->tableId()];
      $textRenderer->setOwner ($page);
      $textRenderer->render($this->recData ['text']);
      $code = $template->renderPagePart('content', $textRenderer->code);

      $this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code/*$textRenderer->code*/, 'pane' => 'e10-pane e10-pane-table pageText']);
    }

    // annots
    if ($useAnnots)
    {
      $annots = new \e10pro\kb\libs\AnnotationsList($this->app());
      $annots->addRecord($this->table->ndx, $this->recData['ndx']);
      $annots->load();
      $code = $annots->code();

      if ($code !== '')
      {
        $title = [['text' => 'Odkazy', 'class' => 'h1']];
        $this->addContent ('body', [
          'pane' => 'e10-pane e10-pane-table pageText', 'paneTitle' => $title,
          'type' => 'line', 'line' => ['code' => $code],
        ]);
      }
    }
  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}