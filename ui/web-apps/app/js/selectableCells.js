function initSelectableCells()
{
	$('body').on ('click', "div.e10-sc-item.selectable", function(event) {
		selectableCellsSelect (event, $(this));
	});

	$('body').on ('click', "div.e10-sc-check-box", function(event) {
		selectableCellsCheckBox (event, $(this));
	});
}

function selectableCellsDoIt(el)
{
	var parentRow = searchParentWithClass(el, 'e10-sc-row');
	if (!parentRow)
		return;

	var containerElement = searchParentWithClass(el, 'e10-sc-container');
	if (!containerElement)
		return;

	var callParams = {};
	callParams['action'] = containerElement.attr('data-web-action');
	elementPrefixedAttributes (containerElement, 'data-web-action-', callParams);
	elementPrefixedAttributes (parentRow, 'data-web-action-', callParams);
	elementPrefixedAttributes (el, 'data-web-action-', callParams);

	callWebAction(callParams, callWebActionSucceeded);
}

function selectableCellsSelect (event, el)
{
	var parentRow = searchParentWithClass(el, 'e10-sc-row');
	if (!parentRow)
		return;

	var wasDisabled = parentRow.find ('.e10-sc-item.selected').hasClass('disabled');

	if (!wasDisabled)
		parentRow.find ('.e10-sc-item.selected').removeClass('selected').addClass('selectable');
	else
		parentRow.find ('.e10-sc-item.selected').removeClass('selected');

	el.addClass('selected').removeClass('selectable');

	if (1)
	{
		var containerElement = searchParentWithClass(el, 'e10-sc-container');
		if (!containerElement)
			return;

		var callParams = {};
		callParams['action'] = containerElement.attr('data-web-action');
		elementPrefixedAttributes (containerElement, 'data-web-action-', callParams);
		elementPrefixedAttributes (parentRow, 'data-web-action-', callParams);
		elementPrefixedAttributes (el, 'data-web-action-', callParams);

		callWebAction(callParams, callWebActionSucceeded);
	}
}

function selectableCellsCheckBox(event, el)
{
	let valueAttr = el.attr('data-check-box-value-attr');
	if (valueAttr === undefined)
		return;

	if (el.hasClass('check-box-off'))
	{
		el.removeClass('check-box-off').addClass('check-box-on');
		el.attr(valueAttr, '1');
		selectableCellsDoIt(el);
	}
	else if (el.hasClass('check-box-on'))
	{
		el.removeClass('check-box-on').addClass('check-box-off');
		el.attr(valueAttr, '0');
		selectableCellsDoIt(el);
	}
}

$(function () {
	initSelectableCells ();
});
