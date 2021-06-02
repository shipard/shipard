e10client.prototype.terminal = {
	widgetId: '',
	symbolForProduct: '',
	mode: 'cashbox',

	boxWidget: null,
	boxProducts: null,
	boxRows: null,
	boxPay: null,
	boxDone: null,

	document: null,

	lastPosReports: null,

	calculatorMode: 0,
	ckPrice: '',
	ckQuantity: '',
	ckMode: 0,
};

e10client.prototype.terminal.init = function (widgetId) {

	if (e10.terminal.widgetId === '')
	{
		$('body').on(e10.CLICK_EVENT, "ul.tabs>li", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.terminal.productsTabsClick($(this));
		});

		$('body').on(e10.CLICK_EVENT, ".e10-trigger-ck", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.terminal.calcKeyboard(event, $(this), 0);
		});

		$('body').on(e10.CLICK_EVENT, "div.products>span", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.terminal.newRow($(this));
		});

		$('body').on(e10.CLICK_EVENT, ".e10-terminal-action", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.terminal.action(event, $(this));
		});
	}

	e10.terminal.calculatorMode = 0;
	var activeProductsTab = $('#e10-wcb-products-tabs>li.active');
	if (activeProductsTab.attr('data-tabid') === 'e10-wcb-cat-calc_kbd')
		e10.terminal.calculatorMode = 1;

	e10.terminal.ckPrice = '';
	e10.terminal.ckQuantity = '';
	e10.terminal.ckMode = 0;

	e10.terminal.symbolForProduct = '';
	e10.terminal.widgetId = widgetId;

	e10.terminal.boxWidget = $('#'+widgetId);
	e10.terminal.boxRows = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows');
	e10.terminal.boxProducts = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products');
	e10.terminal.boxPay = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-pay');
	e10.terminal.boxDone = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-done');

	e10.terminal.setPrintRetryButton();
	e10.terminal.refreshLayout ();

	e10.terminal.documentInit();
};


e10client.prototype.terminal.refreshLayout = function () {
	var w = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content');
	var hh = $(window).height() - $('#e10-page-header').height();
	w.height(hh);

	if (e10.terminal.mode === 'cashbox')
	{
		var rowsContainer = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows');
		var display = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.display');
		var rhh = (rowsContainer.parent().innerHeight() - display.outerHeight()) | 0;
		rowsContainer.height(rhh);

		var buttonsContainer = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products>div.e10-wcb-products-buttons');
		var tabs = $('#e10-wcb-products-tabs');

		rhh = (buttonsContainer.parent().innerHeight() - tabs.outerHeight()) | 0;
		buttonsContainer.height(rhh);
	}
	else
	if (e10.terminal.mode === 'pay')
	{

	}
};


e10client.prototype.terminal.productsTabsClick = function (e) {
	var tabs = e.parent();
	var activeTab = tabs.find('>li.active');
	activeTab.removeClass('active');
	var activeTabId = activeTab.attr ('data-tabid');
	$('#'+activeTabId).hide();

	e.addClass('active');
	activeTabId = e.attr ('data-tabid');
	$('#'+activeTabId).show();

	if (activeTabId === 'e10-wcb-cat-calc_kbd')
		e10.terminal.calculatorMode = 1;
	else
		e10.terminal.calculatorMode = 0;
};

