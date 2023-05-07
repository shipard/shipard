<?php
namespace e10mnf\mf\libs\dc;


/**
 * class DCProductVariant
 */
class DCProductVariant extends \e10mnf\mf\libs\dc\DCProduct
{
  public function createContentBodyTEMP ()
	{
    if ($this->productInfo->countVariants)
    {
      $tabs = [];
      foreach ($this->productInfo->data['variants'] as $vid => $variantItem)
      {
        $content = [
          [
            'pane' => 'e10-pane e10-pane-table', 'paneTitle' => 'Materiál',
            'type' => 'table', 'table' => $variantItem['tableBOM'], 'header' => $this->productInfo->data['headerBOM'],
            '__main' => TRUE, 'params' => ['precision' => 0],
          ]
        ];
        $title = ['text' => $variantItem['id'], 'icon' => 'system/iconCheckSquare', 'class' => 'TEST'];
        $tabs[] = ['title' => $title, 'content' => $content];
      }
      $this->addContent('body', ['tabsId' => 'mainTabs', 'selectedTab' => '0', 'tabs' => $tabs]);
    }
    else
    {
      $variantItem = $this->productInfo->data['variants']['V0'];
      $content =
        [
          'pane' => 'e10-pane e10-pane-table', 'paneTitle' => 'Materiál',
          'type' => 'table', 'table' => $variantItem['tableBOM'], 'header' => $this->productInfo->data['headerBOM'],
          '__main' => TRUE, 'params' => ['precision' => 0],
        ];

      $this->addContent('body', $content);
    }
  }

  public function createContent ()
	{
    $this->createProductInfo($this->recData['product']);
    $this->fixedVariantNdx = $this->recData['ndx'];

		$this->createContentBody ();
	}
}
