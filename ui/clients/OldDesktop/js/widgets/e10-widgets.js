e10client.prototype.widgets = {

};


e10client.prototype.widgets.init = function () {

};


e10client.prototype.widgets.autoRefresh = function (widgetId) {
	var widget = $('#'+widgetId);
	if (widget.length === 0)
	{
		return;
	}
	e10WidgetAction (0, null, widgetId);
	setTimeout("e10.widgets.autoRefresh('"+widgetId+"')", 60000);
};
