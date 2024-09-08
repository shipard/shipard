class WidgetVendMSetup extends ShipardWidgetBoard
{
  itemName = '';
  itemNdx = 0;
  personNdx = 0;
  itemPrice = 0;
  itemBoxId = '';
  itemBoxNdx = '';

  init (rootElm)
  {
    super.init(rootElm);
  }

  doAction (actionId, e)
  {
    switch (actionId)
    {
      case 'vmBoxSetQuantity': return this.boxSetQuantity(e);
    }
    return super.doAction (actionId, e);
  }

  doApiObjectResponse(data)
  {
    if (data.response.classId === 'vendms-box-quantity')
    {
      location.reload();
      return;
    }
  }

  boxSetQuantity (e)
  {
    let newBoxId = e.getAttribute('data-box-id');
    this.itemName = e.getAttribute('data-item-name');
    this.itemPrice = parseFloat(e.getAttribute('data-item-price'));
    this.itemNdx = parseInt(e.getAttribute('data-item-ndx'));
    this.itemBoxId = newBoxId;
    this.itemBoxNdx = e.getAttribute('data-box-ndx');

    this.getNumber(
      {
        title: 'Zadejte množství v boxu '+e.getAttribute('data-box-label'),
        subtitle: e.getAttribute('data-item-name'),
        srcElement: e,
        askType: 'q',
        success: function() {this.boxSetQuantityDoIt()}.bind(this)
      }
    );

    return 1;
  }

  boxSetQuantityDoIt()
  {
    let quantity = this.parseFloat(this.numPad.gnValue);

    this.numPad.rootElm.remove();
    this.numPad = null;

    if (!quantity)
      return;

    this.apiCallObject('vendms-box-quantity', {'itemNdx': this.itemNdx, 'boxNdx': this.itemBoxNdx, 'quantity': quantity});
  }
}

function initWidgetVendMSetup(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new WidgetVendMSetup();
  e.shpWidget.init(e);
}