e10client.prototype.terminal.calcKeyboard = function (event, e, keyCode) {
	var number = null;
	var backspace = 0;
	var multiply = 0;
	var ok = 0;

	if (e) {
		if (e.hasClass('n'))
			number = e.text();
		if (e.hasClass('b'))
			backspace = 1;
		if (e.hasClass('multiply'))
			multiply = 1;
		if (e.hasClass('ok'))
			ok = 1;
	}
	else if (keyCode) {
		if (event.keyCode >= 48 && event.keyCode <= 57)
			number = String.fromCharCode(event.which);
		else if (event.keyCode >= 96 && event.keyCode <= 105)
			number = String.fromCharCode(event.which - 48);
		else if (event.key === ',' || event.key === '.' || event.keyCode === 110)
			number = ',';
		else if (event.keyCode === 8)
			backspace = 1;
		else if (event.keyCode === 13 || event.keyCode === 107)
			ok = 1;
		else if (event.keyCode === 88 || event.key === '*' || event.keyCode === 106)
			multiply = 1;
	}

	if (number !== null)
	{
		if (e10.terminal.ckMode === 0) {
			e10.terminal.ckPrice += number;
			$('#e10-display-ck').text(e10.terminal.ckPrice);
		}
		else
		{
			e10.terminal.ckQuantity += number;
			$('#e10-display-ck').text(e10.terminal.ckPrice + ' × ' + e10.terminal.ckQuantity);
		}
	}
	else
	if (backspace)
	{
		if (e10.terminal.ckMode === 0) {
			if (e10.terminal.ckPrice !== '') {
				e10.terminal.ckPrice = e10.terminal.ckPrice.slice(0, -1);
				$('#e10-display-ck').text(e10.terminal.ckPrice);
			}
		}
		else
		if (e10.terminal.ckMode === 1) {
			if (e10.terminal.ckQuantity !== '') {
				e10.terminal.ckQuantity = e10.terminal.ckQuantity.slice(0, -1);
				$('#e10-display-ck').text(e10.terminal.ckPrice + ' × ' + e10.terminal.ckQuantity);
			}
			else
			{
				e10.terminal.ckMode = 0;
				$('#e10-display-ck').text(e10.terminal.ckPrice);
			}
		}
	}
	else
	if (multiply)
	{
		if (e10.terminal.ckMode === 0) {
			e10.terminal.ckMode = 1;
			$('#e10-display-ck').text(e10.terminal.ckPrice + ' × ');
		}
	}
	else
	if (ok)
	{
		var quantity = 1;
		var price = e10.parseFloat(e10.terminal.ckPrice);
		if (e10.terminal.ckMode === 1)
		{
			quantity = e10.parseFloat(e10.terminal.ckQuantity);
			if (quantity == 0)
				quantity = 1;
		}

		if (!e)
			e = $('#e10-terminal-ck-primary');
		if (e) {
			e.attr('data-price', price);
			e.attr('data-quantity', quantity);
			e10.terminal.addDocumentRow(e10.terminal.itemFromElement(e));
		}

		e10.terminal.ckPrice = '';
		e10.terminal.ckQuantity = '';
		e10.terminal.ckMode = 0;
		$('#e10-display-ck').text('');
	}
};


e10client.prototype.terminal.itemFromElement = function (e) {
	var item = {
		pk: e.attr('data-pk'),
		price: parseFloat(e.attr('data-price')),
		quantity: (e.attr('data-quantity')) ? parseFloat(e.attr('data-quantity')) : 1,
		name: e.attr('data-name'),
		askq: e.attr('data-askq'),
		askp: e.attr('data-askp'),
		unit: e.attr ('data-unit'),
		unitName: e.attr ('data-unit-name')
	};

	return item;
};

e10client.prototype.terminal.newRow = function (e) {
	var askq = parseInt(e.attr('data-askq'));
	var askp = parseInt(e.attr('data-askp'));
	if (!askq && !askp) {
		e10.terminal.addDocumentRow(e10.terminal.itemFromElement(e));
		return;
	}

	if (askp) {
		e10.form.getNumber(
				{
					title: 'Zadejte cenu ' + '(' + e.attr('data-unit-name') + ')',
					subtitle: e.attr('data-name'),
					srcElement: e,
					askType: 'p',
					success: e10.terminal.addDocumentRow
				}
		);
		return;
	}

	if (askq) {
		e10.form.getNumber(
				{
					title: 'Zadejte množství ' + '(' + e.attr('data-unit-name') + ')',
					subtitle: e.attr('data-name'),
					srcElement: e,
					askType: 'q',
					success: e10.terminal.addDocumentRow
				}
		);
	}
};

