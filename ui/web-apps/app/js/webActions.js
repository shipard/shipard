function webActionReloadElement(element)
{
	var callParams = {};
	callParams['action'] = element.attr('data-web-action');
	elementPrefixedAttributes (element, 'data-web-action-', callParams);

	var elementId = element.attr('id');
	callWebAction(callParams, function (data){webActionReloadElementSuccess(elementId, data)});
}

function webActionReloadElementSuccess(elementId, data)
{
	var element = $('#'+elementId);
	if (element.length)
		element.html(data.html);
}

function webActionClickElement(event, element)
{
	var callParams = {};
	callParams['action'] = element.attr('data-web-action');
	elementPrefixedAttributes (element, 'data-web-action-', callParams);

	callWebAction(callParams, function (data){webActionClickElementSuccess(element, data)});
}

function webActionClickElementSuccess(element, data)
{
	webActionRun(element, data);
}

function webActionRun(element, data)
{
	if (!data.run)
		return;

	for (var i in data.run)
	{
		var runCfg = data.run[i];

		if (runCfg.cmd === 'reloadParentPane')
		{
			var parentPanel = searchParentWithClass (element, 'e10-remote-pane');
			if (parentPanel.length)
				webActionReloadElement(parentPanel);
		}
		else if (runCfg.cmd === 'reloadElementId')
		{
			var elementToReload = $('#'+runCfg.id);
			if (elementToReload.length)
				webActionReloadElement(elementToReload);
		}
	}
}

function initWebActions()
{
	$('body').on ('click', ".e10-web-action-call", function(event) {
		webActionClickElement(event, $(this));
	});
}
