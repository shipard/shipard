function e10SumTableExpandedCellClick (element, event)
{
	var icon = element.find('>i');
	var tableCell = element.parent();
	var tableRow = tableCell.parent();
	var sumTableContainer = searchObjectAttr(tableRow, 'data-object-class-id');

	if (element.hasClass('expandable'))
	{
		element.removeClass('expandable').removeClass('expanded');
		icon.removeClass('fa-plus-square-o').addClass('fa-minus-square-o');

		var requestParams = {};
		requestParams['expanded-id'] = tableRow.attr('data-exp-this-id');
		requestParams['object-class-id'] = sumTableContainer.attr('data-object-class-id');
		requestParams['query-params'] = {};
		requestParams['level'] = element.attr('data-next-level');
		elementPrefixedAttributes (sumTableContainer, 'data-query-', requestParams['query-params']);
		elementPrefixedAttributes (tableCell, 'data-query-', requestParams['query-params']);
		elementPrefixedAttributes (element, 'data-query-', requestParams['query-params']);

		e10.server.api(requestParams, function(data) {
			$(data['rowsHtmlCode']).insertAfter(tableRow);
		});
	}
	else
	{
		element.addClass('expandable').removeClass('expanded');
		icon.removeClass('fa-minus-square-o').addClass('fa-plus-square-o');

		var selector = ">tr[data-exp-parent-id^='"+tableRow.attr('data-exp-this-id')+"']";
		tableRow.parent().find(selector).each(function () {
			$(this).detach();
		});
	}
}

function e10SumTableSelectRow(element, event)
{
	if (element.hasClass('active')) {
		return;
	}

	element.parent().find('>tr.active').removeClass('active');
	element.addClass('active');

	var input = element.parent().parent().parent().find('>input:first');
	input.val(element.attr('data-selectable-row-id'));
}

