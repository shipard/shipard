class WidgetCashBox extends ShipardWidgetDocumentCore
{
  init (rootElm)
  {
    super.init(rootElm);
    console.log("hello, cashBox", this.rootId);

    this.documentInit();
  }

  doAction (actionId, e)
  {
    console.log("ACTION: ", actionId);
    switch (actionId)
    {
      case 'addRow': return this.newRow(e);
      case 'quantity-plus': return this.documentQuantityRow(e, 1);
      case 'quantity-minus': return this.documentQuantityRow(e, -1);
      case 'remove-row': return this.documentRemoveRow(e);

      case 'terminal-sell': return this.setMode('sell');
      case 'terminal-pay': return this.doPay(e);
      case 'terminal-save': return this.save();

      case 'change-payment-method': return this.changePaymentMethod(e);
    }
    return super.doAction (actionId, e);
  }

  itemFromElement (e)
  {
    var item = {
      pk: e.getAttribute('data-pk'),
      price: parseFloat(e.getAttribute('data-price')),
      quantity: (e.getAttribute('data-quantity')) ? parseFloat(e.getAttribute('data-quantity')) : 1,
      name: e.getAttribute('data-name'),
      askq: e.getAttribute('data-askq'),
      askp: e.getAttribute('data-askp'),
      unit: e.getAttribute ('data-unit'),
      unitName: e.getAttribute ('data-unit-name')
    };

    return item;
  }

  newRow (e)
  {
    //console.log("newRow");
    var askq = parseInt(e.getAttribute('data-askq'));
    var askp = parseInt(e.getAttribute('data-askp'));
    if (!askq && !askp)
    {
      this.addDocumentRow(this.itemFromElement(e));
      return 1;
    }

    if (askp) {
      this.getNumber(
          {
            title: 'Zadejte cenu ' + '(' + e.getAttribute('data-unit-name') + ')',
            subtitle: e.getAttribute('data-name'),
            srcElement: e,
            askType: 'p',
            success: function() {this.addDocumentRow()}.bind(this)
          }
      );
      return 1;
    }

    if (askq) {
      this.getNumber(
          {
            title: 'Zadejte množství ' + '(' + e.getAttribute('data-unit-name') + ')',
            subtitle: e.getAttribute('data-name'),
            srcElement: e,
            askType: 'q',
            success: function() {this.addDocumentRow()}.bind(this)
          }
      );
    }

    return 1;
  }

  addDocumentRow (item)
  {
    var quantity = 1;
    //console.log("addDoucmentRow", item);


    if (!item)
    {
      //e10.form.getNumberClose();
      item = this.itemFromElement(this.numPad.options.srcElement);

      if (this.numPad.options.askType === 'p')
      {
        var price = e10.parseFloat(this.numPad.gnValue);
        if (!price)
          price = null;
        if (price !== null)
          item.price = price;
      }
      else if (!this.numPad.options.askType || this.numPad.options.askType === 'q')
      {
        quantity = this.parseFloat(this.numPad.gnValue);
        if (!quantity)
          quantity = 1;
      }

      this.numPad.rootElm.remove();
      this.numPad = null;
    }
    else
    if (item.quantity)
      quantity = item.quantity;

    var priceStr = this.nf(item.price, 2);
    var totalPrice = this.round(quantity * item.price, 2);
    var totalPriceStr = this.nf(totalPrice, 2);

    var row = '<tr' +
        ' data-pk="' + item.pk + '"' +
        ' data-quantity="' + quantity + '"' +
        ' data-price="' + item.price + '"' +
        ' data-totalprice="' + totalPrice + '"' +

        '>';

    row += '<td class="shp-widget-action" data-action="remove-row">×</td>';

    row +=
        '<td class="item">' + '<span class="t">'+this.escapeHtml(item.name) + '</span>' + '<br>' +
        '<span class="e10-small i e10-terminal-action" data-action="row-price-item-change">' + quantity + ' ' + item.unitName + ' á '+priceStr+' = <b>'+totalPriceStr+'</b>'+'</span>' +
        '</td>';


    row += '<td class="q number">' + quantity + '</td>';

    row += '<td class="shp-widget-action" data-action="quantity-plus">+</td>';
    row += '<td class="shp-widget-action" data-action="quantity-minus">-</td>';

    row += '</tr>';

    this.docRowsTableElm.innerHTML = row + this.docRowsTableElm.innerHTML;


    let re = this.docRowsTableElm.rows[0];
    re.setAttribute ('data-unit', item.unit);
    re.setAttribute ('data-unit-name', item.unitName);
    re.setAttribute ('data-name', item.name);

    this.documentRecalc();
  }

  documentInit (clearUI)
  {
    if (this.doc !== null)
      delete this.doc;

    this.doc = {
      rec: {
        docType: "cashreg",
        currency: "czk",
        paymentMethod: 1 /*e10.terminal.detectPaymentMethod()*/,
        taxCalc: parseInt(this.rootElm.getAttribute ('data-taxcalc')),
        automaticRound: 1,
        roundMethod: parseInt(this.rootElm.getAttribute ('data-roundmethod')),
        cashBox: parseInt(this.rootElm.getAttribute ('data-cashbox')),
        warehouse: parseInt(this.rootElm.getAttribute ('data-warehouse')),
        docState: 4000,
        docStateMain: 2,
        toPay: 0.0
      },
      rows: []
    };

    if (clearUI === true)
    {
      while (this.docRowsTableElm.rows.length)
        this.docRowsTableElm.rows[0].remove();
      this.documentRecalc();
    }
  };

  documentRecalc ()
  {
    var rowsCount = 0;
    var totalPrice = 0.0;

    this.doc.rows.length = 0;

    for (let i = 0; i < this.docRowsTableElm.rows.length; i++)
    {
      var r = this.docRowsTableElm.rows[i];
      var rowTotalPrice = parseFloat(r.getAttribute('data-totalprice'));
      totalPrice += rowTotalPrice;
      rowsCount++;

      var documentRow = {
        item: parseInt(r.getAttribute('data-pk')),
        text: r.getAttribute ('data-name'),//r.children('td').eq(1).children('span').eq(0).text(),
        quantity: parseFloat(r.getAttribute('data-quantity')),
        unit: r.getAttribute('data-unit'),
        priceItem: parseFloat(r.getAttribute('data-price'))
      };

      this.doc.rows.push(documentRow);
    }

    const totalPriceStr = this.nf(totalPrice, 2);
    let toPay = (this.doc.rec.roundMethod == 1) ? this.round(totalPrice, 0) : totalPrice;
    this.doc.toPay = toPay;

    let displayToPay = this.elmContainerPay.querySelector ('div.paymentAmount>span.money-to-pay');
    displayToPay.innerText = this.nf(toPay, 2);

    this.displayValueElm.innerText = totalPriceStr;

    if (rowsCount) {
      this.elmHide(this.elmIntro);
      this.elmShow(this.docRowsTableElm);
    }
    else
    {
      this.elmHide(this.docRowsTableElm);
      this.elmShow(this.elmIntro);
    }
  }

  documentQuantityRow (e, how)
  {
    var row = e.parentElement;

    var quantity = parseFloat(row.getAttribute ('data-quantity'));

    if (how === -1 && quantity <= 1.0)
      return;

    quantity += how;

    var price = parseFloat(row.getAttribute('data-price'));
    var totalPrice = quantity * price;

    var quantityStr = quantity;

    row.setAttribute ('data-quantity', quantity);
    row.setAttribute ('data-totalprice', totalPrice);
    row.querySelector('td.q').innerText = quantityStr;

    var unitName = row.getAttribute ('data-unit-name');

    var rowInfo = quantityStr + ' ' + unitName + ' á ' + this.nf (price, 2) +' = <b>'+this.nf (totalPrice, 2)+'</b>';
    row.querySelector('td.item>span.i').innerHTML = rowInfo;

    this.documentRecalc();

    return 0;
  }

  documentRemoveRow (e)
  {
    var row = e.parentElement;
    row.remove();
    this.documentRecalc();

    return 0;
  }

  doPay (e)
  {
    var paymentMethod = e.getAttribute ('data-pay-method');
    console.log('payment method: ', paymentMethod);
    //var paymentMethodButton = e10.terminal.boxWidget.find ('div.pay-methods>span[data-pay-method="'+paymentMethod+'"]');
    this.changePaymentMethod(e);
    this.setMode('pay');
  }

  changePaymentMethod (e)
  {
    let paymentMethod = parseInt(e.getAttribute ('data-pay-method'));
    this.doc.rec.paymentMethod = parseInt (paymentMethod);

    if (this.doc.rec.paymentMethod == 2) // card
      this.doc.rec.roundMethod = 0;
    else
      this.doc.rec.roundMethod = parseInt(this.rootElm.getAttribute ('data-roundmethod'));

    this.documentRecalc();

    const radioButtons = this.elmContainerPay.querySelectorAll('input[type="radio"]');

    console.log('set-pay-method: ', paymentMethod, e);
    for (const radioButton of radioButtons)
    {
      console.log(radioButton);
      if (parseInt(radioButton.value) == paymentMethod)
        radioButton.checked = true;
      else
        radioButton.checked = false;
    }

    return 0;
  }

  save ()
  {
    console.log("__SAVE__");

    //this.setDoneStatus ('sending');
    this.setMode('save');

    var printAfterConfirm = '1';

    var url = '/api/objects/insert/e10doc.core.heads?printAfterConfirm='+printAfterConfirm;

    shc.server.post (url, this.doc,
      function (data) {
        console.log("--save-success--");
        this.documentInit(true);
        this.setMode('sell');
        //e10.terminal.setDoneStatus ('success');
      }.bind(this),
      function (data) {
        console.log("--save-error--");
        //e10.terminal.setDoneStatus ('error');
      }.bind(this)
    );

    return 0;
  }

  setMode (mode)
  {
    if (mode === 'pay')
    {
      console.log("do-pay", this.elmContainerSell);
      this.elmHide(this.elmContainerSell);
      this.elmHide(this.elmContainerSave);
      this.elmShow(this.elmContainerPay);
    }
    else
    if (mode === 'sell')
    {
      this.elmHide(this.elmContainerSave);
      this.elmHide(this.elmContainerPay);
      this.elmShow(this.elmContainerSell);
    }
    else
    if (mode === 'save')
    {
      this.elmHide(this.elmContainerSell);
      this.elmHide(this.elmContainerPay);
      this.elmShow(this.elmContainerSave);
    }

    //e10.terminal.mode = mode;

    return 0;
  }
}


function initWidgetCashBox(id)
{
  let e = document.getElementById(id);
  e.shpWidget = new WidgetCashBox();
  e.shpWidget.init(e);
}
