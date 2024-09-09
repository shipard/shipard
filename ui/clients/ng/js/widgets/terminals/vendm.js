class WidgetVendM extends ShipardWidgetBoard
{
  elmTableBoxes = null;
  elmSBDisplayItemName = null;
  elmSBDisplayItemPrice = null;
  elmCardCodeInput = null;

  itemName = '';
  itemNdx = 0;
  personNdx = 0;
  itemPrice = 0;
  itemBoxId = '';
  itemBoxNdx = '';

  init (rootElm)
  {
    super.init(rootElm);

    this.elmTableBoxes = this.rootElm.querySelector('table.vmSelectBox');
    this.elmSBDisplayItemName = document.getElementById('vm-select-box-display-item-name');
    this.elmSBDisplayItemPrice = document.getElementById('vm-select-box-display-item-price');
    this.elmCardCodeInput = document.getElementById('vm-card-code-input');

    this.elmCardCodeInput.addEventListener("keypress", function(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        this.validateCardCode();
      }
    }.bind(this));
  }

  doAction (actionId, e)
  {
    console.log("VM-ACTION: ", actionId);
    switch (actionId)
    {
      case 'vmSelectBox': return this.selectBox(e);
      case 'vmBuyGetCard': return this.buyGetCard(e);
      case 'vmBuyCancel': return this.buyCancel(e);
    }
    return super.doAction (actionId, e);
  }

  doApiObjectResponse(data)
  {
    if (data.response.classId === 'vendms-validate-code')
    {
      this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));
      this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));
      this.elmHide(this.rootElm.querySelector('div.statusCardVerify'));

      if (data.response.validPerson !== 1)
      {
        this.elmShow(this.rootElm.querySelector('div.statusInvalidCode'));
        this.elmCardCodeInput.value = '';
        return;
      }

      let currentCredit = parseInt(data.response.creditAmount);
      if (currentCredit < this.itemPrice)
      {
        this.elmShow(this.rootElm.querySelector('div.statusInvalidCredit'));
        this.rootElm.querySelector('div.statusInvalidCreditAmount').innerText = 'Výše kreditu: '+currentCredit+' Kč';
        this.elmCardCodeInput.value = '';
        return;
      }

      this.personNdx = data.response.personNdx;
      this.setVMMode('do-buy');
      this.doBuyCreateInvoice();
      return;
    }
    if (data.response.classId === 'vendms-create-invoice')
      {
        if (data.response.success !== 1)
        {
          return;
        }
        this.doBuyEjectItem();
      }
      console.log("VM_doApiObjectResponse", data);
  }

  selectBox (e)
  {

    let oldBoxId = '';
    let oldActiveElement = this.elmTableBoxes.querySelector('td.active');
    if (oldActiveElement)
    {
      oldBoxId = oldActiveElement.getAttribute('data-box-id');
      oldActiveElement.classList.remove('active');
    }

    let newBoxId = e.getAttribute('data-box-id');

    e.classList.add('active');

    this.itemName = e.getAttribute('data-item-name');
    this.itemPrice = parseFloat(e.getAttribute('data-item-price'));
    this.itemNdx = parseInt(e.getAttribute('data-item-ndx'));
    this.itemBoxId = newBoxId;
    this.itemBoxNdx = e.getAttribute('data-box-ndx');

    return 1;
  }

  buyGetCard (e)
  {
    this.selectBox (e);
    this.setVMMode('get-card');
    this.elmCardCodeInput.value = '';

    this.elmSBDisplayItemName.innerText = this.itemName;
    this.elmSBDisplayItemPrice.innerText = this.itemPrice;

    return 1;
  }

  buyCancel (e)
  {
    console.log('buyCancel');
    this.setVMMode('select');
    return 1;
  }

  validateCardCode ()
  {
    let cardCode = this.elmCardCodeInput.value;
    console.log("validateCardCode: ", cardCode);

    this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));
    this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));
    this.elmShow(this.rootElm.querySelector('div.statusCardVerify'));

    this.apiCallObject('vendms-validate-code', {'cardCode': cardCode});

    return 1;
  }

  setVMMode(mode)
  {
    let oldActiveElement = this.rootElm.querySelector('div.vmMode.active');
    oldActiveElement.classList.remove('active');

    if (mode === 'select')
    {
      document.getElementById('vm-mode-select').classList.add('active');
    }
    else if (mode === 'get-card')
    {
      this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));
      this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));
      this.elmHide(this.rootElm.querySelector('div.statusCardVerify'));

      document.getElementById('vm-mode-buy-get-card').classList.add('active');
      this.elmCardCodeInput.value = '';
      this.elmCardCodeInput.focus();
    }
    else if (mode === 'do-buy')
    {
      document.getElementById('vm-mode-buy-in-progress').classList.add('active');
    }
  }

  doBuyCreateInvoice ()
  {
    console.log('create_invoice');
    this.apiCallObject('vendms-create-invoice', {'itemNdx': this.itemNdx, 'boxNdx': this.itemBoxNdx, 'personNdx': this.personNdx});
  }

  doBuyEjectItem ()
  {
    location.reload();
  }
}

function initWidgetVendM(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new WidgetVendM();
  e.shpWidget.init(e);
}
