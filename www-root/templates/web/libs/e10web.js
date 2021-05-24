

var CLICK_EVENT = 'click';

function e10jsinit ()
{
	$('body').on (CLICK_EVENT, ".e10-document-trigger", function(event) {
		e10DocumentAction (event, $(this));
	});
    $('body').on (CLICK_EVENT, ".df2-action-trigger", function(event) {
        e10Action (event, $(this));
    });

	initEventer();
}

function initEventer()
{
	var eventMethod = window.addEventListener ? "addEventListener" : "attachEvent";
	var eventer = window[eventMethod];
	var messageEvent = eventMethod === "attachEvent" ? "onmessage" : "message";
	eventer(messageEvent, function (e) {
		if (e.data === "close-iframe-embedd-element")
		{
			if ($('#embeddAppBlocker').hasClass('iframe-app'))
			{
				$('#embeddApp').remove();
				$('#embeddAppBlocker').remove();
			}
			else
				location.reload(true);
		}
	});
}


function e10MakeEmbeddApp (url)
{
	var embeddAppHtml = "<div id='embeddAppBlocker' style='position: fixed; z-index: 31000; background-color: #000; opacity: .5;'></div>" +
											"<iframe id='embeddApp' frameborder='0' src='"+url+"' style='padding: 0; background-color: white; position: absolute;z-index: 32000; box-shadow: 0 5px 5px rgba(0, 0, 0, 0.5); border: 1px solid navy; overflow: hidden;'></iframe>";

	$('body').append (embeddAppHtml);
	var embedAppBlocker = $('#embeddAppBlocker');
	var embedApp = $('#embeddApp');

	var leftOffset = 5;
	var rightOffset = 5;
	var topOffset = 5;
	var bottomOffset = 5;
	embedAppBlocker.css ({left: 0, top: 0, width: window.innerWidth, height: $(window).height()});
	embedApp.css ({left: leftOffset, top: topOffset, width: window.innerWidth - leftOffset - rightOffset - 5, height: $(window).height() - topOffset - bottomOffset});
}

function e10DocumentAction (event, e)
{
	if (!$('body').hasClass('e10-edit-no-iframe') && window.parent && window.parent.document && window.parent.document.body.classList.contains('e10-body-app'))
	{
		e.attr('data-srcObjectType', 'iframe');
		e.attr('data-srcObjectId', window.frameElement.id);
		window.parent.e10DocumentAction(event, e);
		return;
  }

	var table = e.attr('data-table');
	var action = e.attr('data-action');
	var pk = '';
	if (e.attr('data-pk') !== undefined)
		pk = e.attr('data-pk');

	var addParams = '';
	if (e.attr('data-addParams') !== undefined)
		addParams = e.attr('data-addparams');

	var url = httpServerRootPath + 'app/!/e10-document-trigger/' + table + '/' + action + '/' + pk + '?mismatch=1';
	if (addParams !== '')
		url += '&' + addParams;
	e10MakeEmbeddApp (url);
}

function e10Action (event, e)
{
		if (!$('body').hasClass('e10-edit-no-iframe') && window.parent && window.parent.document && window.parent.document.body.classList.contains('e10-body-app'))
		{
				e.attr('data-srcObjectType', 'iframe');
				e.attr('data-srcObjectId', window.frameElement.id);
				window.parent.df2ViewerAction(event, e);
				return;
		}

    alert ("disabled operation");
}

$(function () {e10jsinit ()});
