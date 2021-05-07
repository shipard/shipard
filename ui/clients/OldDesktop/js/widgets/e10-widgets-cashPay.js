e10client.prototype.widgets.cashPay = {
	widgetId: '',
	mode: 'pay',
	boxWidget: null,
	boxPay: null,
	boxDone: null,
	roundMethod: 0,
	paymentMethod: 1
};


e10client.prototype.widgets.cashPay.init = function (widgetId) {
	if (e10.widgets.cashPay.widgetId === '') {
		$('body').on(e10.CLICK_EVENT, ".e10-cashpay-action", function (event) {
			event.stopPropagation();
			event.preventDefault();
			e10.widgets.cashPay.action(event, $(this));
		});
	}
	e10.widgets.cashPay.paymentMethod = 1;
	e10.widgets.cashPay.widgetId = widgetId;
	e10.widgets.cashPay.boxWidget = $('#'+widgetId);
	e10.widgets.cashPay.boxPay = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-pay');
	e10.widgets.cashPay.boxDone = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-done');

	e10.widgets.cashPay.refreshLayout();
};


e10client.prototype.widgets.cashPay.refreshLayout = function () {
	var w = $('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content');
	var hh = $(window).height() - $('#e10-page-header').height();
	w.height(hh);
};


e10client.prototype.widgets.cashPay.action = function (event, e) {
	var action = e.attr('data-action');

	if (action === 'change-payment-method')
		return e10.widgets.cashPay.changePaymentMethod(e);
	if (action === 'change-amount')
		return e10.widgets.cashPay.changeAmountRequest();
	if (action === 'cashpay-done')
		return e10.widgets.cashPay.done();
};

e10client.prototype.widgets.cashPay.changePaymentMethod = function (e) {
	var paymentMethod = e.attr ('data-pay-method');
	e10.widgets.cashPay.paymentMethod = parseInt (paymentMethod);

	if (e10.widgets.cashPay.paymentMethod === 2) // card
		e10.widgets.cashPay.roundMethod = 0;
	else
		e10.widgets.cashPay.roundMethod = parseInt(e10.widgets.cashPay.boxWidget.attr ('data-roundmethod'));

	e.parent().find('.active').removeClass ('active');
	e.addClass ('active');
};

e10client.prototype.widgets.cashPay.changeAmountRequest = function (e) {
	e10.form.getNumber (
			{
				title: 'Zadejte částku k úhradě',
				subtitle: 'fff',
				srcElement: e,
				success: e10.widgets.cashPay.changeAmount
			}
	);
};

e10client.prototype.widgets.cashPay.changeAmount = function (e) {
	e10.form.getNumberClose();

	var newAmount = e10.parseFloat(e10.form.gnValue);
	var newAmountStr = e10.nf(newAmount, 0);
	e10.widgets.cashPay.boxWidget.find('#e10-widget-cashpay-display').html(newAmountStr).attr('data-amount', newAmount);
};

e10client.prototype.widgets.cashPay.setMode = function (mode) {
	if (mode === 'pay')
	{
		e10.widgets.cashPay.boxDone.hide();
		e10.widgets.cashPay.boxPay.show();
	}
	else
	if (mode === 'done')
	{
		e10.widgets.cashPay.boxPay.hide();
		e10.widgets.cashPay.boxDone.show();
	}

	e10.widgets.cashPay.mode = mode;
};

e10client.prototype.widgets.cashPay.done = function () {
	e10.widgets.cashPay.setDoneStatus ('sending');
	e10.widgets.cashPay.setMode('done');

	var person = parseInt(e10.widgets.cashPay.boxWidget.attr ('data-person'));
	var amount = e10.widgets.cashPay.boxWidget.find ('#e10-widget-cashpay-display').attr('data-amount');
	var paymentMethod = e10.widgets.cashPay.paymentMethod;

	var url = '/api/objects/call/e10-finance-cashpay/' + person + '/' + amount + '/' + paymentMethod;
	var data = {};
 	e10.server.post (url, data,
			function (data) {
				e10.widgets.cashPay.setDoneStatus ('success');
			},
			function (data) {
				e10.widgets.cashPay.setDoneStatus ('error');
			}
	);
};

e10client.prototype.widgets.cashPay.setDoneStatus = function (status) {
	var statusMsg = e10.widgets.cashPay.boxDone.find ('>.done-status');
	var statusButtons = e10.widgets.cashPay.boxDone.find ('>.done-buttons');

	if (status === 'sending')
	{
		statusMsg.text ('vyčkejte prosím, dokud se účtenka nezpracuje');
		statusButtons.hide();
	}
	else
	if (status === 'success')
	{
		statusMsg.text ('hotovo');
		e10.loadPage('/');
	}
	else
	if (status === 'error')
	{
		statusMsg.text ('zpracování účtenky bohužel selhalo');
		statusButtons.show();
	}
};
