<?php
namespace e10mnf\mf\libs\dc;
use \Shipard\Base\DocumentCard;


/**
 * class DCProduct
 */
class DCProduct extends DocumentCard
{
  var \e10mnf\mf\libs\ProductInfo $productInfo;
  var $fixedVariantNdx = 0;

  public function createContentBody ()
	{
    $paneTitle = ['text' => 'Materiál', 'class' => 'h2 block pb1', 'icon' => 'tables/e10.witems.items'];
    if ($this->productInfo->countVariants && !$this->fixedVariantNdx)
    {
      $tabs = [];
      foreach ($this->productInfo->data['variants'] as $vid => $variantItem)
      {
        $content = [
          [
            'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $paneTitle,
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
      $vid = 'V'.$this->fixedVariantNdx;
      $variantItem = $this->productInfo->data['variants'][$vid];
      $content =
        [
          'pane' => 'e10-pane e10-pane-table', 'paneTitle' => $paneTitle,
          'type' => 'table', 'table' => $variantItem['tableBOM'], 'header' => $this->productInfo->data['headerBOM'],
          'main' => TRUE, 'params' => ['precision' => 0],
        ];

      $this->addContent('body', $content);
    }
  }

  protected function createProductInfo($productNdx)
  {
    $this->productInfo = new \e10mnf\mf\libs\ProductInfo($this->app());
    $this->productInfo->setProduct($productNdx);
    $this->productInfo->run();
  }

  public function createContent ()
	{
    $this->createProductInfo($this->recData['ndx']);
		$this->createContentBody ();
	}
}