e10client.prototype.terminal.addDocumentRow = function (item) {
	var quantity = 1;

	if (!item)
	{
		e10.form.getNumberClose();
		item = e10.terminal.itemFromElement(e10.form.gnOptions.srcElement);

		if (e10.form.gnOptions.askType === 'p')
		{
			var price = e10.parseFloat(e10.form.gnValue);
			if (!price)
				price = null;
			if (price !== null)
				item.price = price;
		}
		else if (!e10.form.gnOptions.askType || e10.form.gnOptions.askType === 'q')
		{
			quantity = e10.parseFloat(e10.form.gnValue);
			if (!quantity)
				quantity = 1;
		}
	}
	else
	if (item.quantity)
		quantity = item.quantity;

	var priceStr = e10.nf(item.price, 2);
	var totalPrice = e10.round(quantity * item.price, 2);
	var totalPriceStr = e10.nf(totalPrice, 2);

	var row = '<tr' +
			' data-pk="' + item.pk + '"' +
			' data-quantity="' + quantity + '"' +
			' data-price="' + item.price + '"' +
			' data-totalprice="' + totalPrice + '"' +
			'>';

	row += '<td class="e10-terminal-action" data-action="remove-row"><i class="fa fa-times"></i></td>';

	row +=
			'<td class="item">' + '<span class="t">'+e10.escapeHtml(item.name) + '</span>' + '<br>' +
			'<span class="e10-small i e10-terminal-action" data-action="row-price-item-change">' + quantity + ' ' + item.unitName + ' á '+priceStr+' = <b>'+totalPriceStr+'</b>'+'</span>' +
			'</td>';


	row += '<td class="q number">' + quantity + '</td>';

	row += '<td class="e10-terminal-action" data-action="quantity-plus"><i class="fa fa-plus"></i></td>';
	row += '<td class="e10-terminal-action" data-action="quantity-minus"><i class="fa fa-minus"></i></td>';

	row += '</tr>';


	var rows = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table');

	rows.prepend(row);
	var re = rows.find('tbody>tr').eq(0);
	re.attr ('data-unit', item.unit);
	re.attr ('data-unit-name', item.unitName);
	re.attr ('data-name', item.name);

	e10.terminal.documentRecalc();
};

e10client.prototype.terminal.documentInit = function (clearUI) {
	if (e10.terminal.document !== null)
		delete e10.terminal.document;

	e10.terminal.document = {
		rec: {
			docType: "cashreg",
			currency: "czk",
			paymentMethod: e10.terminal.detectPaymentMethod(),
			taxCalc: parseInt(e10.terminal.boxWidget.attr ('data-taxcalc')),
			automaticRound: 1,
			roundMethod: parseInt(e10.terminal.boxWidget.attr ('data-roundmethod')),
			cashBox: parseInt(e10.terminal.boxWidget.attr ('data-cashbox')),
			warehouse: parseInt(e10.terminal.boxWidget.attr ('data-warehouse')),
			docState: 4000,
			docStateMain: 2,
			toPay: 0.0
		},
		rows: []
	};

	if (clearUI === true)
	{
		var rows = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table>tbody');
		rows.empty();

		e10.terminal.documentRecalc();
	}
};


