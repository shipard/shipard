<?php


namespace helpdesk\core\libs\dc;
use \Shipard\Base\DocumentCard;
use \e10\web\WebTemplateMustache;

/**
 * class HelpdeskTicketCore
 */
class HelpdeskTicketCore extends DocumentCard
{
  public function createContentBody ()
	{
    // -- text
    if ($this->recData ['text'] && $this->recData ['text'] !== '')
    {
      $textRenderer = new \lib\core\texts\Renderer($this->app());
      /*
      $template = new WebTemplateMustache ($this->app());
			$page = ['tableId' => $this->table->tableId()];
      $textRenderer->setOwner ($page);
      $textRenderer->render($this->recData ['text']);
      $code = $template->renderPagePart('content', $textRenderer->code);
      */
      $textRenderer->renderAsArticle ($this->recData ['text'], $this->table);

      $this->addContent('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $textRenderer->code, 'pane' => 'e10-pane e10-pane-table pageText']);
    }
  }

  public function createContent ()
	{
		$this->createContentBody ();
	}
}