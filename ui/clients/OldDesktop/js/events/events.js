e10client.prototype.events = {
};

e10client.prototype.events.init = function () {
};

e10client.prototype.events.columnInputEvent = function (eventType, input) {
	var attrCallName = 'data-clientevent-' + eventType;
	if (!attrCallName)
		return;
	var attrCall = input.attr(attrCallName);
};