e10client.prototype.terminal.documentRecalc = function () {
	var rowsCount = 0;
	var totalPrice = 0.0;

	e10.terminal.document.rows.length = 0;

	var rows = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table>tbody');
	rows.find('>tr').each(function () {
		var r = $(this);
		var rowTotalPrice = parseFloat(r.attr('data-totalprice'));
		totalPrice += rowTotalPrice;
		rowsCount++;

		var documentRow = {
			item: parseInt(r.attr('data-pk')),
			text: r.attr ('data-name'),//r.children('td').eq(1).children('span').eq(0).text(),
			quantity: parseFloat(r.attr('data-quantity')),
			unit: r.attr('data-unit'),
			priceItem: parseFloat(r.attr('data-price'))
		};

		e10.terminal.document.rows.push(documentRow);
	});

	var totalPriceStr = e10.nf(totalPrice, 2);

	var displayTotal = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.display>li.total');
	displayTotal.text(totalPriceStr);

	var toPay = (e10.terminal.document.rec.roundMethod === 1) ? e10.round(totalPrice, 0) : totalPrice;
	e10.terminal.document.toPay = toPay;

	var displayToPay = e10.terminal.boxPay.find ('>div.pay-right>div.pay-display>span.money-to-pay');
	displayToPay.text (e10.nf(toPay, 2));

	if (rowsCount) {
		e10.terminal.boxRows.find('>div.close').hide();
		e10.terminal.boxRows.find('>div.rows').show();
	}
	else
	{
		e10.terminal.boxRows.find('>div.rows').hide();
		e10.terminal.boxRows.find('>div.close').show();
	}
};


e10client.prototype.terminal.documentQuantityRow = function (e, how) {
	var row = e.parent();

	var quantity = parseFloat(row.attr ('data-quantity'));

	if (how === -1 && quantity <= 1.0)
		return;

	quantity += how;

	var price = parseFloat(row.attr('data-price'));
	var totalPrice = quantity * price;

	var quantityStr = quantity;

	row.attr ('data-quantity', quantity);
	row.attr ('data-totalprice', totalPrice);
	row.find('td.q').text (quantityStr);

	var unitName = row.attr ('data-unit-name');

	var rowInfo = quantityStr + ' ' + unitName + ' á ' + e10.nf (price, 2) +' = <b>'+e10.nf (totalPrice, 2)+'</b>';
	row.find('td.item>span.i').html (rowInfo);

	e10.terminal.documentRecalc();

	return 0;
};

e10client.prototype.terminal.rowPriceItemChangeAsk = function (e)
{
	var row = e.parent().parent();
	e10.form.getNumber (
			{
				title: 'Zadejte novou cenu položky',
				subtitle: row.attr('data-name'),
				srcElement: row,
				success: e10.terminal.rowPriceItemChange
			}
	);
};

e10client.prototype.terminal.rowPriceItemChange = function () {
	var newPriceItem = 0.0;


	e10.form.getNumberClose();
	var row = e10.form.gnOptions.srcElement;

	newPriceItem = e10.parseFloat(e10.form.gnValue);
	row.attr('data-price', newPriceItem);

	var quantity = parseFloat(row.attr ('data-quantity'));
	var totalPrice = quantity * newPriceItem;
	row.attr ('data-totalprice', totalPrice);

	e10.terminal.documentRowResetInfo(row);

	e10.terminal.documentRecalc();
};


e10client.prototype.terminal.terminalSearchSymbolManuallyAsk = function (e)
{
	e10.form.getNumber (
			{
				title: 'Zadejte kód položky',
				subtitle: '',
				srcElement: null,
				success: e10.terminal.terminalSearchSymbolManually
			}
	);
};

e10client.prototype.terminal.terminalSearchSymbolManually = function (e) {
	e10.form.getNumberClose();
	var symbol = e10.form.gnValue;
	e10.terminal.symbolForProduct = symbol;
	e10.terminal.symbolChanged();
	e10.terminal.symbolSearch();
};

e10client.prototype.terminal.terminalSearchSymbolComboAsk = function (e)
{
	e10.form.comboViewer (
			{
				table: 'e10.witems.items',
				viewer: 'terminals.store.ItemsViewer',
				title: 'Vyberte položku pro prodej',
				success: e10.terminal.terminalSearchSymbolCombo
			}
	);
};

