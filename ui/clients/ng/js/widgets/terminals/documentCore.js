class ShipardWidgetDocumentCore extends ShipardWidget
{
  docRowsTableElm = null;
  displayAllElm = null;
  displayValueElm = null;

  elmIntro = null;
  elmContainerSell = null;
  elmContainerPay = null;
  elmContainerSave = null;

  doc = null;
  mode = '';

  init (rootElm)
  {
    super.init(rootElm);

    this.docRowsTableElm = this.rootElm.querySelector('div.rows>table.rows');
    this.elmIntro = this.rootElm.querySelector('div.cash-box-rows-content>div.docTermIntro');

    this.displayAllElm = this.rootElm.querySelector('div.display');
    this.displayValueElm = this.displayAllElm.querySelector('div.total');

    this.elmContainerSell = this.rootElm.querySelector('div.cash-box-container-sell');
    this.elmContainerPay = this.rootElm.querySelector('div.cash-box-container-pay');
    this.elmContainerSave = this.rootElm.querySelector('div.cash-box-container-save');
  }
}
