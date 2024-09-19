class WidgetVendM extends ShipardWidgetBoard
{
  elmTableBoxes = null;
  elmSBDisplayItemName = null;
  elmSBDisplayItemPrice = null;
  elmCardCodeInput = null;

  vmMode = 'select';

  rfidReaderTopic = '';
  tempBottomSensorTopic = '';
  topicMotorsMatrix = '';
  topicMotorsBusy = '';

  setupModeCards = null;
  setupUrl = '';

  itemName = '';
  itemNdx = 0;
  personNdx = 0;
  itemPrice = 0;
  itemBoxId = '';
  itemBoxNdx = '';
  motorsMatrixValue = '';
  restCreditAmount = 0;

  buyTimeout = 0;

  init (rootElm)
  {
    super.init(rootElm);

    this.rfidReaderTopic = this.rootElm.getAttribute('data-reader-rfid');
    this.tempBottomSensorTopic = this.rootElm.getAttribute('data-temp-sensor-bottom');

    this.elmTableBoxes = this.rootElm.querySelector('table.vmSelectBox');
    this.elmSBDisplayItemName = document.getElementById('vm-select-box-display-item-name');
    this.elmSBDisplayItemPrice = document.getElementById('vm-select-box-display-item-price');
    this.elmCardCodeInput = document.getElementById('vm-card-code-input');

    let setupModeCardsStr = this.rootElm.getAttribute('data-setup-mode-cards');
    if (setupModeCardsStr !== undefined)
      this.setupModeCards = setupModeCardsStr.split(' ');

    this.setupUrl = this.rootElm.getAttribute('data-setup-url');
    this.topicMotorsBusy = this.rootElm.getAttribute('data-sensor-busy');
    this.topicMotorsMatrix = this.rootElm.getAttribute('data-base-topic') + '/M';

    this.elmCardCodeInput.addEventListener("keypress", function(event) {
      if (event.key === "Enter") {
        event.preventDefault();
        this.validateCardCode();
      }
    }.bind(this));
  }

  doAction (actionId, e)
  {
    //console.log("VM-ACTION: ", actionId);
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
    if (data.response.classId === 'vendms-validate-code' && this.vmMode === 'info-card')
    {
      if (data.response.validPerson !== 1)
      {
        document.getElementById('card-holder-info').innerText = 'Neznámá karta / čip';
        document.getElementById('rest-credit-info').innerText = '';
      }
      else
      {
        document.getElementById('card-holder-info').innerText = data.response.personName;
        document.getElementById('rest-credit-info').innerText = 'Kredit: ' + data.response.creditAmount + ' Kč';
      }
      setTimeout(function() {location.reload();}, 30000);
      return;
    }

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

      this.restCreditAmount = currentCredit - this.itemPrice;
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
      }
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
    this.motorsMatrixValue = e.getAttribute('data-box-mm');

    if (this.buyTimeout != 0)
      clearTimeout(this.buyTimeout);
    this.buyTimeout = setTimeout(function() {location.reload();}, 120000);

    return 1;
  }

  buyGetCard (e)
  {
    this.selectBox (e);
    this.setVMMode('get-card');
    this.elmCardCodeInput.value = '';

    this.elmSBDisplayItemName.innerText = this.itemName;
    this.elmSBDisplayItemPrice.innerText = this.itemPrice + ' Kč';

    return 1;
  }

  buyCancel (e)
  {
    if (this.buyTimeout != 0)
      clearTimeout(this.buyTimeout);
    this.buyTimeout = 0;
    this.setVMMode('select');

    return 1;
  }

  validateCardCode (cardId)
  {
    let cardCode = this.elmCardCodeInput.value;
    if (cardId !== undefined)
      cardCode = cardId;
    console.log("validateCardCode: ", cardCode);

    this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));
    this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));
    this.elmShow(this.rootElm.querySelector('div.statusCardVerify'));

    this.apiCallObject('vendms-validate-code', {'cardCode': cardCode});

    return 1;
  }

  setVMMode(mode)
  {
    this.vmMode = mode;

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
      document.getElementById('rest-credit-amount').innerText = this.restCreditAmount + ' Kč';
    }
    else if (mode === 'info-card')
      {
        this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));
        this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));
        this.elmHide(this.rootElm.querySelector('div.statusCardVerify'));

        document.getElementById('vm-mode-info-card').classList.add('active');
      }
    }

  doBuyCreateInvoice ()
  {
    if (this.buyTimeout != 0)
      clearTimeout(this.buyTimeout);
    this.buyTimeout = 0;

    shc.mqtt.publish(1, this.topicMotorsMatrix, this.motorsMatrixValue);

    this.apiCallObject('vendms-create-invoice', {'itemNdx': this.itemNdx, 'boxNdx': this.itemBoxNdx, 'personNdx': this.personNdx});
  }

  doRfidReader(cardId)
  {
    console.log("RFID1: ", cardId);

    if (this.vmMode === 'select')
    { // show card info / start setup mode

      if (this.setupModeCards !== null && this.setupModeCards.indexOf(cardId) >= 0)
      {
        document.location.href = this.setupUrl;
        return;
      }

      this.setVMMode('info-card');
      this.validateCardCode(cardId);
      return;
    }
    if (this.vmMode === 'get-card')
    { // show card info
      this.validateCardCode(cardId);
      return;
    }
  }

  onMqttMessage (serverIndex, topic, payload)
  {
    console.log("vendms - onMqttMessage: ", topic, payload);

    if (topic === this.rfidReaderTopic)
    {
      this.doRfidReader(payload['value']);
    }

    if (topic == this.tempBottomSensorTopic)
    {
      document.getElementById('vm-temp-bottom').innerText = parseFloat(payload['value']) + ' °C';
    }

    console.log("topicBusy: ", this.topicMotorsBusy);
    if (topic == this.topicMotorsBusy)
    {
      console.log("YES, busy...");
      if (parseInt(payload['value']) == 0)
      {
        console.log("!!!RELOAD");
        location.reload();
      }
    }
  }
}

function initWidgetVendM(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new WidgetVendM();
  e.shpWidget.init(e);
}