e10client.prototype.terminal.terminalSearchSymbolCombo = function (e) {
	var item = {
		pk: parseInt(e.attr('data-pk')),
		title: e10.b64DecodeUnicode(e.attr('data-cc-title').substring (6)),
		name: e10.b64DecodeUnicode(e.attr('data-cc-name').substring (5)),
		price: parseFloat(e10.b64DecodeUnicode(e.attr('data-cc-price').substring (6))),
		unit: e10.b64DecodeUnicode(e.attr('data-cc-unit').substring (5)),
		unitName: e10.b64DecodeUnicode(e.attr('data-cc-unitname').substring (9)),
	};

	e10.form.comboViewerClose();
	e10.terminal.addDocumentRow(item);
};

e10client.prototype.terminal.documentRemoveRow = function (e, how) {
	var row = e.parent();
	row.detach();
	e10.terminal.documentRecalc();

	return 0;
};

e10client.prototype.terminal.documentRowResetInfo = function (row) {
	var unitName = row.attr ('data-unit-name');
	var quantity = parseFloat(row.attr ('data-quantity'));
	var price = parseFloat(row.attr('data-price'));
	var totalPrice = parseFloat(row.attr('data-totalprice'));
	var quantityStr = quantity;

	var rowInfo = quantityStr + ' ' + unitName + ' á ' + e10.nf (price, 2) +' = <b>'+e10.nf (totalPrice, 2)+'</b>';
	row.find('td.item>span.i').html (rowInfo);
};

e10client.prototype.terminal.action = function (event, e) {
	var action = e.attr('data-action');

	if (action === 'quantity-plus')
		return e10.terminal.documentQuantityRow(e, 1);
	if (action === 'quantity-minus')
		return e10.terminal.documentQuantityRow(e, -1);
	if (action === 'remove-row')
		return e10.terminal.documentRemoveRow(e);
	if (action === 'terminal-pay')
		return e10.terminal.setMode('pay');
	if (action === 'terminal-cashbox')
		return e10.terminal.setMode('cashbox');
	if (action === 'change-payment-method')
		return e10.terminal.changePaymentMethod(e);
	if (action === 'do-payment-method')
		return e10.terminal.doPay(e);
	if (action === 'terminal-done')
		return e10.terminal.done();
	if (action === 'terminal-retry')
		return e10.terminal.done();
	if (action === 'terminal-queue')
		return e10.terminal.queue();
	if (action === 'terminal-symbol-clear')
		return e10.terminal.symbolClear();
	if (action === 'row-price-item-change')
		return e10.terminal.rowPriceItemChangeAsk(e);
	if (action === 'terminal-search-code-manually')
		return e10.terminal.terminalSearchSymbolManuallyAsk(e);
	if (action === 'terminal-search-code-combo')
		return e10.terminal.terminalSearchSymbolComboAsk(e);
	if (action === 'print-retry')
		return e10.terminal.printRetry();
	if (action === 'print-exit')
		return e10.terminal.printExit();
};


e10client.prototype.terminal.changePaymentMethod = function (e) {
	var paymentMethod = e.attr ('data-pay-method');
	e10.terminal.document.rec.paymentMethod = parseInt (paymentMethod);

	if (e10.terminal.document.rec.paymentMethod === 2) // card
		e10.terminal.document.rec.roundMethod = 0;
	else
		e10.terminal.document.rec.roundMethod = parseInt(e10.terminal.boxWidget.attr ('data-roundmethod'));

	e10.terminal.documentRecalc();

	e.parent().find('.active').removeClass ('active');
	e.addClass ('active');
};

e10client.prototype.terminal.detectPaymentMethod = function () {
	var e = e10.terminal.boxPay.find (">div>div.pay-methods>.e10-terminal-action.active");
	var paymentMethod = e.attr ('data-pay-method');
	return parseInt(paymentMethod);
};

e10client.prototype.terminal.done = function () {
	e10.terminal.setDoneStatus ('sending');
	e10.terminal.setMode('done');

	var printAfterConfirm = '1';

	var receiptsLocalPrintMode = e10.options.get ('receiptsLocalPrintMode', 'none');
	if (receiptsLocalPrintMode === 'bt' || receiptsLocalPrintMode === 'lan')
		printAfterConfirm = '2';

	var url = '/api/objects/insert/e10doc.core.heads?printAfterConfirm='+printAfterConfirm;

	if (printAfterConfirm == '2')
	{
		var printerType = e10.options.get ('receiptsLocalPrinterType', 'normal');
		url += '&printerType='+printerType;
	}

	e10.server.post (url, e10.terminal.document,
		function (data) {
			e10.terminal.documentInit(true);

			if (printAfterConfirm === '2' && data.posReports)
			{
				e10.terminal.setDoneStatus ('printing');
				e10.terminal.printReceipts(data.posReports);
			}
			else
				e10.terminal.setDoneStatus ('success');
		},
		function (data) {
			e10.terminal.setDoneStatus ('error');
		}
	);
};

e10client.prototype.terminal.printReceipts = function (posReports) {
	e10.terminal.lastPosReports = posReports;

	var receiptsLocalPrintMode = e10.options.get ('receiptsLocalPrintMode', 'none');

	if (receiptsLocalPrintMode === 'bt')
		e10.printbt.print (posReports [0], function (){e10.terminal.setDoneStatus ('success');}, function (){e10.terminal.setDoneStatus ('printError');});
	else if (receiptsLocalPrintMode === 'lan')
		e10.printlan.print (posReports [0], function (){e10.terminal.setDoneStatus ('success');}, function (){e10.terminal.setDoneStatus ('printError');});
};

e10client.prototype.terminal.printRetry = function () {
	if (e10.terminal.lastPosReports) {
		if (e10.terminal.mode !== 'done')
			e10.terminal.setMode('done');

		e10.terminal.setDoneStatus ('printing');
		e10.terminal.printReceipts(e10.terminal.lastPosReports);
	}
};

e10client.prototype.terminal.printExit = function () {
	e10.terminal.setDoneStatus ('success');
};

e10client.prototype.terminal.doPay = function (e) {
	var paymentMethod = e.attr ('data-pay-method');
	var paymentMethodButton = e10.terminal.boxWidget.find ('div.pay-methods>span[data-pay-method="'+paymentMethod+'"]');
	e10.terminal.changePaymentMethod(paymentMethodButton);
	e10.terminal.setMode('pay');
};

e10client.prototype.terminal.queue = function () {
	// TODO: add to queue
	e10.terminal.setDoneStatus ('success');
	e10.terminal.documentInit(true);
};


e10client.prototype.terminal.setDoneStatus = function (status) {
	var headerMsg = e10.terminal.boxDone.find ('>.header');
	var statusMsg = e10.terminal.boxDone.find ('>.done-status');
	var statusButtons = e10.terminal.boxDone.find ('>.done-buttons');
	var printButtons = e10.terminal.boxDone.find ('>.print-buttons');

	if (status === 'sending')
	{
		headerMsg.text ('Účtenka se ukládá');
		statusMsg.text ('vyčkejte prosím, dokud se účtenka nezpracuje');
		statusButtons.hide();
		printButtons.hide();
	}
	else
	if (status === 'printing')
	{
		headerMsg.text ('Tisk účtenky');
		statusMsg.text ('probíhá tisk');
		statusButtons.hide();
		printButtons.hide();
	}
	else
	if (status === 'success')
	{
		statusMsg.text ('hotovo');
		e10.terminal.setMode ('cashbox');
	}
	else
	if (status === 'error')
	{
		headerMsg.text ('Chyba');
		statusMsg.text ('zpracování účtenky bohužel selhalo');
		statusButtons.show();
	}
	else
	if (status === 'printError')
	{
		headerMsg.text ('Tisk selhal');
		statusMsg.html ('<h3>Je tiskárna zapnutá?</h3>');
		statusButtons.hide();
		printButtons.show();
	}
};


e10client.prototype.terminal.symbolClear = function () {
	e10.terminal.symbolForProduct = '';
	e10.terminal.symbolChanged();
};


e10client.prototype.terminal.symbolChanged = function (notFound) {
	var displayValue = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.symbol>li.value');
	var displaySymbol = displayValue.parent();

	if (e10.terminal.symbolForProduct === '')
	{
		displaySymbol.hide();
	}
	else
	{
		displayValue.text (e10.terminal.symbolForProduct);
		displaySymbol.show();
	}
	if (notFound === true)
		displaySymbol.addClass('notFound');
	else
		displaySymbol.removeClass('notFound');
};


e10client.prototype.terminal.symbolSearch = function () {
	// -- search in tiles
	var products = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products');
	var product = products.find ("span[data-ean='"+e10.terminal.symbolForProduct+"']");
	if (product.length)
	{
		e10.terminal.symbolForProduct = '';
		e10.terminal.symbolChanged();
		e10.terminal.addDocumentRow(e10.terminal.itemFromElement(product));
		return;
	}

	// -- search via server
	e10.terminal.searchRemoteItem(e10.terminal.symbolForProduct);
};

e10client.prototype.terminal.searchRemoteItem = function (symbol) {
	var url = '/api/objects/call/e10-cashreg-item/'+encodeURI(symbol);
	e10.server.get (url,
			function (data) {
				if (data.success)
				{
					e10.terminal.symbolForProduct = '';
					e10.terminal.symbolChanged();
					e10.terminal.addDocumentRow(data.item);
				}
				else
				{
					e10.terminal.symbolChanged(true);
				}
			},
			function (data) {
				e10.terminal.symbolChanged(true);
			}
	);
};


e10client.prototype.terminal.setMode = function (mode) {
	if (mode === 'pay')
	{
		e10.terminal.boxDone.hide();
		e10.terminal.boxRows.hide();
		e10.terminal.boxProducts.hide();
		e10.terminal.boxPay.show();
	}
	else
	if (mode === 'cashbox')
	{
		e10.terminal.boxDone.hide();
		e10.terminal.boxPay.hide();
		e10.terminal.boxRows.show();
		e10.terminal.boxProducts.show();
		e10.terminal.setPrintRetryButton();
	}
	else
	if (mode === 'done')
	{
		e10.terminal.boxPay.hide();
		e10.terminal.boxRows.hide();
		e10.terminal.boxProducts.hide();
		e10.terminal.boxDone.show();
	}

	e10.terminal.mode = mode;

	e10.terminal.refreshLayout();
};

e10client.prototype.terminal.setPrintRetryButton = function () {
	if (e10.terminal.lastPosReports !== null)
		$('#terminal-print-retry').show();
	else
		$('#terminal-print-retry').hide();
};

e10client.prototype.terminal.keyDown = function (event, e) {
	if (event.metaKey && event.keyCode === 91)
		return;

	if (e10.terminal.calculatorMode)
	{
		console.log (event.keyCode);
		e10.terminal.calcKeyboard(event, null, event.keyCode);
		event.stopPropagation();
		event.preventDefault();
		return;
	}

	if (event.keyCode === 13)
	{ // enter
		e10.terminal.symbolSearch();
	}
	else
	if (event.keyCode === 8)
	{ // backspace
		e10.terminal.symbolForProduct = e10.terminal.symbolForProduct.slice(0, -1);
		e10.terminal.symbolChanged();
	}
	else
	if (event.keyCode > 32 && event.keyCode < 128)
	{
		var char = String.fromCharCode(event.which);
		e10.terminal.symbolForProduct += char;
		e10.terminal.symbolChanged();
	}

	event.stopPropagation();
	event.preventDefault();

	return false;
};


e10client.prototype.terminal.barcode = function (e, data) {
	if (data.sensorClass == 'barcode')
	{
		var barcode = data.value;
		if (barcode.length == 12)
			barcode = '0' + data.value;

		e10.terminal.symbolForProduct = barcode;
		e10.terminal.symbolChanged();
		e10.terminal.symbolSearch();
	}
};


