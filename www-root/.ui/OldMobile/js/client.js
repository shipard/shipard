var
googleMapsApi=0;const
g_isMobile=window.matchMedia("(any-pointer:coarse)").matches;function
e10client(){this.appVersion='2.0.1';this.CLICK_EVENT='click';this.g_formId=1;this.openModals=[];this.progressCount=0;this.viewerScroll=0;this.pageType='home';this.disableKeyDown=0;this.app=0;this.clientType='mobile.browser';this.deviceId=null;this.httpServerRoot='';this.userLogin='';this.userSID='';this.userPassword='';this.userPin='';this.standaloneApp=0;this.oldBrowser=0;this.appUrlRoot='/mapp/';this.userInfo=null;function
e10Action(event,e){var
action=e.attr('data-action');if(action==='form')return e10.form.open(e);if(action==='form-close')return e10.form.close(e);if(action==='form-done')return e10.form.done(e);if(action==='app-options')return e10.options.openDialog();if(action==='app-logout')return e10.appLogout();if(action==='app-menu')return e10.options.openAppMenuDialog();if(action==='app-about')return e10.options.appAbout(e);if(action==='app-options-save')return e10.options.saveDialog(e);if(action==='setInputValue'){$('#'+e.attr('data-inputid')).val(e.text());return 0;}if(action==='detail-add-photo')return e10.camera.takePhoto(e);if(action==='detail-add-file')return e10.camera.takeFile(e);if(action==='viewer-search')return e10.viewer.search(e);if(action==='modal-close')return e10.closeModal();if(action==='app-fs-plus')return e10.options.fontSize(e,1);if(action==='app-fs-minus')return e10.options.fontSize(e,-1);if(action==='app-fs-reset')return e10.options.fontSize(e,0);if(action==='workspace-login')return e10.workspaceLogin(e);if(action==='inline-action'){e10InlineAction(e);return;}}function
e10WidgetAction(event,e,widgetId){if(e&&e.attr('data-call-function')!==undefined){e10.executeFunctionByName(e.attr('data-call-function'),e);return;}var
widget=null;if(widgetId===undefined)widget=e10.searchObjectAttr(e,'data-widget-class');else
widget=$('#'+widgetId);var
actionType='reload';if(e!==null)actionType=e.attr("data-action");var
postData={};if(widget.attr('data-collect')!==null){var
fn=window[widget.attr('data-collect')];if(typeof
fn==="function")postData=fn(widget);}var
fullCode=0;if((e&&e.parent().hasClass('e10-wf-tabs'))||(widget.hasClass('e10-widget-viewer')))fullCode=1;else
if(!event&&!e)fullCode=1;if(e&&e.parent().hasClass('e10-wf-tabs')){var
tabList=e.parent();var
inputId=(tabList.hasClass('right'))?'e10-widget-topTab-value-right':'e10-widget-topTab-value';$('#'+inputId).val(e.attr('data-tabid'));}var
className=widget.attr("data-widget-class");var
widgetParams=widget.attr("data-widget-params");var
oldWidgetId=widget.attr('id');var
urlPath="/api/widget/"+className+"/html?fullCode="+fullCode+"&widgetAction="+actionType+'&widgetId='+oldWidgetId;if(widgetParams!='')urlPath+="&"+widgetParams;var
params=e10.collectFormData(widget);if(params!='')urlPath+='&'+params;e10.server.post(urlPath,postData,function(data){widget.find("*:first").remove();widget.html(data.object.mainCode);e10.widgetTabsInit();});}function
e10InlineAction(e){if(e.attr('data-object-class-id')===undefined)return;var
requestParams={};requestParams['object-class-id']=e.attr('data-object-class-id');requestParams['action-type']=e.attr('data-action-type');elementPrefixedAttributes(e,'data-action-param-',requestParams);if(e.attr('data-pk')!==undefined)requestParams['pk']=e.attr('data-pk');e10.server.api(requestParams,function(data){if(e.parent().hasClass('btn-group')){e.parent().find('>button.active').removeClass('active');e.addClass('active');}});}function
e10StaticTab(e,event){e.parent().find('li.active').removeClass('active');e.addClass('active');e.parent().parent().find('div.e10-static-tab-content>div.active').removeClass('active');$('#'+e.data('content-id')).addClass('active');}function
e10FormsTabClick(e){var
pageid=e.attr("id");if(pageid){var
activeTab=e.parent().find("li.active").first();activeTab.removeClass("active");$("#"+activeTab.attr('id')+'-tc').hide();e.addClass("active");$("#"+pageid+'-tc').show();if(e.attr('data-inputelement')){$('#'+e.attr('data-inputelement')+' input[name='+e.attr('data-inputname')+']').val(e.attr('data-inputvalue'));}return true;}return false;}function
e10ReportChangeParam(e){var
param=e10.searchObjectAttr(e,'data-paramid');if(e.attr('data-value')){var
value=e.attr('data-value');param.find('input').val(value).trigger('change');param.find('.active').removeClass('active');e.addClass('active');if(!e.is('BUTTON')){var
title=e.attr('data-title');param.find('>button>span.v').text(title);}}else{var
value=e.parent().attr('data-value');var
title=e.parent().attr('data-title');param.find('input').val(value).trigger('change');param.find('>button>span.v').text(title);param.find('.dropdown-menu .active').removeClass('active');e.parent().addClass('active');}}function
e10SensorToggle(event,e){var
sensorId=e.attr('data-sensorid');var
serveridx=parseInt(e.attr('data-serveridx'));var
url=webSocketServers[serveridx].postUrl;url=url+'?callback=?&data=';if(e.hasClass('e10-sensor-on')){var
msg={'deviceId':e10.deviceId,'sensorId':sensorId,'cmd':'unlockSensor'};url+=encodeURI(JSON.stringify(msg));$.getJSON(url,function(data){});}else{var
msg={'deviceId':e10.deviceId,'sensorId':sensorId,'cmd':'lockSensor'};url+=encodeURI(JSON.stringify(msg));$.getJSON(url,function(data){});}e.toggleClass('e10-sensor-on');}this.e10LoadRemoteWidget=function(id){var
w=$('#'+id);var
widgetClassId=w.attr('data-widget-class');var
url="/api/widget/"+widgetClassId;if(w.attr('data-widget-params'))url+='?'+w.attr('data-widget-params');e10.server.get(url,function(data){w.html(data.object.mainCode);});};function
e10viewerOpenDocument(e){var
pk=e.attr('data-pk');var
path=httpOriginPath+'/'+pk;e10.loadPage(path);}this.searchParentAttr=function(e,attr){var
p=e;while(p.length){var
attrValue=p.attr(attr);if(p.attr(attr))return p.attr(attr);p=p.parent();if(!p.length)break;}return null;};this.searchObjectAttr=function(e,attr){var
p=e;while(p.length){if(p.attr(attr))return p;p=p.parent();if(!p.length)break;}return null;};this.e10AttWidgetFileSelected=function(input){var
infoPanel=$(input).parent().find('div.e10-att-input-files');var
info='<table>';for(var
i=0;i<input.files.length;i++){var
file=input.files[i];var
fileSize=0;if(file.size>1024*1024)fileSize=(Math.round(file.size*100/(1024*1024))/100).toString()+'MB';else
fileSize=(Math.round(file.size*100/1024)/100).toString()+'KB';info+='<tr>'+'<td>'+file.name+"</td><td class='number'>"+fileSize+'</td><td>-</td></tr>';}info+='</table>';infoPanel.html(info);};this.e10AttWidgetUploadFile=function(button){var
table=e10.searchParentAttr(button,'data-table');if(table===null)table='_tmp';var
pk=e10.searchParentAttr(button,'data-pk');var
infoPanel=button.parent().parent().find('div.e10-att-input-files');var
input=button.parent().parent().find('input:first').get(0);infoPanel.attr('data-fip',input.files.length);for(var
i=0;i<input.files.length;i++){var
file=input.files[i];var
url=e10.httpServerRoot+"/upload/e10.base.attachments/"+table+'/'+pk+'/'+file.name;e10.e10AttWidgetUploadOneFile(url,file,infoPanel,i);}};this.e10AttWidgetUploadOneFile=function(url,file,infoPanel,idx){var
xhr=new
XMLHttpRequest();xhr.upload.addEventListener("progress",function(e){e10.e10AttWidgetUploadProgress(e,infoPanel,idx);},false);xhr.onload=function(e){e10.e10AttWidgetUploadDone(e,infoPanel,idx);};xhr.open("POST",url);xhr.setRequestHeader("Cache-Control","no-cache");xhr.setRequestHeader("Content-Type","application/octet-stream");xhr.send(file);};this.e10AttWidgetUploadDone=function(e,infoPanel,idx){var
cell=infoPanel.find('table tr:eq('+idx+') td:eq(2)');cell.css({"background-color":"green"}).attr('data-ufn',e.target.responseText);var
fip=parseInt(infoPanel.attr('data-fip'))-1;infoPanel.attr('data-fip',fip);if(fip==0){}var
table=e10.searchParentAttr(infoPanel,'data-table');if(table===null)table='_tmp';var
pk=e10.searchParentAttr(infoPanel,'data-pk');e10.reloadDetail(table,pk);};this.e10AttWidgetUploadProgress=function(e,infoPanel,idx){if(e.lengthComputable){var
percentage=Math.round((e.loaded*100)/e.total);var
cell=infoPanel.find('table tr:eq('+idx+') td:eq(2)');cell.text(percentage+' % ');}};this.init=function(){$('body').on(e10.CLICK_EVENT,".link",function(event){event.stopPropagation();event.preventDefault();e10.doLink($(this));});$('body').on(e10.CLICK_EVENT,"ul.e10-viewer-list >li.r",function(event){event.stopPropagation();event.preventDefault();e10.openDocument($(this));});$('body').on(e10.CLICK_EVENT,".e10-document-trigger",function(event){event.stopPropagation();event.preventDefault();e10.openDocument($(this));});$('body').on(e10.CLICK_EVENT,"div.e10-page-end",function(event){$('body').scrollTop(0);});$('body').on(e10.CLICK_EVENT,".e10-trigger-action",function(event){event.stopPropagation();event.preventDefault();e10Action(event,$(this));});$('body').on(e10.CLICK_EVENT,".df2-action-trigger",function(event){event.stopPropagation();event.preventDefault();e10Action(event,$(this));});$('body').on(e10.CLICK_EVENT,".e10-trigger-gn",function(event){event.stopPropagation();event.preventDefault();e10.form.getNumberAction(event,$(this));});$('body').on(e10.CLICK_EVENT,".e10-trigger-cv",function(event){event.stopPropagation();event.preventDefault();e10.form.comboViewerAction(event,$(this));});$('body').on('search input',"input.e10-inc-search",function(event){e10.viewer.incSearch($(this),event);});$('body').on(e10.CLICK_EVENT,".e10-widget-trigger, .df2-widget-trigger",function(event){e10WidgetAction(event,$(this));});$("body").on('change',"div.e10-widget-pane input, #e10dashboardWidget select",function(event){e10WidgetAction(event,$(this));});$("body").on(e10.CLICK_EVENT,"div.e10-param .dropdown-menu a",function(event){e10ReportChangeParam($(this));});$("body").on(e10.CLICK_EVENT,"div.e10-param .e10-param-btn",function(event){e10ReportChangeParam($(this));});$('body').on(e10.CLICK_EVENT,"li.e10-sensor",function(event){e10SensorToggle(event,$(this));});$('body').on(e10.CLICK_EVENT,"ul.e10-viewer-tabs>li",function(event){event.stopPropagation();event.preventDefault();e10.viewer.bottomTabsClick($(this));});$("body").on(e10.CLICK_EVENT,"ul.e10-widget-tabs>li",function(event){e10FormsTabClick($(this));});$("body").on(e10.CLICK_EVENT,"span.e10-sum-table-exp-icon",function(event){e10SumTableExpandedCellClick($(this),event);});$("body").on(e10.CLICK_EVENT,"li.e10-static-tab",function(event){e10StaticTab($(this),event);});$("body").on('keydown',function(event){e10.keyDown(event,$(this));});$(window).resize(e10.screenResize);this.pageTabsInit();this.widgetTabsInit();}};e10client.prototype.pageTabsInit=function(hideActive){if(hideActive!==undefined){var
activeTab=$('#e10-page-tabs>li.active');if(activeTab.length!==0){var
activeTabId=activeTab.attr('id');$('#'+activeTabId+'-c').hide();activeTab.removeClass('active');}}var
tabs=$('#e10-page-tabs');if(tabs.length===0){$('body').removeClass('pageTabs');return;}$('body').addClass('pageTabs');$('#e10-page-tabs').on(e10.CLICK_EVENT,"li",function(event){event.stopPropagation();event.preventDefault();e10.pageTabsClick($(this));});var
activeTabId='';if(window.location.hash!='')activeTabId='e10-page-tab-'+window.location.hash.substr(1);else{var
activeTab=$('#e10-page-tabs>li.active');if(activeTab.length===0)activeTab=$('#e10-page-tabs>li:first');activeTabId=activeTab.attr('id');}$('#'+activeTabId).addClass('active');$('#'+activeTabId+'-c').show();};e10client.prototype.widgetTabsInit=function(){var
w=$('#e10-page-body');w.find('ul.e10-widget-tabs').each(function(){var
id=$(this).attr('id');$("#"+id+" >li").each(function(){var
tabId=$(this).attr("id");var
tabContentId=tabId+'-tc';if($(this).hasClass('active'))$('#'+tabContentId).show();else
$('#'+tabContentId).hide();});});};e10client.prototype.pageTabsClick=function(e){var
activeTab=$('#e10-page-tabs>li.active');var
activeTabId=activeTab.attr('id');$('#'+activeTabId+'-c').hide();activeTab.removeClass('active');var
newTabId=e.attr('id');$('#'+newTabId+'-c').show();e.addClass('active');window.location.hash='#'+newTabId.substr(newTabId.lastIndexOf('-')+1);};e10client.prototype.loadPage=function(dataPath,successFunction,errorFunction){var
url=e10.appUrlRoot;if(dataPath[0]==='#')url+='?app=1';else
url+=dataPath+'?app=1';if(typeof
g_initDataPath!=='undefined'&&window['g_UserInfo']!==undefined&&g_initDataPath!=='')url+='&embeddMode=1';if(e10.standaloneApp)url+='&standaloneApp='+e10.standaloneApp;e10.setProgress(1);e10.server.get(url,function(data){$("#e10-page-body *").off();$("#e10-page-body").empty();$("#e10-page-body").html(data.object.htmlCode);$("#e10-page-body").attr('data-reload-path',dataPath);$("#e10-page-body").attr('data-page-type',data.object.pageInfo.pageType);e10.pageType=data.object.pageInfo.pageType;window.scrollTo(0,0);e10.userSID=data.object.pageInfo.sessionId;httpOriginPath=data.object.pageInfo.httpOriginPath;g_uiTheme=data.object.pageInfo.guiTheme;if(window['webSocketServers']===undefined)webSocketServers=data.object.pageInfo.wss;if(data.object.pageInfo.viewerScroll){window.onscroll=function(ev){e10.viewer.loadNextData(ev)};e10.viewerScroll=1;}else{e10.viewerScroll=0;window.onscroll=null;}if(data.object.pageInfo.userInfo)e10.userInfo=data.object.pageInfo.userInfo;if(successFunction!==undefined)successFunction();e10.pageTabsInit();e10.refreshLayout();e10.setProgress(0);},errorFunction);};e10client.prototype.refreshLayout=function(){var
hh=0;var
pageHeader=$('#e10-page-header');if(pageHeader.length)hh=pageHeader.outerHeight();var
fh=0;var
pageFooter=$('#e10-page-footer');if(pageFooter.length)fh=pageFooter.outerHeight();$('body').css('margin-top',hh);$('body').css('margin-bottom',fh);e10.widgetTabsInit();};e10client.prototype.doLink=function(e){e.css("background-color","rgba(255,0,0,.3)");if(e.attr('data-path')==='#')return;var
url='';if(e.attr('data-path')!==undefined){this.loadPage(e.attr('data-path'));return;}else
if(e.attr('data-file-url')!==undefined){url=e.attr('data-file-url');var
mimeType=e.attr('data-mime-type');e10.openFile(url,mimeType);return;}else
if(e.attr('data-url')!==undefined){url=e.attr('data-url');}else
if(e.attr('data-oid')!==undefined){url=e10.httpServerRoot+e10.appUrlRoot+e.attr('data-oid');url+='?op='+httpOriginPath;}window.location.href=url;};e10client.prototype.openFile=function(url,mimeType){window.location.href=url;};e10client.prototype.setProgress=function(progress){if(progress)e10.progressCount++;else
e10.progressCount--;if(e10.progressCount)$('#e10-status-progress').addClass('active');else
$('#e10-status-progress').removeClass('active');};e10client.prototype.collectFormData=function(form,isNextBlock){var
frmData='';if(isNextBlock===undefined)frmData="frmId=myForm&";else
frmData='&';var
data={};var
formElements=form.find('input');for(var
i=0;i<formElements.length;i++){var
element=formElements[i];var
type=element.type;if(type=="checkbox"||type=="radio"){if(element.checked)data[element.name]=element.value;continue;}data[element.name]=element.value;}formElements=form.find('select');for(var
i=0;i<formElements.length;i++){var
element=formElements[i];data[element.name]=element.value;}formElements=form.find('textarea');for(i=0;i<formElements.length;i++){var
element=formElements[i];data[element.name]=element.value;}frmData+=$.param(data);return frmData;};e10client.prototype.initPage=function(){};e10client.prototype.escapeHtml=function(str){var
div=document.createElement('div');div.appendChild(document.createTextNode(str));return div.innerHTML;};e10client.prototype.openDocument=function(e){var
pk=e.attr('data-pk');var
action=e10.searchParentAttr(e,'data-rowaction');if(action){var
actionClass=e10.searchParentAttr(e,'data-rowactionclass');if(action==='call'){e10.executeFunctionByName(actionClass,e);return;}e10.setProgress(1);var
url=e10.appUrlRoot;url+=action;if(actionClass)url+='/'+actionClass;url+='/'+pk;url+='?app=1';e10.openModal();e10.server.get(url,function(data){$("#e10-page-body").html(data.object.htmlCode);e10.refreshLayout();e10.setProgress(0);});return;}e10.setProgress(1);e10.openModal();var
table=e10.searchParentAttr(e,'data-table');var
url='/api/d/'+table+'/'+pk;e10.g_formId++;var
newElementId="docPopup"+e10.g_formId;e10.server.get(url,function(data){e10.createDocument(data,newElementId);e10.setProgress(0);});};e10client.prototype.reloadDetail=function(table,pk){e10.closeModal();e10.setProgress(1);e10.openModal();var
url='/api/d/'+table+'/'+pk;e10.g_formId++;var
newElementId="docPopup"+e10.g_formId;e10.server.get(url,function(data){e10.createDocument(data,newElementId);e10.setProgress(0);});};e10client.prototype.createDocument=function(data,id){var
c='';c+="<div id='e10-page-header' class='e10mui pageHeader docHeader'></div>";c+="<div class='e10-doc' id='"+id+"' data-object='document'>";c+="</div>";$("#e10-page-body").html(c);var
tc="";tc+="<span class='lmb e10-trigger-action' data-action='modal-close' id='e10-back-button'><i class='fa fa-times'></i></span>"+"<div class='pageTitle'><h1>"+data.codeTitle+"</h1>";if(data.codeSubTitle!=='')tc+="<span class='subTitle'>"+data.codeSubTitle+"</span>";tc+="</div>";if(e10.app){tc+="<ul class='rb'>";tc+="<li class='e10-trigger-action' data-action='detail-add-photo' data-table='"+data.table+"' data-pk='"+data.pk+"'><i class='fa fa-camera'></i></li>";tc+="</ul>";}var
toolbar=$('#e10-page-header');var
form=$('#'+id);toolbar.html(tc);form.html(data.codeBody+data.codeFooter);e10.refreshLayout();if(window.MSApp)$('img.e10-img-loading').each(function(){e10.imgLoaded($(this))});return 0;};e10client.prototype.openModal=function(){var
modalInfo={scrollTop:$("body").scrollTop(),htmlCode:$("#e10-page-body").html(),viewerScroll:e10.viewerScroll};$("#e10-page-body *").off();$("#e10-page-body").empty();e10.viewerScroll=0;window.onscroll=null;e10.openModals.push(modalInfo);};e10client.prototype.closeModal=function(){if(e10.openModals.length===0)return;var
modalInfo=e10.openModals.pop();$("#e10-page-body *").off();$("#e10-page-body").empty();$("#e10-page-body").html(modalInfo.htmlCode);e10.viewerScroll=modalInfo.viewerScroll;if(e10.viewerScroll){window.onscroll=function(ev){e10.viewer.loadNextData(ev)};}else
window.onscroll=null;e10.refreshLayout();$("body").scrollTop(modalInfo.scrollTop);};e10client.prototype.imgLoaded=function(e){e.removeClass('e10-img-loading').prev().text('');};e10client.prototype.b64DecodeUnicode=function(str){return decodeURIComponent(Array.prototype.map.call(atob(str),function(c){return'%'+('00'+c.charCodeAt(0).toString(16)).slice(-2);}).join(''));};e10client.prototype.nf=function(n,c){var
c=isNaN(c=Math.abs(c))?2:c,d='.',t=' ',s=n<0?"-":"",i=parseInt(n=Math.abs(+n||0).toFixed(c))+"",j=(j=i.length)>3?j%3:0;return s+(j?i.substr(0,j)+t:"")+i.substr(j).replace(/(\d{3})(?=\d)/g,"$1"+t)+(c?d+Math.abs(n-i).toFixed(c).slice(2):"");};e10client.prototype.parseFloat=function(n){var
str=n.replace(',','.');return parseFloat(str);};e10client.prototype.round=function(value,decimals){return Number(Math.round(value+'e'+decimals)+'e-'+decimals);};e10client.prototype.isPortrait=function(){return window.innerHeight>window.innerWidth;};e10client.prototype.screenResize=function(){if(e10.pageType==='terminal')e10.terminal.refreshLayout();};e10client.prototype.keyDown=function(event,e){if(e10.pageType==='terminal'&&!e10.disableKeyDown)return e10.terminal.keyDown(event,e);var
element=event.target.nodeName.toLowerCase();if(element!='input'&&element!='textarea'){if(event.keyCode===8){event.stopPropagation();event.preventDefault();return false;}}};e10client.prototype.workspaceLogin=function(e){e10.form.getNumber({title:'Zadejte přístupový kód',srcElement:e,success:e10.workspaceLoginDoIt});};e10client.prototype.workspaceLoginDoIt=function(){if(e10.app){localStorage.setItem("userLogin",e10.form.gnOptions.srcElement.attr('data-login'));localStorage.setItem("userPin",e10.form.gnValue);var
terminalURL=e10.options.get('terminalURL','');var
url=(terminalURL.substr(0,6)==='https:')?terminalURL:'https://'+terminalURL;document.location="datasource.html?ds="+encodeURI(url)+'&standalone=0';return;}$('#e10-login-user').val(e10.form.gnOptions.srcElement.attr('data-login'));$('#e10-login-pin').val(e10.form.gnValue);document.forms['e10-mui-login-form'].submit();};e10client.prototype.executeFunctionByName=function(functionName){var
context=window;var
args=[].slice.call(arguments).splice(1);var
namespaces=functionName.split(".");var
func=namespaces.pop();for(var
i=0;i<namespaces.length;i++){context=context[namespaces[i]];}return context[func].apply(this,args);};function
e10nf(n,c){var
c=isNaN(c=Math.abs(c))?0:c,d=',',t=' ',s=n<0?"-":"",i=parseInt(n=Math.abs(+n||0).toFixed(c))+"",j=(j=i.length)>3?j%3:0;return s+(j?i.substr(0,j)+t:"")+i.substr(j).replace(/(\d{3})(?=\d)/g,"$1"+t)+(c?d+Math.abs(n-i).toFixed(c).slice(2):"");}function
e10sleep(milliseconds){var
start=new
Date().getTime();for(var
i=0;i<1e7;i++){if((new
Date().getTime()-start)>milliseconds){break;}}}function
e10WidgetInit(id){var
w=$('#'+id);w.find('div.e10-widget-iframe').each(function(){$(this).parent().parent().height($(this).parent().parent().parent().height());$(this).height($(this).parent().parent().parent().height());});w.find('div.e10-remote-widget').each(function(){var
id=$(this).attr('id');e10.e10LoadRemoteWidget(id);});}function
searchObjectAttr(e,attr){var
p=e;while(p.length){if(p.attr(attr))return p;p=p.parent();if(!p.length)break;}return null;}function
elementPrefixedAttributes(e,prefix,data){var
iel=e.get(0);for(var
i=0,attrs=iel.attributes,l=attrs.length;i<l;i++){var
attrName=attrs.item(i).nodeName;if(attrName.substring(0,prefix.length)!==prefix)continue;var
attrNameShort=attrName.substring(prefix.length);var
val=attrs.item(i).nodeValue;data[attrNameShort]=val;}}e10client.prototype.viewer={};e10client.prototype.viewer.refresh=function(viewer){e10.viewer.appendRows(viewer,0);};e10client.prototype.viewer.loadNextData=function(event){var
viewerId=$('body div.e10-viewer').attr('id');var
viewer=$('#'+viewerId);if(viewer.attr('data-loadonprogress')&&viewer.attr('data-loadonprogress')!=0)return;var
scrollTop=(document.documentElement&&document.documentElement.scrollTop)||document.body.scrollTop;var
scrolledToBottom=(scrollTop+window.innerHeight)>=document.body.scrollHeight-300;if(scrolledToBottom){viewer.attr('data-loadonprogress',1);e10.viewer.appendRows(viewer,1);}};e10client.prototype.viewer.loadNextDataCombo=function(event){var
viewerForm=$('#'+e10.form.cvId);var
viewerContent=$('#e10-form-combo-viewer-content');var
viewer=viewerContent.find('>div.e10-viewer');var
viewerList=viewer.find('>ul.e10-viewer-list');if(viewer.attr('data-loadonprogress')&&viewer.attr('data-loadonprogress')!=0)return;var
heightToEnd=viewerList[0].scrollHeight-(viewerList.scrollTop()+viewerList.height());if(heightToEnd<=150){viewer.attr('data-loadonprogress',1);e10.viewer.appendRows(viewer,1);}};e10client.prototype.viewer.appendRows=function(viewer,appendLines){var
tableName=viewer.attr("data-table");if(!tableName)return;var
viewerOptions=viewer.attr("data-viewer-view-id");var
urlPath='';var
rowsPageNumber=0;if(appendLines)rowsPageNumber=parseInt(viewer.attr('data-rowspagenumber'))+1;else
viewer.attr('data-rowspagenumber',0);urlPath='/api/viewer/'+tableName+'/'+viewerOptions+'/html'+"?mobile=1&rowsPageNumber="+rowsPageNumber;var
queryParams=viewer.attr("data-queryparams");if(queryParams)urlPath+='&'+queryParams;e10.viewer.fillRows(viewer,urlPath,appendLines);};e10client.prototype.viewer.fillRows=function(viewer,url,appendLines){var
viewerId=viewer.attr('id');viewer.attr('data-loadonprogress',1);e10.setProgress(1);var
formPostData=e10.collectFormData(viewer);e10.server.postForm(url,formPostData,function(data){var
viewerLines=$('#'+viewerId+'Items');var
rowElement='li';if(appendLines){if(rowElement==='tr'){viewerLines.find(">table tbody tr:last-child").detach();viewerLines.find('>table tbody').append(data.object.htmlItems);viewerLines.find('>table.dataGrid.main').floatThead('reflow');}else{viewerLines.find('>'+rowElement+":last-child").detach();var
currCnt=viewerLines.find(rowElement).length;viewerLines.append(data.object.htmlItems);}}else{if(rowElement==='tr'){viewerLines.find('>table tbody').html(data.object.htmlItems);viewerLines.find('>table.dataGrid.main').floatThead('reflow');}else{viewerLines.empty();viewerLines.html(data.object.htmlItems);}}viewer.attr('data-rowspagenumber',data.object.rowsPageNumber);viewer.attr('data-loadonprogress',0);e10.setProgress(0);});};var
g_incSearchTimer=0;e10client.prototype.viewer.incSearch=function(input,event){var
viewer=null;if(input.attr('data-combo')){var
viewerForm=$('#'+e10.form.cvId);var
viewerContent=$('#e10-form-combo-viewer-content');viewer=viewerContent.find('>div.e10-viewer');}else
viewer=input.parent().parent();var
thisVal=input.val();if(input.attr('data-lastvalue')&&input.attr('data-lastvalue')==thisVal)return;if(event&&event.type=='keyup'){if(!input.attr('data-lastvalue')&&thisVal=='')return;if(event.keyCode==38||event.keyCode==40||event.keyCode==13)return;}if(viewer.attr('data-loadonprogress')&&viewer.attr('data-loadonprogress')!=0){g_incSearchTimer=setTimeout(function(){e10.viewer.incSearch(input)},100);return;}if(g_incSearchTimer){clearTimeout(g_incSearchTimer);g_incSearchTimer=0;}input.attr('data-lastvalue',thisVal);viewer.attr('data-loadonprogress',1);e10.viewer.refresh(viewer);};e10client.prototype.viewer.search=function(e){var
viewer=$('#e10-page-body>div.e10-viewer');var
searchBox=viewer.find('>div.e10-viewer-search');var
input=searchBox.find('>input.e10-inc-search');if(searchBox.hasClass('off')){e.addClass('active');var
hh=$('#e10-page-header').outerHeight()|0;searchBox.removeClass('off').addClass('on');searchBox.css('top',hh+'px');input.focus();$('body').css('margin-top',hh+searchBox.outerHeight()|0);}else{e.removeClass('active');input.val('');searchBox.removeClass('on').addClass('off');$('body').css('margin-top',$('#e10-page-header').height());}};e10client.prototype.viewer.bottomTabsClick=function(e){var
tabs=e.parent();var
activeTab=tabs.find('>li.active');activeTab.removeClass('active');e.addClass('active');var
viewer=$('#e10-page-body>div.e10-viewer');var
input=viewer.find(">div.e10-viewer-search>input[name=bottomTab]");input.val(e.attr('data-id'));e10.viewer.refresh(viewer);};e10client.prototype.form={gnOptions:null,gnValue:'',gnDisplay:null,gnId:'',cvId:'',cvValue:0,cvOptions:null,};e10client.prototype.form.create=function(data,id){var
c="<div class='e10-form' id='"+id+"' data-object='form' data-classid='"+data.classId+"'>";c+="<div class='e10-form-content'>";c+="<div class='toolbar'></div>";c+="<div class='content'>";c+="<form onsubmit='return e10.form.submit(this);' action='javascript:void(0);'></form>";c+="</div>";c+="</div>";c+="</div>";$('body').append(c);var
form=$('#'+id);if(data.formData.recData.ndx!==undefined)form.attr('data-pk',data.formData.recData.ndx);else
form.attr('data-pk','0');form.css('width','100%');form.css('height','100%');form.css('overflow-y','auto');var
content=form.find('div.content>form');var
toolbar=form.find('div.toolbar');toolbar.html(data.toolbarCode);content.html(data.contentCode+"<input type='submit' value='Submit Button' id='sbmt' style='display: none;' />");e10.form.setData(form,data.formData);return 0;};e10client.prototype.form.close=function(e){var
form=e10.searchObjectAttr(e,'data-object');form.detach();return 0;};e10client.prototype.form.open=function(e){e10.setProgress(1);var
classId=e.attr("data-classid");e10.g_formId++;var
newElementId="mainEditF"+e10.g_formId;var
url="/api/f/"+classId+"?newFormId="+newElementId;if(e.attr('data-addparams')!==undefined)url+='&'+e.attr('data-addparams');if(e.attr('data-pict'))url+='&addPicture='+e.attr('data-pict');if(e.attr('data-pict-thumb'))url+='&addPictureThumbnail='+e.attr('data-pict-thumb');var
postData={};postData.operation=e.attr('data-operation');postData.pk=e.attr('data-pk');e10.server.post(url,postData,function(data){e10.form.create(data,newElementId);e10.setProgress(0);});};e10client.prototype.form.done=function(e){var
form=e10.searchObjectAttr(e,'data-object');var
htmlForm=form.find('>div.e10-form-content>div.content>form');$('#sbmt').click();if(!htmlForm[0].checkValidity())return;e10.setProgress(1);var
classId=form.attr('data-classid');var
postData={};postData.operation='done';postData.pk=form.attr('data-pk');postData.formData=e10.form.getData(form);var
url="/api/f/"+classId;e10.server.post(url,postData,function(data){e10.form.uploadFiles(form,data.table,data.pk);form.detach();e10.setProgress(0);e10.loadPage('');});return 1;};e10client.prototype.form.submit=function(form){return false;};e10client.prototype.form.getData=function(form){var
newData={};var
usedInputs=new
Array();var
thisInputValue=null;var
mainFid=form.attr('id');var
formElements=form.find('input, select, textarea');for(var
i=0;i<formElements.length;i++){var
thisInput=$(formElements[i]);if(!thisInput.attr("name")&&!thisInput.attr("data-name"))continue;var
thisInputName=thisInput.attr('name');if(thisInput.attr("data-name"))thisInputName=thisInput.attr('data-name');var
dataMainPart='recData';var
dataSubPart=null;var
dataRowPart=null;var
dataColumnPart=null;var
nameParts=thisInputName.split('.');if(nameParts.length==1)dataColumnPart=thisInputName;else
if(nameParts.length==2){dataMainPart=nameParts[0];dataColumnPart=nameParts[1];}else
if(nameParts.length==3){dataMainPart=nameParts[0];dataColumnPart=nameParts[1];dataRowPart=nameParts[2];}else
if(nameParts.length==4){dataMainPart=nameParts[0];dataSubPart=nameParts[1];dataRowPart=nameParts[2];dataColumnPart=nameParts[3];}thisInputValue=null;if(thisInput.hasClass("e10-inputLogical")){if(thisInput.attr('value')=='Y'){if(thisInput.is(':checked'))thisInputValue='Y';else
thisInputValue='N';}else
if(thisInput.attr('value')!='1'){if(thisInput.is(':checked'))thisInputValue=thisInput.attr('value');else
thisInputValue='0';}else{if(thisInput.is(':checked'))thisInputValue=1;else
thisInputValue=0;}}else
if(thisInput.hasClass("e10-inputRadio")){if(thisInput.is(':checked'))thisInputValue=thisInput.attr('value');else
continue;}else
if(thisInput.hasClass("e10-inputDate")){if(thisInput.val()!=''){var
dp=thisInput.val().split(".");thisInputValue=dp[2]+"-"+dp[1]+"-"+dp[0];if(thisInput.hasClass("e10-inputDateTime")){var
timeInput=$('#'+thisInput.attr('id')+'_Time');thisInputValue+=' '+timeInput.val();}}else
thisInputValue='0000-00-00';}else
if(thisInput.hasClass("e10-inputMoney")||thisInput.hasClass("e10-inputDouble")||thisInput.hasClass("e10-inputInt")){if(thisInput.val()!=''){var
sv=thisInput.val();sv=sv.replace(",",".").replace(/\s/g,'');thisInputValue=parseFloat(sv);}else
thisInputValue=0.0;}else
if(thisInput.hasClass("e10-inputEnum")){if(thisInput.hasClass("e10-inputEnumMultiple")){if(thisInput.val())thisInputValue=thisInput.val().join('.');else
thisInputValue='';}else
thisInputValue=thisInput.val();}else
if(thisInput.hasClass("e10-inputRefId")&&!thisInput.hasClass("e10-inputRefIdDirty")){}else
if(thisInput.hasClass("e10-inputDocLink")){thisInputValue={};var
listItems=thisInput.find('ul li');for(var
ii=0;ii<listItems.length;ii++){var
li=$(listItems[ii]);if(!thisInputValue[li.attr('data-table')])thisInputValue[li.attr('data-table')]=[li.attr('data-pk')];else
thisInputValue[li.attr('data-table')].push(li.attr('data-pk'));}}else
if(thisInput.hasClass("e10-inputCode")){thisInputValue=thisInput.data('cm').getValue();}else
thisInputValue=thisInput.val();if(thisInputValue===null)continue;if(!newData[dataMainPart])newData[dataMainPart]={};if(dataMainPart=='recData'){newData[dataMainPart][dataColumnPart]=thisInputValue;usedInputs.push(dataColumnPart);}else{if(nameParts.length==2){if(!newData[dataMainPart])newData[dataMainPart]={};newData[dataMainPart][dataColumnPart]=thisInputValue;}else
if(nameParts.length==3){if(!newData[dataMainPart][dataColumnPart])newData[dataMainPart][dataColumnPart]={};newData[dataMainPart][dataColumnPart][dataRowPart]=thisInputValue;}else{if(!newData[dataMainPart][dataSubPart])newData[dataMainPart][dataSubPart]={};if(!newData[dataMainPart][dataSubPart][dataRowPart])newData[dataMainPart][dataSubPart][dataRowPart]={};newData[dataMainPart][dataSubPart][dataRowPart][dataColumnPart]=thisInputValue;}}}return newData;};e10client.prototype.form.setData=function(form,data){var
formElements=form.find('input, select, textarea');var
readOnly=(form.attr('data-readonly')!==undefined);for(var
i=0;i<formElements.length;i++){var
thisInput=$(formElements[i]);if(thisInput.attr("name")===undefined&&thisInput.attr("data-name")===undefined)continue;var
thisInputName=thisInput.attr('name');if(thisInput.attr("data-name"))thisInputName=thisInput.attr('data-name');var
dataMainPart='recData';var
dataSubPart=null;var
dataRowPart=null;var
dataColumnPart=null;var
nameParts=thisInputName.split('.');if(nameParts.length==1)dataColumnPart=thisInputName;else
if(nameParts.length==2){dataMainPart=nameParts[0];dataColumnPart=nameParts[1];}else
if(nameParts.length==3){dataMainPart=nameParts[0];dataSubPart=nameParts[1];dataColumnPart=nameParts[2];}else
if(nameParts.length==4){dataMainPart=nameParts[0];dataSubPart=nameParts[1];dataRowPart=nameParts[2];dataColumnPart=nameParts[3];}var
thisInputValue=null;if(dataMainPart=='recData')thisInputValue=data[dataMainPart][dataColumnPart];else
if(nameParts.length==2)thisInputValue=data[dataMainPart][dataColumnPart];else
if(nameParts.length==3)thisInputValue=data[dataMainPart][dataSubPart][dataColumnPart];else{if((data[dataMainPart]!=undefined)&&(data[dataMainPart][dataSubPart]!=undefined)&&(data[dataMainPart][dataSubPart][dataRowPart]!=undefined)&&(data[dataMainPart][dataSubPart][dataRowPart][dataColumnPart]!=undefined))thisInputValue=data[dataMainPart][dataSubPart][dataRowPart][dataColumnPart];}if(thisInputValue===undefined)thisInputValue='';if(thisInput.hasClass("e10-fromSensor")){var
sensorId='#e10-sensordisplay-'+thisInput.attr('data-srcsensor');var
btnInputId=thisInput.attr('id')+'_sensor';if(thisInputValue===undefined||thisInputValue==''||thisInputValue==0){thisInputValue=$(sensorId).text();}var
dstelm=form;var
sensList=dstelm.attr('data_receivesensors');if(sensList===undefined)sensList=btnInputId;else
sensList=sensList+' '+btnInputId;dstelm.attr('data_receivesensors',sensList);$('#'+btnInputId).text($(sensorId).text());}if(thisInput.hasClass("e10-inputLogical")){var
checkIt=false;if(thisInput.val()=='1'){if(thisInputValue==1)checkIt=true;}else
if(thisInputValue)checkIt=true;if(checkIt)thisInput.attr('checked',true);else
thisInput.attr('checked',false);}else
if(thisInput.hasClass("e10-inputRadio")){if(thisInput.attr('value')==thisInputValue)thisInput.attr('checked',true);}else
if(thisInput.hasClass("e10-inputDate")){var
dateVal="";var
timeVal="";var
timeInput=null;if(thisInputValue&&thisInputValue.date!==undefined){var
ds=thisInputValue.date.substring(0,10);dp=ds.split("-");dateVal=dp[2]+"."+dp[1]+"."+dp[0];if(thisInput.hasClass("e10-inputDateTime")){timeInput=$('#'+thisInput.attr('id')+'_Time');timeVal=thisInputValue.date.substring(11,16);}}thisInput.val(dateVal);if(timeInput)timeInput.val(timeVal);}else
if(thisInput.hasClass("e10-inputDateTime_Time")){}else
if(thisInput.hasClass("e10-inputEnum")){if(thisInput.hasClass("e10-inputEnumMultiple")){if(thisInputValue)thisInput.val(thisInputValue.split('.'));thisInput.trigger("liszt:updated");}else
thisInput.val(thisInputValue);}else
if(thisInput.hasClass("e10-inputRefId")&&!thisInput.hasClass("e10-inputRefIdDirty")){}else
if(thisInput.hasClass("e10-inputNdx")){thisInput.val(thisInputValue);if(thisInputValue!=0)thisInput.parent().find('span.btns').show();}else
if(thisInput.hasClass("e10-inputDocLink")){var
inpItems='';for(var
ii=0;ii<thisInputValue.length;ii++){var
li=thisInputValue[ii];inpItems+="<li data-pk='"+li['dstRecId']+"' data-table='"+li['dstTableId']+"'"+'>'+li['title']+((readOnly)?"&nbsp;":"<span class='e10-inputDocLink-closeItem'>&times;</span>")+"</li>";}thisInput.find('ul').html(inpItems);if(inpItems!='')thisInput.find("span.placeholder").hide();else
thisInput.find("span.placeholder").show();}else
if(thisInput.hasClass("e10-inputMoney")||thisInput.hasClass("e10-inputDouble")||thisInput.hasClass("e10-inputInt")){if(thisInputValue!=0)thisInput.val(thisInputValue);else
thisInput.val('');}else{thisInput.val(thisInputValue);}}};e10client.prototype.form.getNumber=function(options){e10.form.gnId='gn1234';e10.form.gnValue='';if(e10.form.gnOptions)delete
e10.form.gnOptions;e10.form.gnOptions=options;var
c="<div class='e10-form-get-number' id='"+e10.form.gnId+"'>";c+="<table class='e10-get-number-keyboard'>";c+="<tr>";c+="<td class='c e10-trigger-gn'>✕</td><td class='m' colspan='3'>";if(e10.form.gnOptions.title)c+="<div class='title'>"+e10.escapeHtml(e10.form.gnOptions.title)+"</div>";if(e10.form.gnOptions.subtitle)c+="<div class='e10-small'>"+e10.escapeHtml(e10.form.gnOptions.subtitle)+"</div>";c+="</td>";c+="</tr>";c+="<tr>";c+="<td class='d e10-trigger-gn' colspan='3'></td><td class='b e10-trigger-gn'>←</td>";c+="</tr>";c+="<tr>";c+="<td class='n e10-trigger-gn'>7</td><td class='n e10-trigger-gn'>8</td><td class='n e10-trigger-gn'>9</td><td class='ok e10-trigger-gn' rowspan='4'>✔︎</td>";c+="</tr>";c+="<tr>";c+="<td class='n e10-trigger-gn'>4</td><td class='n e10-trigger-gn'>5</td><td class='n e10-trigger-gn'>6</td>";c+="</tr>";c+="<tr>";c+="<td class='n e10-trigger-gn'>1</td><td class='n e10-trigger-gn'>2</td><td class='n e10-trigger-gn'>3</td>";c+="</tr>";c+="<tr>";c+="<td class='n e10-trigger-gn' colspan='2'>0</td><td class='n e10-trigger-gn'>,</td>";c+="</tr>";c+="</table>";c+="</div>";$('body').append(c);var
form=$('#'+e10.form.gnId);e10.form.gnDisplay=form.find('td.d');return 0;};e10client.prototype.form.getNumberAction=function(event,e){if(e.hasClass('n')){e10.form.gnValue+=e.text();e10.form.gnDisplay.text(e10.form.gnValue);}else
if(e.hasClass('b')){if(e10.form.gnValue!==''){e10.form.gnValue=e10.form.gnValue.slice(0,-1);e10.form.gnDisplay.text(e10.form.gnValue);}}else
if(e.hasClass('c')){e10.form.getNumberClose();}else
if(e.hasClass('ok')){e10.form.getNumberDone();}};e10client.prototype.form.getNumberClose=function(event,e){var
e=$('#'+e10.form.gnId);e.empty().detach();};e10client.prototype.form.getNumberDone=function(event,e){e10.form.gnOptions.success();};e10client.prototype.form.comboViewer=function(options){e10.form.cvId='cv1234';e10.form.cvValue=0;e10.disableKeyDown=1;if(e10.form.cvOptions)delete
e10.form.cvOptions;e10.form.cvOptions=options;var
c="<div class='e10-form-combo-viewer' id='"+e10.form.cvId+"' style='padding: 1em;'>";c+="<div class='e10-form-combo-viewer-content' id='e10-form-combo-viewer-content' style='overflow-y: auto;'></div>";c+="</div>";$('body').append(c);var
url=e10.appUrlRoot;url+='comboviewer/'+options.table+'/'+options.viewer+'?app=1';e10.server.get(url,function(data){var
viewerForm=$('#'+e10.form.cvId);var
viewerContent=$('#e10-form-combo-viewer-content');viewerContent.html(data.object.htmlCode);var
viewerTitle=$('#e10-form-combo-viewer-title');var
viewerContentHeight=viewerForm.innerHeight()-viewerTitle.height()-25;var
viewer=viewerContent.find('>div.e10-viewer');var
viewerList=viewer.find('>ul.e10-viewer-list');viewerList.height(viewerContentHeight);viewerList.get(0).onscroll=function(){e10.viewer.loadNextDataCombo();};var
searchInput=viewerTitle.find('input.e10-inc-search');searchInput.attr('placeholder',e10.form.cvOptions.title).focus();});return 0;};e10client.prototype.form.comboViewerRefreshLayout=function(e){};e10client.prototype.form.comboViewerAction=function(event,e){if(e.hasClass('c')){e10.form.comboViewerClose();}};e10client.prototype.form.comboViewerClose=function(event,e){var
e=$('#'+e10.form.cvId);e.empty().detach();e10.disableKeyDown=0;};e10client.prototype.form.comboViewerDone=function(e){e10.form.cvOptions.success(e);e10.disableKeyDown=0;};e10client.prototype.server={deviceInfoSent:0,remote:null};e10client.prototype.server.init=function(){};e10client.prototype.server.beginUrl=function(){if(e10.server.remote)return'https://'+e10.server.remote;return e10.httpServerRoot;};e10client.prototype.server.httpHeaders=function(){var
headers={};headers['e10-client-type']=e10.clientType;if(e10.oldBrowser)headers['e10-old-browser']='1';if(e10.userSID!=='')headers['e10-login-sid']=e10.userSID;else
if(e10.userPassword!=='')headers['e10-login-pw']=e10.userPassword;else
if(e10.userPin!=='')headers['e10-login-pin']=e10.userPin;if(e10.userLogin!=='')headers['e10-login-user']=e10.userLogin;if(!e10.server.deviceInfoSent){e10.server.deviceInfoSent=1;headers['e10-device-info']=btoa(JSON.stringify(e10.systemInfo(true)));}if(e10.server.remote)headers['e10-remote']=e10.server.remote;return headers;};e10client.prototype.server.api=function(data,f,errorFunction){var
fullUrl=e10.server.beginUrl()+'/api';var
options={type:'POST',url:fullUrl,success:f,data:JSON.stringify(data),dataType:'json',headers:e10.server.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};if(e10.server.remote!==null){options.xhrFields={withCredentials:true};options.crossDomain=true;}e10.server.remote=null;$.ajax(options);};e10client.prototype.server.post=function(url,data,f,errorFunction){var
fullUrl=e10.server.beginUrl()+url;var
options={type:'POST',url:fullUrl,success:f,data:JSON.stringify(data),dataType:'json',headers:e10.server.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};if(e10.server.remote!==null){options.xhrFields={withCredentials:true};options.crossDomain=true;}e10.server.remote=null;$.ajax(options);};e10client.prototype.server.postForm=function(url,data,f){var
fullUrl=e10.server.beginUrl()+url;var
options={type:'POST',url:fullUrl,success:f,data:data,dataType:'json',headers:e10.server.httpHeaders(),error:function(data){console.log("========================ERROR: "+fullUrl);console.log(data);}};if(e10.server.remote!==null){options.xhrFields={withCredentials:true};options.crossDomain=true;}e10.server.remote=null;$.ajax(options);};e10client.prototype.server.get=function(url,f,errorFunction){var
fullUrl=(url.startsWith('https://')?url:e10.server.beginUrl()+url);var
options={type:"GET",url:fullUrl,success:f,dataType:'json',data:"",headers:e10.server.httpHeaders()};if(errorFunction!==undefined)options.error=errorFunction;else
options.error=function(data){console.log("========================ERROR-GET: "+fullUrl);};if(e10.server.remote!==null||url.startsWith('https://')){options.xhrFields={withCredentials:true};options.crossDomain=true;}e10.server.remote=null;$.ajax(options);};e10client.prototype.server.setHttpServerRoot=function(httpServerRoot){e10.httpServerRoot=httpServerRoot;};e10client.prototype.server.setRemote=function(e){if(e.attr('data-remote'))e10.server.remote=e.attr('data-remote');};e10client.prototype.server.setUser=function(login,sid,pw,pin){e10.userPin='';e10.userSID='';e10.userPassword='';e10.userLogin=btoa(login);if(sid&&sid!=='')e10.userSID=btoa(sid);if(pw&&pw!=='')e10.userPassword=btoa(pw);if(pin&&pin!=='')e10.userPin=btoa(pin);};e10client.prototype.wss={g_camerasBarTimer:0};e10client.prototype.wss.init=function(){for(var
i
in
webSocketServers){e10.wss.start(i);}e10.wss.reloadCams();};e10client.prototype.wss.start=function(serverIndex){var
ws=webSocketServers[serverIndex];ws.server=null;ws.retryTimer=0;if("WebSocket"in
window)ws.server=new
WebSocket(ws.wsUrl);else
if("MozWebSocket"in
window)ws.server=new
MozWebSocket(ws.wsUrl);if(ws.server===null)return;ws.server.onopen=function(){e10.wss.setState(serverIndex,'open');};ws.server.onerror=function(evt){e10.wss.setState(serverIndex,'error');};ws.server.onclose=function(){e10.wss.setState(serverIndex,'close');ws.retryTimer=setTimeout(function(){e10.wss.start(serverIndex);},10000);};ws.server.onmessage=function(e){e10.wss.onMessage(serverIndex,e.data);};};e10client.prototype.wss.setState=function(serverIndex,socketState){var
ws=webSocketServers[serverIndex];var
serverIcon=$('#wss-'+ws.id);serverIcon.attr('class','e10-wss e10-wss-'+socketState);};e10client.prototype.wss.onMessage=function(serverIndex,stringData){var
data=eval('('+stringData+')');var
sensorId=data.sensorId;var
elid='wss-'+webSocketServers[serverIndex].ndx+'-'+sensorId;var
deviceBtn=$('#'+elid);if(!deviceBtn.length)return;if(data.cmd){if(data.deviceId==e10.deviceId)return;if(data.cmd=='lockSensor')deviceBtn.removeClass('e10-sensor-on');if(data.cmd=='unlockSensor')deviceBtn.addClass('e10-sensor-on');return;}if(!deviceBtn.hasClass('e10-sensor-on')&&!deviceBtn.hasClass('allwaysOn'))return;if(deviceBtn.attr('data-call-function')!==undefined){e10.executeFunctionByName(deviceBtn.attr('data-call-function'),deviceBtn,data);return;}if(data.sensorClass=='number'){var
value=data.value;$('#e10-sensordisplay-'+sensorId).text(value);var
form=$('body>div.e10-form');if(form.length!=0){var
receiveSensors=form.attr('data_receivesensors');if(receiveSensors!==undefined){var
sids=receiveSensors.split(' ');for(i=0;i<sids.length;i++)$('#'+sids[i]).text(value);}}if(e10.wss.g_camerasBarTimer!==0){clearTimeout(e10.wss.g_camerasBarTimer);e10.wss.g_camerasBarTimer=0;e10.wss.reloadCams();}}};e10client.prototype.wss.reloadCams=function(){for(var
si
in
webSocketServers){var
ws=webSocketServers[si];var
camsList=ws.camList;var
camUrl=ws.camerasURL;var
urlPath=ws.camerasURL+"cams"+"?callback=?";var
jqxhr=$.getJSON(urlPath,function(data){for(var
ii
in
data){var
picFileName=camUrl+'imgs/-w960/-q70/'+ii+"/"+data[ii];var
origPicFileName=camUrl+'/imgs/'+ii+"/"+data[ii];$('#e10-cam-'+ii+'-right').attr("src",picFileName).parent().attr("data-pict",origPicFileName).attr("data-pict-thumb",picFileName);}e10.wss.g_camerasBarTimer=setTimeout(e10.wss.reloadCams,60000);});}};e10client.prototype.options={};e10client.prototype.options.get=function(key,defaultValue){var
value=localStorage.getItem('options-'+key);if(value===null)return defaultValue;return value;};e10client.prototype.options.set=function(key,value){localStorage.setItem('options-'+key,value);};e10client.prototype.options.openDialog=function(){e10.printbt.scan();var
tc="<span class='lmb e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></span>"+"<div class='pageTitle'><h1>Nastavení aplikace</h1><h2>Shipard</h2></div>"+"<ul class='rb'><li class='e10-trigger-action' data-action='app-options-save'><i class='fa fa-check'></i> Hotovo</li></ul>";var
cc='';cc+="<div class='e10-option-row'><label>Způsob tisku účtenek</label><select name='receiptsLocalPrintMode'>";cc+="<option value='none'>-- nenastavovat --</option>";if(e10.printbt.supported)cc+="<option value='bt'>Bluetooth</option>";cc+="<option value='lan'>Síť (WiFi)</option>";cc+="</select></div>";if(e10.printbt.supported){cc+="<div class='e10-option-row'><label>Bluetooth tiskárna</label><select name='receiptsPrinterBluetooth'>";cc+="<option value=''>Nenastaveno</option>";for(var
printerId
in
e10.printbt.printers){var
printerName=e10.printbt.printers[printerId];console.log("#P "+printerId+" - "+printerName);cc+="<option value='"+printerId+"'>"+printerName+"</option>";}cc+="</select></div>";}cc+="<div class='e10-option-row'><label>IP adresa tiskárny</label><br><input type='string' class='e10-inputString' name='receiptsPrinterLan'></div>";cc+="<div class='e10-option-row'><label>Typ tiskárny</label><select name='receiptsLocalPrinterType'>";cc+="<option value='normal'>Klasická (78mm)</option>";cc+="<option value='thin'>Úzká (55mm)</option>";cc+="</select></div>";cc+="<div class='e10-option-row'><label>Používat jako Terminál</label><input type='checkbox' class='e10-inputLogical' name='useTerminalMode' value='1'></div>";cc+="<div class='e10-option-row'><label>URL terminálu</label><br><input type='url' class='e10-inputString' name='terminalURL'></div>";var
data={toolbarCode:tc,contentCode:cc,formData:{recData:{receiptsLocalPrintMode:e10.options.get('receiptsLocalPrintMode','none'),receiptsPrinterBluetooth:e10.options.get('receiptsPrinterBluetooth',''),receiptsPrinterLan:e10.options.get('receiptsPrinterLan',''),receiptsLocalPrinterType:e10.options.get('receiptsLocalPrinterType','normal'),useTerminalMode:e10.options.get('useTerminalMode',0),terminalURL:e10.options.get('terminalURL','')}}};e10.form.create(data,'app-options');};e10client.prototype.options.saveDialog=function(e){var
form=e10.searchObjectAttr(e,'data-object');var
postData={};postData.formData=e10.form.getData(form);e10.options.set('receiptsLocalPrintMode',postData.formData.recData.receiptsLocalPrintMode);e10.options.set('receiptsPrinterBluetooth',postData.formData.recData.receiptsPrinterBluetooth);e10.options.set('receiptsPrinterLan',postData.formData.recData.receiptsPrinterLan);e10.options.set('receiptsLocalPrinterType',postData.formData.recData.receiptsLocalPrinterType);e10.options.set('useTerminalMode',postData.formData.recData.useTerminalMode);e10.options.set('terminalURL',postData.formData.recData.terminalURL);window.location.reload();e10.options.apply();return 1;};e10client.prototype.options.apply=function(e){var
useBluetooth=e10.options.get('useBluetooth',0);if(useBluetooth==1)e10.bt.on();else
e10.bt.off();var
shareLocation=e10.options.get('shareLocation',0);if(shareLocation==1)e10.geo.on();else
e10.geo.off();};e10client.prototype.options.fontSize=function(e,how){var
cfs=$('html').css('font-size');var
cfsNumber=parseInt(cfs,10);if(how===0){$('html').css('font-size','medium');e10.options.set('appFontSize',null);}else{cfsNumber+=how;$('html').css('font-size',cfsNumber+'px');e10.options.set('appFontSize',cfsNumber);}e10.refreshLayout();};e10client.prototype.options.loadAppSettings=function(){var
fontSize=e10.options.get('appFontSize');if(fontSize)$('html').css('font-size',fontSize+'px');};e10client.prototype.options.openAppMenuDialog=function(){var
appType=$('body').attr('data-app-type');var
tc='';if(e10.userInfo){tc="<span class='lmb'><i class='fa fa-user'></i></span>"+"<div class='pageTitle'>";tc+="<h1>"+e10.escapeHtml(e10.userInfo.name)+"</h1>";tc+="<h2>"+e10.escapeHtml(e10.userInfo.login)+"</h2>";tc+="<ul class='rb'><li class='e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></li></ul>";tc+="</div>";}else{tc="<span class='lmb'><i class='fa fa-wrench'></i></span>"+"<div class='pageTitle'>";tc+="<h1>"+e10.escapeHtml('Nastavení aplikace')+"</h1>";tc+="<h2>"+e10.escapeHtml(' ')+"</h2>";tc+="<ul class='rb'><li class='e10-trigger-action' data-action='form-close'><i class='fa fa-times'></i></li></ul>";tc+="</div>";}var
cc='';if(1){cc+="<div class='block'>";cc+="<h3>Velikost písma</h3>";cc+="<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-plus'><i class='fa fa-plus'></i><span>Větší</span></div>";cc+="<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-minus'><i class='fa fa-minus'></i><span>Menší</span></div>";cc+="<div class='e10-option-fsbtn e10-trigger-action' data-action='app-fs-reset'><i class='fa fa-asterisk'></i><span>Výchozí</span></div>";cc+="<div class='font-size-example'>";cc+="Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.";cc+="</div>";cc+="</div>";}if(e10.app){cc+="<div class='block'>";cc+="<div class='e10-option-bigbtn e10-trigger-action' data-action='app-options'><i class='fa fa-cog'></i><span>Nastavení</span></div>";cc+="<div class='e10-option-bigbtn e10-trigger-action' data-action='app-logout'><i class='fa fa-power-off'></i><span>Odhlásit</span></div>";cc+="</div>";}var
data={toolbarCode:tc,contentCode:cc,formData:{recData:{}}};e10.form.create(data,'app-menu');};e10client.prototype.options.appAbout=function(e){var
infoBox=e.find('>div.info');if(!infoBox.is(":visible")){var
info="<div style='border-top: 1px solid gray; padding-top: .5rem;'>";var
systemInfo=e10.systemInfo();for(var
i
in
systemInfo){var
item=systemInfo[i];info+='<p><b>'+e10.escapeHtml(item.title)+'</b><br><small>'+e10.escapeHtml(item.value)+'</small></p>';}info+='</div>';infoBox.html(info);infoBox.show();}else{infoBox.hide();}};e10client.prototype.terminal={widgetId:'',symbolForProduct:'',mode:'cashbox',boxWidget:null,boxProducts:null,boxRows:null,boxPay:null,boxDone:null,document:null,lastPosReports:null,calculatorMode:0,ckPrice:'',ckQuantity:'',ckMode:0,};e10client.prototype.terminal.init=function(widgetId){if(e10.terminal.widgetId===''){$('body').on(e10.CLICK_EVENT,"ul.tabs>li",function(event){event.stopPropagation();event.preventDefault();e10.terminal.productsTabsClick($(this));});$('body').on(e10.CLICK_EVENT,".e10-trigger-ck",function(event){event.stopPropagation();event.preventDefault();e10.terminal.calcKeyboard(event,$(this),0);});$('body').on(e10.CLICK_EVENT,"div.products>span",function(event){event.stopPropagation();event.preventDefault();e10.terminal.newRow($(this));});$('body').on(e10.CLICK_EVENT,".e10-terminal-action",function(event){event.stopPropagation();event.preventDefault();e10.terminal.action(event,$(this));});}e10.terminal.calculatorMode=0;var
activeProductsTab=$('#e10-wcb-products-tabs>li.active');if(activeProductsTab.attr('data-tabid')==='e10-wcb-cat-calc_kbd')e10.terminal.calculatorMode=1;e10.terminal.ckPrice='';e10.terminal.ckQuantity='';e10.terminal.ckMode=0;e10.terminal.symbolForProduct='';e10.terminal.widgetId=widgetId;e10.terminal.boxWidget=$('#'+widgetId);e10.terminal.boxRows=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows');e10.terminal.boxProducts=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products');e10.terminal.boxPay=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-pay');e10.terminal.boxDone=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-done');e10.terminal.setPrintRetryButton();e10.terminal.refreshLayout();e10.terminal.documentInit();};e10client.prototype.terminal.refreshLayout=function(){var
w=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content');var
hh=$(window).height()-$('#e10-page-header').height();w.height(hh);if(e10.terminal.mode==='cashbox'){var
rowsContainer=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows');var
display=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.display');var
rhh=(rowsContainer.parent().innerHeight()-display.outerHeight())|0;rowsContainer.height(rhh);var
buttonsContainer=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products>div.e10-wcb-products-buttons');var
tabs=$('#e10-wcb-products-tabs');rhh=(buttonsContainer.parent().innerHeight()-tabs.outerHeight())|0;buttonsContainer.height(rhh);}else
if(e10.terminal.mode==='pay'){}};e10client.prototype.terminal.productsTabsClick=function(e){var
tabs=e.parent();var
activeTab=tabs.find('>li.active');activeTab.removeClass('active');var
activeTabId=activeTab.attr('data-tabid');$('#'+activeTabId).hide();e.addClass('active');activeTabId=e.attr('data-tabid');$('#'+activeTabId).show();if(activeTabId==='e10-wcb-cat-calc_kbd')e10.terminal.calculatorMode=1;else
e10.terminal.calculatorMode=0;};e10client.prototype.terminal.calcKeyboard=function(event,e,keyCode){var
number=null;var
backspace=0;var
multiply=0;var
ok=0;if(e){if(e.hasClass('n'))number=e.text();if(e.hasClass('b'))backspace=1;if(e.hasClass('multiply'))multiply=1;if(e.hasClass('ok'))ok=1;}else
if(keyCode){if(event.keyCode>=48&&event.keyCode<=57)number=String.fromCharCode(event.which);else
if(event.keyCode>=96&&event.keyCode<=105)number=String.fromCharCode(event.which-48);else
if(event.key===','||event.key==='.'||event.keyCode===110)number=',';else
if(event.keyCode===8)backspace=1;else
if(event.keyCode===13||event.keyCode===107)ok=1;else
if(event.keyCode===88||event.key==='*'||event.keyCode===106)multiply=1;}if(number!==null){if(e10.terminal.ckMode===0){e10.terminal.ckPrice+=number;$('#e10-display-ck').text(e10.terminal.ckPrice);}else{e10.terminal.ckQuantity+=number;$('#e10-display-ck').text(e10.terminal.ckPrice+' × '+e10.terminal.ckQuantity);}}else
if(backspace){if(e10.terminal.ckMode===0){if(e10.terminal.ckPrice!==''){e10.terminal.ckPrice=e10.terminal.ckPrice.slice(0,-1);$('#e10-display-ck').text(e10.terminal.ckPrice);}}else
if(e10.terminal.ckMode===1){if(e10.terminal.ckQuantity!==''){e10.terminal.ckQuantity=e10.terminal.ckQuantity.slice(0,-1);$('#e10-display-ck').text(e10.terminal.ckPrice+' × '+e10.terminal.ckQuantity);}else{e10.terminal.ckMode=0;$('#e10-display-ck').text(e10.terminal.ckPrice);}}}else
if(multiply){if(e10.terminal.ckMode===0){e10.terminal.ckMode=1;$('#e10-display-ck').text(e10.terminal.ckPrice+' × ');}}else
if(ok){var
quantity=1;var
price=e10.parseFloat(e10.terminal.ckPrice);if(e10.terminal.ckMode===1){quantity=e10.parseFloat(e10.terminal.ckQuantity);if(quantity==0)quantity=1;}if(!e)e=$('#e10-terminal-ck-primary');if(e){e.attr('data-price',price);e.attr('data-quantity',quantity);e10.terminal.addDocumentRow(e10.terminal.itemFromElement(e));}e10.terminal.ckPrice='';e10.terminal.ckQuantity='';e10.terminal.ckMode=0;$('#e10-display-ck').text('');}};e10client.prototype.terminal.itemFromElement=function(e){var
item={pk:e.attr('data-pk'),price:parseFloat(e.attr('data-price')),quantity:(e.attr('data-quantity'))?parseFloat(e.attr('data-quantity')):1,name:e.attr('data-name'),askq:e.attr('data-askq'),askp:e.attr('data-askp'),unit:e.attr('data-unit'),unitName:e.attr('data-unit-name')};return item;};e10client.prototype.terminal.newRow=function(e){var
askq=parseInt(e.attr('data-askq'));var
askp=parseInt(e.attr('data-askp'));if(!askq&&!askp){e10.terminal.addDocumentRow(e10.terminal.itemFromElement(e));return;}if(askp){e10.form.getNumber({title:'Zadejte cenu '+'('+e.attr('data-unit-name')+')',subtitle:e.attr('data-name'),srcElement:e,askType:'p',success:e10.terminal.addDocumentRow});return;}if(askq){e10.form.getNumber({title:'Zadejte množství '+'('+e.attr('data-unit-name')+')',subtitle:e.attr('data-name'),srcElement:e,askType:'q',success:e10.terminal.addDocumentRow});}};e10client.prototype.terminal.addDocumentRow=function(item){var
quantity=1;if(!item){e10.form.getNumberClose();item=e10.terminal.itemFromElement(e10.form.gnOptions.srcElement);if(e10.form.gnOptions.askType==='p'){var
price=e10.parseFloat(e10.form.gnValue);if(!price)price=null;if(price!==null)item.price=price;}else
if(!e10.form.gnOptions.askType||e10.form.gnOptions.askType==='q'){quantity=e10.parseFloat(e10.form.gnValue);if(!quantity)quantity=1;}}else
if(item.quantity)quantity=item.quantity;var
priceStr=e10.nf(item.price,2);var
totalPrice=e10.round(quantity*item.price,2);var
totalPriceStr=e10.nf(totalPrice,2);var
row='<tr'+' data-pk="'+item.pk+'"'+' data-quantity="'+quantity+'"'+' data-price="'+item.price+'"'+' data-totalprice="'+totalPrice+'"'+'>';row+='<td class="e10-terminal-action" data-action="remove-row">×</td>';row+='<td class="item">'+'<span class="t">'+e10.escapeHtml(item.name)+'</span>'+'<br>'+'<span class="e10-small i e10-terminal-action" data-action="row-price-item-change">'+quantity+' '+item.unitName+' á '+priceStr+' = <b>'+totalPriceStr+'</b>'+'</span>'+'</td>';row+='<td class="q number">'+quantity+'</td>';row+='<td class="e10-terminal-action" data-action="quantity-plus">+</td>';row+='<td class="e10-terminal-action" data-action="quantity-minus">-</td>';row+='</tr>';var
rows=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table');rows.prepend(row);var
re=rows.find('tbody>tr').eq(0);re.attr('data-unit',item.unit);re.attr('data-unit-name',item.unitName);re.attr('data-name',item.name);e10.terminal.documentRecalc();};e10client.prototype.terminal.documentInit=function(clearUI){if(e10.terminal.document!==null)delete
e10.terminal.document;e10.terminal.document={rec:{docType:"cashreg",currency:"czk",paymentMethod:e10.terminal.detectPaymentMethod(),taxCalc:parseInt(e10.terminal.boxWidget.attr('data-taxcalc')),automaticRound:1,roundMethod:parseInt(e10.terminal.boxWidget.attr('data-roundmethod')),cashBox:parseInt(e10.terminal.boxWidget.attr('data-cashbox')),warehouse:parseInt(e10.terminal.boxWidget.attr('data-warehouse')),docState:4000,docStateMain:2,toPay:0.0},rows:[]};if(clearUI===true){var
rows=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table>tbody');rows.empty();e10.terminal.documentRecalc();}};e10client.prototype.terminal.documentRecalc=function(){var
rowsCount=0;var
totalPrice=0.0;e10.terminal.document.rows.length=0;var
rows=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>div.rows>table>tbody');rows.find('>tr').each(function(){var
r=$(this);var
rowTotalPrice=parseFloat(r.attr('data-totalprice'));totalPrice+=rowTotalPrice;rowsCount++;var
documentRow={item:parseInt(r.attr('data-pk')),text:r.attr('data-name'),quantity:parseFloat(r.attr('data-quantity')),unit:r.attr('data-unit'),priceItem:parseFloat(r.attr('data-price'))};e10.terminal.document.rows.push(documentRow);});var
totalPriceStr=e10.nf(totalPrice,2);var
displayTotal=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.display>li.total');displayTotal.text(totalPriceStr);var
toPay=(e10.terminal.document.rec.roundMethod===1)?e10.round(totalPrice,0):totalPrice;e10.terminal.document.toPay=toPay;var
displayToPay=e10.terminal.boxPay.find('>div.pay-right>div.pay-display>span.money-to-pay');displayToPay.text(e10.nf(toPay,2));if(rowsCount){e10.terminal.boxRows.find('>div.close').hide();e10.terminal.boxRows.find('>div.rows').show();}else{e10.terminal.boxRows.find('>div.rows').hide();e10.terminal.boxRows.find('>div.close').show();}};e10client.prototype.terminal.documentQuantityRow=function(e,how){var
row=e.parent();var
quantity=parseFloat(row.attr('data-quantity'));if(how===-1&&quantity<=1.0)return;quantity+=how;var
price=parseFloat(row.attr('data-price'));var
totalPrice=quantity*price;var
quantityStr=quantity;row.attr('data-quantity',quantity);row.attr('data-totalprice',totalPrice);row.find('td.q').text(quantityStr);var
unitName=row.attr('data-unit-name');var
rowInfo=quantityStr+' '+unitName+' á '+e10.nf(price,2)+' = <b>'+e10.nf(totalPrice,2)+'</b>';row.find('td.item>span.i').html(rowInfo);e10.terminal.documentRecalc();return 0;};e10client.prototype.terminal.rowPriceItemChangeAsk=function(e){var
row=e.parent().parent();e10.form.getNumber({title:'Zadejte novou cenu položky',subtitle:row.attr('data-name'),srcElement:row,success:e10.terminal.rowPriceItemChange});};e10client.prototype.terminal.rowPriceItemChange=function(){var
newPriceItem=0.0;e10.form.getNumberClose();var
row=e10.form.gnOptions.srcElement;newPriceItem=e10.parseFloat(e10.form.gnValue);row.attr('data-price',newPriceItem);var
quantity=parseFloat(row.attr('data-quantity'));var
totalPrice=quantity*newPriceItem;row.attr('data-totalprice',totalPrice);e10.terminal.documentRowResetInfo(row);e10.terminal.documentRecalc();};e10client.prototype.terminal.terminalSearchSymbolManuallyAsk=function(e){e10.form.getNumber({title:'Zadejte kód položky',subtitle:'',srcElement:null,success:e10.terminal.terminalSearchSymbolManually});};e10client.prototype.terminal.terminalSearchSymbolManually=function(e){e10.form.getNumberClose();var
symbol=e10.form.gnValue;e10.terminal.symbolForProduct=symbol;e10.terminal.symbolChanged();e10.terminal.symbolSearch();};e10client.prototype.terminal.terminalSearchSymbolComboAsk=function(e){e10.form.comboViewer({table:'e10.witems.items',viewer:'terminals.store.ItemsViewer',title:'Vyberte položku pro prodej',success:e10.terminal.terminalSearchSymbolCombo});};e10client.prototype.terminal.terminalSearchSymbolCombo=function(e){var
item={pk:parseInt(e.attr('data-pk')),title:e10.b64DecodeUnicode(e.attr('data-cc-title').substring(6)),name:e10.b64DecodeUnicode(e.attr('data-cc-name').substring(5)),price:parseFloat(e10.b64DecodeUnicode(e.attr('data-cc-price').substring(6))),unit:e10.b64DecodeUnicode(e.attr('data-cc-unit').substring(5)),unitName:e10.b64DecodeUnicode(e.attr('data-cc-unitname').substring(9)),};e10.form.comboViewerClose();e10.terminal.addDocumentRow(item);};e10client.prototype.terminal.documentRemoveRow=function(e,how){var
row=e.parent();row.detach();e10.terminal.documentRecalc();return 0;};e10client.prototype.terminal.documentRowResetInfo=function(row){var
unitName=row.attr('data-unit-name');var
quantity=parseFloat(row.attr('data-quantity'));var
price=parseFloat(row.attr('data-price'));var
totalPrice=parseFloat(row.attr('data-totalprice'));var
quantityStr=quantity;var
rowInfo=quantityStr+' '+unitName+' á '+e10.nf(price,2)+' = <b>'+e10.nf(totalPrice,2)+'</b>';row.find('td.item>span.i').html(rowInfo);};e10client.prototype.terminal.action=function(event,e){var
action=e.attr('data-action');if(action==='quantity-plus')return e10.terminal.documentQuantityRow(e,1);if(action==='quantity-minus')return e10.terminal.documentQuantityRow(e,-1);if(action==='remove-row')return e10.terminal.documentRemoveRow(e);if(action==='terminal-pay')return e10.terminal.setMode('pay');if(action==='terminal-cashbox')return e10.terminal.setMode('cashbox');if(action==='change-payment-method')return e10.terminal.changePaymentMethod(e);if(action==='do-payment-method')return e10.terminal.doPay(e);if(action==='terminal-done')return e10.terminal.done();if(action==='terminal-retry')return e10.terminal.done();if(action==='terminal-queue')return e10.terminal.queue();if(action==='terminal-symbol-clear')return e10.terminal.symbolClear();if(action==='row-price-item-change')return e10.terminal.rowPriceItemChangeAsk(e);if(action==='terminal-search-code-manually')return e10.terminal.terminalSearchSymbolManuallyAsk(e);if(action==='terminal-search-code-combo')return e10.terminal.terminalSearchSymbolComboAsk(e);if(action==='print-retry')return e10.terminal.printRetry();if(action==='print-exit')return e10.terminal.printExit();};e10client.prototype.terminal.changePaymentMethod=function(e){var
paymentMethod=e.attr('data-pay-method');e10.terminal.document.rec.paymentMethod=parseInt(paymentMethod);if(e10.terminal.document.rec.paymentMethod===2)e10.terminal.document.rec.roundMethod=0;else
e10.terminal.document.rec.roundMethod=parseInt(e10.terminal.boxWidget.attr('data-roundmethod'));e10.terminal.documentRecalc();e.parent().find('.active').removeClass('active');e.addClass('active');};e10client.prototype.terminal.detectPaymentMethod=function(){var
e=e10.terminal.boxPay.find(">div>div.pay-methods>.e10-terminal-action.active");var
paymentMethod=e.attr('data-pay-method');return parseInt(paymentMethod);};e10client.prototype.terminal.done=function(){e10.terminal.setDoneStatus('sending');e10.terminal.setMode('done');var
printAfterConfirm='1';var
receiptsLocalPrintMode=e10.options.get('receiptsLocalPrintMode','none');if(receiptsLocalPrintMode==='bt'||receiptsLocalPrintMode==='lan')printAfterConfirm='2';var
url='/api/objects/insert/e10doc.core.heads?printAfterConfirm='+printAfterConfirm;if(printAfterConfirm=='2'){var
printerType=e10.options.get('receiptsLocalPrinterType','normal');url+='&printerType='+printerType;}e10.server.post(url,e10.terminal.document,function(data){e10.terminal.documentInit(true);if(printAfterConfirm==='2'&&data.posReports){e10.terminal.setDoneStatus('printing');e10.terminal.printReceipts(data.posReports);}else
e10.terminal.setDoneStatus('success');},function(data){e10.terminal.setDoneStatus('error');});};e10client.prototype.terminal.printReceipts=function(posReports){e10.terminal.lastPosReports=posReports;var
receiptsLocalPrintMode=e10.options.get('receiptsLocalPrintMode','none');if(receiptsLocalPrintMode==='bt')e10.printbt.print(posReports[0],function(){e10.terminal.setDoneStatus('success');},function(){e10.terminal.setDoneStatus('printError');});else
if(receiptsLocalPrintMode==='lan')e10.printlan.print(posReports[0],function(){e10.terminal.setDoneStatus('success');},function(){e10.terminal.setDoneStatus('printError');});};e10client.prototype.terminal.printRetry=function(){if(e10.terminal.lastPosReports){if(e10.terminal.mode!=='done')e10.terminal.setMode('done');e10.terminal.setDoneStatus('printing');e10.terminal.printReceipts(e10.terminal.lastPosReports);}};e10client.prototype.terminal.printExit=function(){e10.terminal.setDoneStatus('success');};e10client.prototype.terminal.doPay=function(e){var
paymentMethod=e.attr('data-pay-method');var
paymentMethodButton=e10.terminal.boxWidget.find('div.pay-methods>span[data-pay-method="'+paymentMethod+'"]');e10.terminal.changePaymentMethod(paymentMethodButton);e10.terminal.setMode('pay');};e10client.prototype.terminal.queue=function(){e10.terminal.setDoneStatus('success');e10.terminal.documentInit(true);};e10client.prototype.terminal.setDoneStatus=function(status){var
headerMsg=e10.terminal.boxDone.find('>.header');var
statusMsg=e10.terminal.boxDone.find('>.done-status');var
statusButtons=e10.terminal.boxDone.find('>.done-buttons');var
printButtons=e10.terminal.boxDone.find('>.print-buttons');if(status==='sending'){headerMsg.text('Účtenka se ukládá');statusMsg.text('vyčkejte prosím, dokud se účtenka nezpracuje');statusButtons.hide();printButtons.hide();}else
if(status==='printing'){headerMsg.text('Tisk účtenky');statusMsg.text('probíhá tisk');statusButtons.hide();printButtons.hide();}else
if(status==='success'){statusMsg.text('hotovo');e10.terminal.setMode('cashbox');}else
if(status==='error'){headerMsg.text('Chyba');statusMsg.text('zpracování účtenky bohužel selhalo');statusButtons.show();}else
if(status==='printError'){headerMsg.text('Tisk selhal');statusMsg.html('<h3>Je tiskárna zapnutá?</h3>');statusButtons.hide();printButtons.show();}};e10client.prototype.terminal.symbolClear=function(){e10.terminal.symbolForProduct='';e10.terminal.symbolChanged();};e10client.prototype.terminal.symbolChanged=function(notFound){var
displayValue=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-docrows>ul.symbol>li.value');var
displaySymbol=displayValue.parent();if(e10.terminal.symbolForProduct===''){displaySymbol.hide();}else{displayValue.text(e10.terminal.symbolForProduct);displaySymbol.show();}if(notFound===true)displaySymbol.addClass('notFound');else
displaySymbol.removeClass('notFound');};e10client.prototype.terminal.symbolSearch=function(){var
products=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-products');var
product=products.find("span[data-ean='"+e10.terminal.symbolForProduct+"']");if(product.length){e10.terminal.symbolForProduct='';e10.terminal.symbolChanged();e10.terminal.addDocumentRow(e10.terminal.itemFromElement(product));return;}e10.terminal.searchRemoteItem(e10.terminal.symbolForProduct);};e10client.prototype.terminal.searchRemoteItem=function(symbol){var
url='/api/objects/call/e10-cashreg-item/'+encodeURI(symbol);e10.server.get(url,function(data){if(data.success){e10.terminal.symbolForProduct='';e10.terminal.symbolChanged();e10.terminal.addDocumentRow(data.item);}else{e10.terminal.symbolChanged(true);}},function(data){e10.terminal.symbolChanged(true);});};e10client.prototype.terminal.setMode=function(mode){if(mode==='pay'){e10.terminal.boxDone.hide();e10.terminal.boxRows.hide();e10.terminal.boxProducts.hide();e10.terminal.boxPay.show();}else
if(mode==='cashbox'){e10.terminal.boxDone.hide();e10.terminal.boxPay.hide();e10.terminal.boxRows.show();e10.terminal.boxProducts.show();e10.terminal.setPrintRetryButton();}else
if(mode==='done'){e10.terminal.boxPay.hide();e10.terminal.boxRows.hide();e10.terminal.boxProducts.hide();e10.terminal.boxDone.show();}e10.terminal.mode=mode;e10.terminal.refreshLayout();};e10client.prototype.terminal.setPrintRetryButton=function(){if(e10.terminal.lastPosReports!==null)$('#terminal-print-retry').show();else
$('#terminal-print-retry').hide();};e10client.prototype.terminal.keyDown=function(event,e){if(event.metaKey&&event.keyCode===91)return;if(e10.terminal.calculatorMode){console.log(event.keyCode);e10.terminal.calcKeyboard(event,null,event.keyCode);event.stopPropagation();event.preventDefault();return;}if(event.keyCode===13){e10.terminal.symbolSearch();}else
if(event.keyCode===8){e10.terminal.symbolForProduct=e10.terminal.symbolForProduct.slice(0,-1);e10.terminal.symbolChanged();}else
if(event.keyCode>32&&event.keyCode<128){var
char=String.fromCharCode(event.which);e10.terminal.symbolForProduct+=char;e10.terminal.symbolChanged();}event.stopPropagation();event.preventDefault();return false;};e10client.prototype.terminal.barcode=function(e,data){if(data.sensorClass=='barcode'){var
barcode=data.value;if(barcode.length==12)barcode='0'+data.value;e10.terminal.symbolForProduct=barcode;e10.terminal.symbolChanged();e10.terminal.symbolSearch();}};;(function(){'use strict';function
FastClick(layer,options){var
oldOnClick;options=options||{};this.trackingClick=false;this.trackingClickStart=0;this.targetElement=null;this.touchStartX=0;this.touchStartY=0;this.lastTouchIdentifier=0;this.touchBoundary=options.touchBoundary||10;this.layer=layer;this.tapDelay=options.tapDelay||200;this.tapTimeout=options.tapTimeout||700;if(FastClick.notNeeded(layer)){return;}function
bind(method,context){return function(){return method.apply(context,arguments);};}var
methods=['onMouse','onClick','onTouchStart','onTouchMove','onTouchEnd','onTouchCancel'];var
context=this;for(var
i=0,l=methods.length;i<l;i++){context[methods[i]]=bind(context[methods[i]],context);}if(deviceIsAndroid){layer.addEventListener('mouseover',this.onMouse,true);layer.addEventListener('mousedown',this.onMouse,true);layer.addEventListener('mouseup',this.onMouse,true);}layer.addEventListener('click',this.onClick,true);layer.addEventListener('touchstart',this.onTouchStart,false);layer.addEventListener('touchmove',this.onTouchMove,false);layer.addEventListener('touchend',this.onTouchEnd,false);layer.addEventListener('touchcancel',this.onTouchCancel,false);if(!Event.prototype.stopImmediatePropagation){layer.removeEventListener=function(type,callback,capture){var
rmv=Node.prototype.removeEventListener;if(type==='click'){rmv.call(layer,type,callback.hijacked||callback,capture);}else{rmv.call(layer,type,callback,capture);}};layer.addEventListener=function(type,callback,capture){var
adv=Node.prototype.addEventListener;if(type==='click'){adv.call(layer,type,callback.hijacked||(callback.hijacked=function(event){if(!event.propagationStopped){callback(event);}}),capture);}else{adv.call(layer,type,callback,capture);}};}if(typeof
layer.onclick==='function'){oldOnClick=layer.onclick;layer.addEventListener('click',function(event){oldOnClick(event);},false);layer.onclick=null;}}var
deviceIsWindowsPhone=navigator.userAgent.indexOf("Windows Phone")>=0;var
deviceIsAndroid=navigator.userAgent.indexOf('Android')>0&&!deviceIsWindowsPhone;var
deviceIsIOS=/iP(ad|hone|od)/.test(navigator.userAgent)&&!deviceIsWindowsPhone;var
deviceIsIOS4=deviceIsIOS&&(/OS 4_\d(_\d)?/).test(navigator.userAgent);var
deviceIsIOSWithBadTarget=deviceIsIOS&&(/OS [6-7]_\d/).test(navigator.userAgent);var
deviceIsBlackBerry10=navigator.userAgent.indexOf('BB10')>0;FastClick.prototype.needsClick=function(target){switch(target.nodeName.toLowerCase()){case'button':case'select':case'textarea':if(target.disabled){return true;}break;case'input':if((deviceIsIOS&&target.type==='file')||target.disabled){return true;}break;case'label':case'iframe':case'video':return true;}return(/\bneedsclick\b/).test(target.className);};FastClick.prototype.needsFocus=function(target){switch(target.nodeName.toLowerCase()){case'textarea':return true;case'select':return!deviceIsAndroid;case'input':switch(target.type){case'button':case'checkbox':case'file':case'image':case'radio':case'submit':return false;}return!target.disabled&&!target.readOnly;default:return(/\bneedsfocus\b/).test(target.className);}};FastClick.prototype.sendClick=function(targetElement,event){var
clickEvent,touch;if(document.activeElement&&document.activeElement!==targetElement){document.activeElement.blur();}touch=event.changedTouches[0];clickEvent=document.createEvent('MouseEvents');clickEvent.initMouseEvent(this.determineEventType(targetElement),true,true,window,1,touch.screenX,touch.screenY,touch.clientX,touch.clientY,false,false,false,false,0,null);clickEvent.forwardedTouchEvent=true;targetElement.dispatchEvent(clickEvent);};FastClick.prototype.determineEventType=function(targetElement){if(deviceIsAndroid&&targetElement.tagName.toLowerCase()==='select'){return'mousedown';}return'click';};FastClick.prototype.focus=function(targetElement){var
length;if(deviceIsIOS&&targetElement.setSelectionRange&&targetElement.type.indexOf('date')!==0&&targetElement.type!=='time'&&targetElement.type!=='month'){length=targetElement.value.length;targetElement.setSelectionRange(length,length);}else{targetElement.focus();}};FastClick.prototype.updateScrollParent=function(targetElement){var
scrollParent,parentElement;scrollParent=targetElement.fastClickScrollParent;if(!scrollParent||!scrollParent.contains(targetElement)){parentElement=targetElement;do{if(parentElement.scrollHeight>parentElement.offsetHeight){scrollParent=parentElement;targetElement.fastClickScrollParent=parentElement;break;}parentElement=parentElement.parentElement;}while(parentElement);}if(scrollParent){scrollParent.fastClickLastScrollTop=scrollParent.scrollTop;}};FastClick.prototype.getTargetElementFromEventTarget=function(eventTarget){if(eventTarget.nodeType===Node.TEXT_NODE){return eventTarget.parentNode;}return eventTarget;};FastClick.prototype.onTouchStart=function(event){var
targetElement,touch,selection;if(event.targetTouches.length>1){return true;}targetElement=this.getTargetElementFromEventTarget(event.target);touch=event.targetTouches[0];if(deviceIsIOS){selection=window.getSelection();if(selection.rangeCount&&!selection.isCollapsed){return true;}if(!deviceIsIOS4){if(touch.identifier&&touch.identifier===this.lastTouchIdentifier){event.preventDefault();return false;}this.lastTouchIdentifier=touch.identifier;this.updateScrollParent(targetElement);}}this.trackingClick=true;this.trackingClickStart=event.timeStamp;this.targetElement=targetElement;this.touchStartX=touch.pageX;this.touchStartY=touch.pageY;if((event.timeStamp-this.lastClickTime)<this.tapDelay){event.preventDefault();}return true;};FastClick.prototype.touchHasMoved=function(event){var
touch=event.changedTouches[0],boundary=this.touchBoundary;if(Math.abs(touch.pageX-this.touchStartX)>boundary||Math.abs(touch.pageY-this.touchStartY)>boundary){return true;}return false;};FastClick.prototype.onTouchMove=function(event){if(!this.trackingClick){return true;}if(this.targetElement!==this.getTargetElementFromEventTarget(event.target)||this.touchHasMoved(event)){this.trackingClick=false;this.targetElement=null;}return true;};FastClick.prototype.findControl=function(labelElement){if(labelElement.control!==undefined){return labelElement.control;}if(labelElement.htmlFor){return document.getElementById(labelElement.htmlFor);}return labelElement.querySelector('button, input:not([type=hidden]), keygen, meter, output, progress, select, textarea');};FastClick.prototype.onTouchEnd=function(event){var
forElement,trackingClickStart,targetTagName,scrollParent,touch,targetElement=this.targetElement;if(!this.trackingClick){return true;}if((event.timeStamp-this.lastClickTime)<this.tapDelay){this.cancelNextClick=true;return true;}if((event.timeStamp-this.trackingClickStart)>this.tapTimeout){return true;}this.cancelNextClick=false;this.lastClickTime=event.timeStamp;trackingClickStart=this.trackingClickStart;this.trackingClick=false;this.trackingClickStart=0;if(deviceIsIOSWithBadTarget){touch=event.changedTouches[0];targetElement=document.elementFromPoint(touch.pageX-window.pageXOffset,touch.pageY-window.pageYOffset)||targetElement;targetElement.fastClickScrollParent=this.targetElement.fastClickScrollParent;}targetTagName=targetElement.tagName.toLowerCase();if(targetTagName==='label'){forElement=this.findControl(targetElement);if(forElement){this.focus(targetElement);if(deviceIsAndroid){return false;}targetElement=forElement;}}else
if(this.needsFocus(targetElement)){if((event.timeStamp-trackingClickStart)>100||(deviceIsIOS&&window.top!==window&&targetTagName==='input')){this.targetElement=null;return false;}this.focus(targetElement);this.sendClick(targetElement,event);if(!deviceIsIOS||targetTagName!=='select'){this.targetElement=null;event.preventDefault();}return false;}if(deviceIsIOS&&!deviceIsIOS4){scrollParent=targetElement.fastClickScrollParent;if(scrollParent&&scrollParent.fastClickLastScrollTop!==scrollParent.scrollTop){return true;}}if(!this.needsClick(targetElement)){event.preventDefault();this.sendClick(targetElement,event);}return false;};FastClick.prototype.onTouchCancel=function(){this.trackingClick=false;this.targetElement=null;};FastClick.prototype.onMouse=function(event){if(!this.targetElement){return true;}if(event.forwardedTouchEvent){return true;}if(!event.cancelable){return true;}if(!this.needsClick(this.targetElement)||this.cancelNextClick){if(event.stopImmediatePropagation){event.stopImmediatePropagation();}else{event.propagationStopped=true;}event.stopPropagation();event.preventDefault();return false;}return true;};FastClick.prototype.onClick=function(event){var
permitted;if(this.trackingClick){this.targetElement=null;this.trackingClick=false;return true;}if(event.target.type==='submit'&&event.detail===0){return true;}permitted=this.onMouse(event);if(!permitted){this.targetElement=null;}return permitted;};FastClick.prototype.destroy=function(){var
layer=this.layer;if(deviceIsAndroid){layer.removeEventListener('mouseover',this.onMouse,true);layer.removeEventListener('mousedown',this.onMouse,true);layer.removeEventListener('mouseup',this.onMouse,true);}layer.removeEventListener('click',this.onClick,true);layer.removeEventListener('touchstart',this.onTouchStart,false);layer.removeEventListener('touchmove',this.onTouchMove,false);layer.removeEventListener('touchend',this.onTouchEnd,false);layer.removeEventListener('touchcancel',this.onTouchCancel,false);};FastClick.notNeeded=function(layer){var
metaViewport;var
chromeVersion;var
blackberryVersion;var
firefoxVersion;if(typeof
window.ontouchstart==='undefined'){return true;}chromeVersion=+(/Chrome\/([0-9]+)/.exec(navigator.userAgent)||[,0])[1];if(chromeVersion){if(deviceIsAndroid){metaViewport=document.querySelector('meta[name=viewport]');if(metaViewport){if(metaViewport.content.indexOf('user-scalable=no')!==-1){return true;}if(chromeVersion>31&&document.documentElement.scrollWidth<=window.outerWidth){return true;}}}else{return true;}}if(deviceIsBlackBerry10){blackberryVersion=navigator.userAgent.match(/Version\/([0-9]*)\.([0-9]*)/);if(blackberryVersion[1]>=10&&blackberryVersion[2]>=3){metaViewport=document.querySelector('meta[name=viewport]');if(metaViewport){if(metaViewport.content.indexOf('user-scalable=no')!==-1){return true;}if(document.documentElement.scrollWidth<=window.outerWidth){return true;}}}}if(layer.style.msTouchAction==='none'||layer.style.touchAction==='manipulation'){return true;}firefoxVersion=+(/Firefox\/([0-9]+)/.exec(navigator.userAgent)||[,0])[1];if(firefoxVersion>=27){metaViewport=document.querySelector('meta[name=viewport]');if(metaViewport&&(metaViewport.content.indexOf('user-scalable=no')!==-1||document.documentElement.scrollWidth<=window.outerWidth)){return true;}}if(layer.style.touchAction==='none'||layer.style.touchAction==='manipulation'){return true;}return false;};FastClick.attach=function(layer,options){return new
FastClick(layer,options);};if(typeof
define==='function'&&typeof
define.amd==='object'&&define.amd){define(function(){return FastClick;});}else
if(typeof
module!=='undefined'&&module.exports){module.exports=FastClick.attach;module.exports.FastClick=FastClick;}else{window.FastClick=FastClick;}}());$(function(){FastClick.attach(document.body);});e10client.prototype.widgets={};e10client.prototype.widgets.init=function(){};e10client.prototype.widgets.autoRefresh=function(widgetId){var
widget=$('#'+widgetId);if(widget.length===0){return;}e10WidgetAction(0,null,widgetId);setTimeout("e10.widgets.autoRefresh('"+widgetId+"')",60000);};e10client.prototype.widgets.vs={camerasTimer:0,widgetId:'',gridType:'',gridMode:'',smartActiveCamera:'',smartMainElement:null,archiveDate:'',archiveHour:'',localServers:null};e10client.prototype.widgets.vs.init=function(elementId,localServers){e10.widgets.vs.gridType=$('#e10-widget-vs-type').val();e10.widgets.vs.gridMode=$('#e10-widget-vs-mode').val();e10.widgets.vs.widgetId=elementId;e10.widgets.vs.localServers=localServers;if(e10.widgets.vs.gridMode==='smart'){e10.widgets.vs.smartMainElement=$('#e10-vs-smart-main');e10.widgets.vs.smartActiveCamera=e10.widgets.vs.smartMainElement.attr('data-active-cam');}if(e10.widgets.vs.gridType==='live')e10.widgets.vs.reloadLive();else
e10.widgets.vs.initArchive();};e10client.prototype.widgets.vs.reloadLive=function(){if(e10.widgets.vs.camerasTimer){clearTimeout(e10.widgets.vs.camerasTimer);}for(var
si
in
e10.widgets.vs.localServers){var
ws=e10.widgets.vs.localServers[si];var
camUrl=ws.camerasURL;var
urlPath=ws.camerasURL+"/cameras?callback=?";var
jqxhr=$.getJSON(urlPath,function(data){var
cntSuccess=0;for(var
ii
in
data){if(!data[ii].image)continue;var
picFileName=camUrl+'imgs/-w960/-q70/'+ii+"/"+data[ii].image;var
origPicFileName=camUrl+'/imgs/'+ii+"/"+data[ii].image;var
imgElement=$('#e10-camp-'+ii);if(imgElement.length===0)continue;var
cameraId=imgElement.attr('data-camera');if(imgElement.hasClass('zoomed'))imgElement.attr("src",origPicFileName).parent().attr("data-pict",origPicFileName);else
imgElement.attr("src",picFileName).parent().attr("data-pict",origPicFileName);if(e10.widgets.vs.smartMainElement!==null&&cameraId===e10.widgets.vs.smartActiveCamera){$('#e10-vs-smart-main-img').attr('src',origPicFileName);}if(data[ii].error)imgElement.addClass('e10-error');else
imgElement.removeClass('e10-error');cntSuccess++;}if(!cntSuccess){e10.widgets.vs.camerasTimer=0;return;}e10.widgets.vs.camerasTimer=setTimeout(e10.widgets.vs.reloadLive,3000);}).error(function(){alert("error XobnovaKamer: content not loaded ("+urlPath+")");});}};e10client.prototype.widgets.vs.initArchive=function(){e10.widgets.vs.archiveDate=$('#e10-widget-vs-day').val();e10.widgets.vs.archiveHour=$('#e10-widget-vs-hour').val();e10.widgets.vs.setVideos();};e10client.prototype.widgets.vs.setVideos=function(){$('#'+e10.widgets.vs.widgetId).find('div.e10-camv').each(function(){e10.widgets.vs.setVideo($(this));});};e10client.prototype.widgets.vs.setMainPicture=function(e){var
cameraId=e.attr('data-camera');e10.widgets.vs.smartActiveCamera=cameraId;$('#e10-vs-smart-main-img').attr('src',e.attr('src'));};e10client.prototype.widgets.vs.zoomPicture=function(e){if(e.hasClass('zoomed')){e.removeClass('zoomed');e.parent().parent().removeClass('zoomed');}else{e.addClass('zoomed');e.parent().parent().addClass('zoomed');}};e10client.prototype.widgets.vs.zoomMainPicture=function(e){var
primaryBox=e.parent().find('div.e10-wvs-smart-primary-box');if(e.hasClass('zoomed')){e.removeClass('zoomed');primaryBox.removeClass('zoomed');}else{e.addClass('zoomed');primaryBox.addClass('zoomed');}};e10client.prototype.widgets.vs.setVideo=function(e){var
camUrl=e.attr('data-cam-url');var
cameraId=e.attr('data-camera');var
bfn=e.attr('data-bfn');var
videoFileName=cameraId+'-'+e10.widgets.vs.archiveDate+'-'+e10.widgets.vs.archiveHour+'.mp4';var
posterFileName=cameraId+'-'+e10.widgets.vs.archiveDate+'-'+e10.widgets.vs.archiveHour+'.jpg';var
dateSlashes=e10.widgets.vs.archiveDate.split('-').join('/');var
videoUrl=camUrl+'video/archive/'+e10.widgets.vs.archiveDate+'/'+bfn+'.mp4';var
posterUrl=camUrl+'video/archive/'+e10.widgets.vs.archiveDate+'/'+bfn+'.jpg';var
c='';c+="<video controls style='width: 100%;' preload='none' poster='"+posterUrl+"' src='"+videoUrl+"'>";c+="</video>";e.empty().html(c);};e10client.prototype.widgets.vs.setDay=function(e){e10.widgets.vs.archiveDate=e.val();e10.widgets.vs.setVideos();};e10client.prototype.widgets.vs.setHour=function(e){e10.widgets.vs.archiveHour=e.val();e10.widgets.vs.setVideos();};e10client.prototype.widgets.lans={widgetTimer:0,widgetId:''};e10client.prototype.widgets.lans.init=function(elementId){e10.widgets.lans.widgetId=elementId;e10.widgets.lans.reloadLive();};e10client.prototype.widgets.lans.reloadLive=function(){if(e10.widgets.lans.widgetTimer){clearTimeout(e10.widgets.lans.widgetTimer);}var
widgetElement=$('#'+e10.widgets.lans.widgetId);if(!widgetElement.length){e10.widgets.lans.widgetTimer=0;return;}var
urlPath="/api/objects/call/lan-info-download";e10.server.get(urlPath,function(data){var
cntSuccess=0;for(var
ii
in
data.lanInfo.devices){var
deviceStatus=data.lanInfo.devices[ii];if(e10.widgets.lans.setDeviceStatus(ii,deviceStatus))cntSuccess++;}e10.widgets.lans.widgetTimer=setTimeout(e10.widgets.lans.reloadLive,30000);});};e10client.prototype.widgets.lans.setDeviceStatus=function(deviceNdx,deviceStatus){var
cntSuccess=0;var
allUp=1;var
allDown=1;var
parentRow=null;var
widget=$('#'+e10.widgets.lans.widgetId);var
deviceIsUp=0;var
device=$('#'+e10.widgets.lans.widgetId+'-'+deviceNdx);for(var
a
in
deviceStatus.addr){var
addr=deviceStatus.addr[a];for(var
i=0;i<addr.rts.length;i++){var
rts=addr.rts[i];if(i===0&&rts.up)deviceIsUp=1;var
dataId='d'+deviceNdx+'-'+addr.ip+'-'+i;var
statusElement=widget.find('span[data-rt-id="'+dataId+'"]');if(!statusElement.length)continue;if(rts.up){if(i===0)deviceIsUp=1;allDown=0;statusElement.html("<i class='fa fa-check fa-fw'></i>").prop('title',rts.title);}else{allUp=0;statusElement.html("<i class='fa fa-times fa-fw'></i>").prop('title',rts.title);}parentRow=statusElement.parent().parent();if(i===0){parentRow.find('.e10-lans-rt-info').text(rts.title);}cntSuccess++;}if(parentRow){if(allUp){parentRow.find('td.ip').removeClass('e10-row-stop e10-row-pause').addClass('e10-row-play');}else
if(allDown){parentRow.find('td.ip').removeClass('e10-row-play e10-row-pause').addClass('e10-row-stop');}else
parentRow.find('td.ip').removeClass('e10-row-play e10-row-stop').addClass('e10-row-pause');}}if(device.length){if(deviceIsUp){device.removeClass('e10-ld-off').addClass('e10-ld-on');}else{device.removeClass('e10-ld-on').addClass('e10-ld-off');}}return cntSuccess;};e10client.prototype.widgets.macLan={widgetTimer:0,alertsTimer:0,badgesTimer:0,widgetId:'',devicesIPStates:[]};e10client.prototype.widgets.macLan.init=function(elementId){e10.widgets.macLan.widgetId=elementId;e10.widgets.macLan.reloadLive();e10.widgets.macLan.reloadAlerts();if(e10.widgets.macLan.badgesTimer){clearTimeout(e10.widgets.macLan.badgesTimer);}e10.widgets.macLan.badgesTimer=setTimeout(e10.widgets.macLan.reloadBadges,10000);};e10client.prototype.widgets.macLan.reloadLive=function(){if(e10.widgets.macLan.widgetTimer){clearTimeout(e10.widgets.macLan.widgetTimer);}var
widgetElement=$('#'+e10.widgets.macLan.widgetId);if(!widgetElement.length){e10.widgets.macLan.widgetTimer=0;return;}var
urlPath="/api/objects/call/mac-lan-info-download";e10.server.get(urlPath,function(data){var
cntSuccess=0;for(var
rr
in
data.lanInfo.ranges){var
range=data.lanInfo.ranges[rr];for(var
ii
in
range){var
deviceStatus=range[ii];var
deviceNdx=deviceStatus['d'];var
deviceIP=deviceStatus['ip'];var
up=deviceStatus['rts'][0]['up'];if(!e10.widgets.macLan.devicesIPStates.hasOwnProperty(deviceNdx))e10.widgets.macLan.devicesIPStates[deviceNdx]=[];e10.widgets.macLan.devicesIPStates[deviceNdx][deviceIP]=up;var
ipId=rr+'-'+ii.split('.').join('-');if(e10.widgets.macLan.setDeviceStatus(ipId,deviceStatus))cntSuccess++;}}e10.widgets.macLan.setOverviewDevices();e10.widgets.macLan.widgetTimer=setTimeout(e10.widgets.macLan.reloadLive,30000);});};e10client.prototype.widgets.macLan.setDeviceStatus=function(ipId,deviceStatus){var
cntSuccess=0;var
allUp=1;var
allDown=1;var
parentRow=null;var
widget=$('#'+e10.widgets.macLan.widgetId);var
deviceIsUp=0;var
ipElement=$('#'+e10.widgets.macLan.widgetId+'-ip-'+ipId);ipElement.addClass('e10-error');if(!ipElement.length)return 0;var
ifaceElement=ipElement.parent();for(var
i=0;i<deviceStatus.rts.length;i++){var
rts=deviceStatus.rts[i];if(i===0&&rts.up)deviceIsUp=1;var
dataId='r'+ipId+'-'+i;var
flagElement=ifaceElement.find('.e10-lans-rt-flags').find('span[data-rt-id="'+dataId+'"]');if(!flagElement.length){continue;}if(rts.up){if(i===0)deviceIsUp=1;allDown=0;flagElement.html("<i class='fa fa-check fa-fw'></i>").prop('title',rts.title);}else{allUp=0;flagElement.html("<i class='fa fa-times fa-fw'></i>").prop('title',rts.title);}parentRow=flagElement.parent().parent();if(i===0){parentRow.find('.e10-lans-rt-info').text(rts.title);}cntSuccess++;}if(parentRow){if(allUp){parentRow.find('td.ip').removeClass('e10-row-stop e10-row-pause').addClass('e10-row-play');}else
if(allDown){parentRow.find('td.ip').removeClass('e10-row-play e10-row-pause').addClass('e10-row-stop');}else
parentRow.find('td.ip').removeClass('e10-row-play e10-row-stop').addClass('e10-row-pause');}var
device=ipElement.parent().parent().parent();if(device.length&&device.hasClass('e10-lans-device')){if(deviceIsUp){device.removeClass('e10-ld-off').addClass('e10-ld-on');}else{device.removeClass('e10-ld-on').addClass('e10-ld-off');}}return cntSuccess;};e10client.prototype.widgets.macLan.setOverviewDevices=function(){for(var
deviceNdx
in
e10.widgets.macLan.devicesIPStates){var
deviceInfo=e10.widgets.macLan.devicesIPStates[deviceNdx];var
isUp=0;for(var
ipIdx
in
deviceInfo){var
ipIsUp=deviceInfo[ipIdx];isUp+=ipIsUp;}var
indicatorId='#e10-lan-do-'+deviceNdx;var
indicatorElement=$(indicatorId);if(!indicatorElement.length){continue;}if(isUp)indicatorElement.removeClass('e10-error').addClass('e10-success');else
indicatorElement.removeClass('e10-success').addClass('e10-error');indicatorElement.parent().parent().attr('data-device-state',isUp);}e10.widgets.macLan.setOverviewBadges('e10-lan-overview');e10.widgets.macLan.setOverviewBadges('e10-lan-overviewsrv');};e10client.prototype.widgets.macLan.setOverviewBadges=function(elementId){var
overviewElement=$('#'+elementId);if(!overviewElement.length)return;var
tabs=overviewElement.find('ul.e10-static-tabs');var
content=overviewElement.find('div.e10-static-tab-content');tabs.find('>li').each(function(){var
cntErrors=e10client.prototype.widgets.macLan.setOverviewBadgesGroup($(this).attr('data-content-id'));$(this).attr("data-cnt-errors",cntErrors);var
badge=$(this).find('>span.e10-ntf-badge');if(cntErrors){badge.text(cntErrors).show();}else{badge.hide();}});};e10client.prototype.widgets.macLan.setOverviewBadgesGroup=function(groupId){var
cntErrors=0;var
contentElement=$('#'+groupId);contentElement.find('>table>tbody>tr').each(function(){var
state=$(this).attr("data-device-state");if(state!==undefined){if(parseInt(state)===0)cntErrors++;}});return cntErrors;};e10client.prototype.widgets.macLan.reloadBadges=function(elementId){e10.widgets.macLan.reloadBadgesElement('e10-lan-overview');e10.widgets.macLan.reloadBadgesElement('e10-lan-overviewsrv');e10.widgets.macLan.badgesTimer=setTimeout(e10.widgets.macLan.reloadBadges,10000);};e10client.prototype.widgets.macLan.reloadBadgesElement=function(elementId){var
overviewElement=$('#'+elementId);if(!overviewElement.length)return;overviewElement.find('div>table>tbody>tr>td img.e10-auto-reload').each(function(){var
idd=new
Date().valueOf();var
url=$(this).attr('data-src')+'?xyz='+idd;$(this).attr('src',url);});var
alertsElement=$('#e10-lan-alerts');alertsElement.find('>div img.e10-auto-reload').each(function(){var
idd=new
Date().valueOf();var
url=$(this).attr('data-src')+'?xyz='+idd;$(this).attr('src',url);});};e10client.prototype.widgets.macLan.reloadAlerts=function(){if(e10.widgets.macLan.alertsTimer){clearTimeout(e10.widgets.macLan.alertsTimer);}var
urlPath="/api/objects/call/mac-lan-alerts-download";e10.server.get(urlPath,function(data){e10.widgets.macLan.setAlerts(data);});e10.widgets.macLan.alertsTimer=setTimeout(e10.widgets.macLan.reloadAlerts,65000);};e10client.prototype.widgets.macLan.setAlerts=function(data){var
alertsElement=$('#e10-lan-alerts');alertsElement.find('>div').each(function(){var
scopeId=$(this).attr('data-scope-id');var
scopeElement=$('#e10-lan-alerts-'+scopeId);var
scopeContentElement=scopeElement.find('details>div.content');var
scopeBadgesElement=scopeElement.find('details>summary');if(!data.hasOwnProperty('lanAlerts')||data['lanAlerts']['scopes']==null||data['lanAlerts']['scopes'][scopeId]===undefined){scopeBadgesElement.html('');scopeContentElement.html('');scopeBadgesElement.parent().hide();return;}var
scope=data.lanAlerts.scopes[scopeId];scopeBadgesElement.html(scope['badges']);scopeContentElement.html(scope['content']);scopeBadgesElement.parent().show();});};e10client.prototype.widgets.macVs={camerasTimer:0,widgetId:'',gridType:'',gridMode:'',smartActiveCamera:'',smartMainElement:null,archiveDate:'',archiveHour:'',localServers:null};e10client.prototype.widgets.macVs.init=function(elementId,localServers){e10.widgets.macVs.gridType=$('#e10-widget-vs-type').val();e10.widgets.macVs.widgetId=elementId;e10.widgets.macVs.localServers=localServers;$('img.e10-camp, #e10-vs-smart-main-img').error(function(){this.src=httpApiRootPath+'/www-root/sc/shipard/ph-image-1920-1080-error.svg';});$('img.e10-camp, #e10-vs-smart-main-img').load(function(){$(this).attr('data-load-in-progress','0');});e10.widgets.macVs.smartMainElement=$('#e10-vs-smart-main');e10.widgets.macVs.smartActiveCamera=e10.widgets.macVs.smartMainElement.attr('data-active-cam');if(e10.widgets.macVs.gridType==='videoArchive')e10.widgets.macVs.initArchive();else
e10.widgets.macVs.reloadLive();};e10client.prototype.widgets.macVs.reloadLive=function(){if(e10.widgets.macVs.camerasTimer){clearTimeout(e10.widgets.macVs.camerasTimer);}for(var
si
in
e10.widgets.macVs.localServers){var
ws=e10.widgets.macVs.localServers[si];var
camUrl=ws.camerasURL;var
urlPath=ws.camerasURL+"/cameras?callback=?";var
jqxhr=$.getJSON(urlPath,function(data){var
errorMsgElement=$('#e10-widget-vs-error');errorMsgElement.css({'display':'none'});var
cntSuccess=0;for(var
ii
in
data){if(!data[ii].image)continue;var
imgElement=$('#e10-camp-'+ii);if(imgElement.length===0)continue;var
picFolder=imgElement.attr('data-folder');var
picFileName=camUrl+'imgs/-w960/-q70/'+picFolder+"/"+data[ii].image;var
origPicFileName=camUrl+'/imgs/'+picFolder+"/"+data[ii].image;var
cameraId=imgElement.attr('data-camera');if(imgElement.attr('data-load-in-progress')==='1')continue;imgElement.attr('data-load-in-progress','1');imgElement.attr("src",picFileName).parent().attr("data-pict",origPicFileName);if(e10.widgets.macVs.smartMainElement!==null&&cameraId===e10.widgets.macVs.smartActiveCamera){if($('#e10-vs-smart-main-img').attr('data-load-in-progress')!=='1'){$('#e10-vs-smart-main-img').attr('data-load-in-progress','1');$('#e10-vs-smart-main-img').attr('src',origPicFileName);}}if(data[ii].error)imgElement.addClass('e10-error');else
imgElement.removeClass('e10-error');cntSuccess++;}if(!cntSuccess){e10.widgets.macVs.camerasTimer=0;return;}e10.widgets.macVs.camerasTimer=setTimeout(e10.widgets.macVs.reloadLive,3000);}).error(function(){var
errorMsgElement=$('#e10-widget-vs-error');errorMsgElement.css({'display':'flex'});e10.widgets.macVs.camerasTimer=setTimeout(e10.widgets.macVs.reloadLive,10000);});}};e10client.prototype.widgets.macVs.initArchive=function(){var
widget=$('#'+e10.widgets.macVs.widgetId);var
inputDate=widget.find('input[name="e10-widget-vs-day"]');var
inputHour=widget.find('input[name="e10-widget-vs-day"]');e10.widgets.macVs.archiveDate=inputDate.val();e10.widgets.macVs.archiveHour=inputHour.val();e10.widgets.macVs.setVideos();};e10client.prototype.widgets.macVs.setVideos=function(){$('#'+e10.widgets.macVs.widgetId).find('div.e10-camv').each(function(){e10.widgets.macVs.setVideo($(this));});};e10client.prototype.widgets.macVs.setMainPicture=function(e){var
cameraId=e.attr('data-camera');e10.widgets.macVs.smartActiveCamera=cameraId;$('#e10-vs-smart-main-img').attr('src',e.attr('src'));$('#e10-vs-smart-main-img').parent().find('.e10-cam-sensor-display').remove();if(e.attr('data-badges-code')!==undefined){var
badges=b64DecodeUnicode(e.attr('data-badges-code'));$('#e10-vs-smart-main-img').parent().append(badges);}};e10client.prototype.widgets.macVs.setVideo=function(e){var
camUrl=e.attr('data-cam-url');var
cameraId=e.attr('data-camera');var
bfn=e.attr('data-bfn');var
videoFileName=cameraId+'-'+e10.widgets.macVs.archiveDate+'-'+e10.widgets.macVs.archiveHour+'.mp4';var
posterFileName=cameraId+'-'+e10.widgets.macVs.archiveDate+'-'+e10.widgets.macVs.archiveHour+'.jpg';var
dateSlashes=e10.widgets.macVs.archiveDate.split('-').join('/');var
videoUrl=camUrl+'cameras/video-archive/'+e10.widgets.macVs.archiveDate+'/'+bfn+'.mp4';var
posterUrl=camUrl+'cameras/video-archive/'+e10.widgets.macVs.archiveDate+'/'+bfn+'.jpg';var
c='';c+="<video controls style='width: 100%;' preload='none' poster='"+posterUrl+"' src='"+videoUrl+"'>";c+="</video>";e.empty().html(c);};e10client.prototype.widgets.cashPay={widgetId:'',mode:'pay',boxWidget:null,boxPay:null,boxDone:null,roundMethod:0,paymentMethod:1};e10client.prototype.widgets.cashPay.init=function(widgetId){if(e10.widgets.cashPay.widgetId===''){$('body').on(e10.CLICK_EVENT,".e10-cashpay-action",function(event){event.stopPropagation();event.preventDefault();e10.widgets.cashPay.action(event,$(this));});}e10.widgets.cashPay.paymentMethod=1;e10.widgets.cashPay.widgetId=widgetId;e10.widgets.cashPay.boxWidget=$('#'+widgetId);e10.widgets.cashPay.boxPay=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-pay');e10.widgets.cashPay.boxDone=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content>div.e10-wcb-done');e10.widgets.cashPay.refreshLayout();};e10client.prototype.widgets.cashPay.refreshLayout=function(){var
w=$('#e10-page-body>div.e10-widget-terminal>div.e10-widget-content');var
hh=$(window).height()-$('#e10-page-header').height();w.height(hh);};e10client.prototype.widgets.cashPay.action=function(event,e){var
action=e.attr('data-action');if(action==='change-payment-method')return e10.widgets.cashPay.changePaymentMethod(e);if(action==='change-amount')return e10.widgets.cashPay.changeAmountRequest();if(action==='cashpay-done')return e10.widgets.cashPay.done();};e10client.prototype.widgets.cashPay.changePaymentMethod=function(e){var
paymentMethod=e.attr('data-pay-method');e10.widgets.cashPay.paymentMethod=parseInt(paymentMethod);if(e10.widgets.cashPay.paymentMethod===2)e10.widgets.cashPay.roundMethod=0;else
e10.widgets.cashPay.roundMethod=parseInt(e10.widgets.cashPay.boxWidget.attr('data-roundmethod'));e.parent().find('.active').removeClass('active');e.addClass('active');};e10client.prototype.widgets.cashPay.changeAmountRequest=function(e){e10.form.getNumber({title:'Zadejte částku k úhradě',subtitle:'fff',srcElement:e,success:e10.widgets.cashPay.changeAmount});};e10client.prototype.widgets.cashPay.changeAmount=function(e){e10.form.getNumberClose();var
newAmount=e10.parseFloat(e10.form.gnValue);var
newAmountStr=e10.nf(newAmount,0);e10.widgets.cashPay.boxWidget.find('#e10-widget-cashpay-display').html(newAmountStr).attr('data-amount',newAmount);};e10client.prototype.widgets.cashPay.setMode=function(mode){if(mode==='pay'){e10.widgets.cashPay.boxDone.hide();e10.widgets.cashPay.boxPay.show();}else
if(mode==='done'){e10.widgets.cashPay.boxPay.hide();e10.widgets.cashPay.boxDone.show();}e10.widgets.cashPay.mode=mode;};e10client.prototype.widgets.cashPay.done=function(){e10.widgets.cashPay.setDoneStatus('sending');e10.widgets.cashPay.setMode('done');var
person=parseInt(e10.widgets.cashPay.boxWidget.attr('data-person'));var
amount=e10.widgets.cashPay.boxWidget.find('#e10-widget-cashpay-display').attr('data-amount');var
paymentMethod=e10.widgets.cashPay.paymentMethod;var
url='/api/objects/call/e10-finance-cashpay/'+person+'/'+amount+'/'+paymentMethod;var
data={};e10.server.post(url,data,function(data){e10.widgets.cashPay.setDoneStatus('success');},function(data){e10.widgets.cashPay.setDoneStatus('error');});};e10client.prototype.widgets.cashPay.setDoneStatus=function(status){var
statusMsg=e10.widgets.cashPay.boxDone.find('>.done-status');var
statusButtons=e10.widgets.cashPay.boxDone.find('>.done-buttons');if(status==='sending'){statusMsg.text('vyčkejte prosím, dokud se účtenka nezpracuje');statusButtons.hide();}else
if(status==='success'){statusMsg.text('hotovo');e10.loadPage('/');}else
if(status==='error'){statusMsg.text('zpracování účtenky bohužel selhalo');statusButtons.show();}};var
e10=null;$(function(){e10=new
e10client();e10.init();e10.refreshLayout();if(window['g_UserInfo']!==undefined)e10.userInfo=g_UserInfo;e10.deviceId=e10.options.get('deviceId',null);if(e10.deviceId===null){for(var
c='';c.length<32;)c+=Math.random().toString(36).substr(2,1);e10.deviceId=c;e10.options.set('deviceId',e10.deviceId);}e10.options.loadAppSettings();e10.server.setHttpServerRoot(httpApiRootPath);if(typeof
g_initDataPath!=='undefined'&&window['g_UserInfo']!==undefined&&g_initDataPath!==''){e10.loadPage(g_initDataPath);}e10.wss.init();if('serviceWorker'in
navigator&&e10ServiceWorkerURL!==undefined){navigator.serviceWorker.register(e10ServiceWorkerURL).then(function(reg){}).catch(function(err){console.log("Service worker registration error: ",err)});}});e10client.prototype.appLogout=function(dataPath,successFunction){var
url=e10.httpServerRoot+'/user/logout-check?m=1';window.location=url;};e10client.prototype.systemInfo=function(withIds){if(withIds){var
info={};info['appVersion']=e10.appVersion;info['userAgent']=navigator.userAgent;return info;}var
info=[];info.push({title:'ID zařízení',value:e10.deviceId});info.push({title:'Prohlížeč',value:navigator.userAgent});return info;};e10client.prototype.form.uploadFiles=function(form,table,pk){};e10client.prototype.camera={addPhotoTable:'',addPhotoPK:'',addPhotoInputId:''};e10client.prototype.camera.takePhoto=function(e){};e10client.prototype.camera.takeFile=function(e){};function
e10SumTableExpandedCellClick(element,event){var
icon=element.find('>i');var
tableCell=element.parent();var
tableRow=tableCell.parent();var
sumTableContainer=searchObjectAttr(tableRow,'data-object-class-id');if(element.hasClass('expandable')){element.removeClass('expandable').removeClass('expanded');icon.removeClass('fa-plus-square-o').addClass('fa-minus-square-o');var
requestParams={};requestParams['expanded-id']=tableRow.attr('data-exp-this-id');requestParams['object-class-id']=sumTableContainer.attr('data-object-class-id');requestParams['query-params']={};requestParams['level']=element.attr('data-next-level');elementPrefixedAttributes(sumTableContainer,'data-query-',requestParams['query-params']);elementPrefixedAttributes(tableCell,'data-query-',requestParams['query-params']);elementPrefixedAttributes(element,'data-query-',requestParams['query-params']);e10.server.api(requestParams,function(data){$(data['rowsHtmlCode']).insertAfter(tableRow);});}else{element.addClass('expandable').removeClass('expanded');icon.removeClass('fa-minus-square-o').addClass('fa-plus-square-o');var
selector=">tr[data-exp-parent-id^='"+tableRow.attr('data-exp-this-id')+"']";tableRow.parent().find(selector).each(function(){$(this).detach();});}}function
e10SumTableSelectRow(element,event){if(element.hasClass('active')){return;}element.parent().find('>tr.active').removeClass('active');element.addClass('active');var
input=element.parent().parent().parent().find('>input:first');input.val(element.attr('data-selectable-row-id'));}// @preserve jQuery.floatThead 1.2.9dev - http://mkoryak.github.io/floatThead/ - Copyright (c) 2012 - 2014 Misha Koryak
// @license MIT
!function(a){function b(a,b,c){if(8==g){var d=j.width(),e=f.debounce(function(){var a=j.width();d!=a&&(d=a,c())},a);j.on(b,e)}else j.on(b,f.debounce(c,a))}function c(a){window.console&&window.console&&window.console.log&&window.console.log(a)}function d(){var b=a('<div style="width:50px;height:50px;overflow-y:scroll;position:absolute;top:-200px;left:-200px;"><div style="height:100px;width:100%"></div>');a("body").append(b);var c=b.innerWidth(),d=a("div",b).innerWidth();return b.remove(),c-d}function e(a){if(a.dataTableSettings)for(var b=0;b<a.dataTableSettings.length;b++){var c=a.dataTableSettings[b].nTable;if(a[0]==c)return!0}return!1}a.floatThead=a.floatThead||{},a.floatThead.defaults={cellTag:null,headerCellSelector:"tr:first>th:visible",zIndex:1001,debounceResizeMs:10,useAbsolutePositioning:!0,scrollingTop:0,scrollingBottom:0,scrollContainer:function(){return a([])},getSizingRow:function(a){return a.find("tbody tr:visible:first>*:visible")},floatTableClass:"floatThead-table",floatWrapperClass:"floatThead-wrapper",floatContainerClass:"floatThead-container",copyTableClass:!0,debug:!1};var f=window._,g=function(){for(var a=3,b=document.createElement("b"),c=b.all||[];a=1+a,b.innerHTML="<!--[if gt IE "+a+"]><i><![endif]-->",c[0];);return a>4?a:document.documentMode}(),h=null,i=function(){if(g)return!1;var b=a("<table><colgroup><col></colgroup><tbody><tr><td style='width:10px'></td></tbody></table>");a("body").append(b);var c=b.find("col").width();return b.remove(),0==c},j=a(window),k=0;a.fn.floatThead=function(l){if(l=l||{},!f&&(f=window._||a.floatThead._,!f))throw new Error("jquery.floatThead-slim.js requires underscore. You should use the non-lite version since you do not have underscore.");if(8>g)return this;if(null==h&&(h=i(),h&&(document.createElement("fthtr"),document.createElement("fthtd"),document.createElement("fthfoot"))),f.isString(l)){var m=l,n=this;return this.filter("table").each(function(){var b=a(this).data("floatThead-attached");if(b&&f.isFunction(b[m])){var c=b[m]();"undefined"!=typeof c&&(n=c)}}),n}var o=a.extend({},a.floatThead.defaults||{},l);return a.each(l,function(b){b in a.floatThead.defaults||!o.debug||c("jQuery.floatThead: used ["+b+"] key to init plugin, but that param is not an option for the plugin. Valid options are: "+f.keys(a.floatThead.defaults).join(", "))}),this.filter(":not(."+o.floatTableClass+")").each(function(){function c(a){return a+".fth-"+y+".floatTHead"}function i(){var b=0;A.find("tr:visible").each(function(){b+=a(this).outerHeight(!0)}),Z.outerHeight(b),$.outerHeight(b)}function l(){var a=z.outerWidth(),b=I.width()||a,c="hidden"!=I.css("overflow-y")?b-F.vertical:b;if(X.width(c),O){var d=100*a/c;S.css("width",d+"%")}else S.outerWidth(a)}function m(){C=(f.isFunction(o.scrollingTop)?o.scrollingTop(z):o.scrollingTop)||0,D=(f.isFunction(o.scrollingBottom)?o.scrollingBottom(z):o.scrollingBottom)||0}function n(){var b,c;if(V)b=U.find("col").length;else{var d;if(d=null==o.cellTag&&o.headerCellSelector?o.headerCellSelector:"tr:first>"+o.cellTag,f.isNumber(d))return d;c=A.find(d),b=0,c.each(function(){b+=parseInt(a(this).attr("colspan")||1,10)})}if(b!=H){H=b;for(var e=[],g=[],i=[],j=0;b>j;j++)e.push('<th class="floatThead-col"/>'),g.push("<col/>"),i.push("<fthtd style='display:table-cell;height:0;width:auto;'/>");g=g.join(""),e=e.join(""),h&&(i=i.join(""),W.html(i),bb=W.find("fthtd")),Z.html(e),$=Z.find("th"),V||U.html(g),_=U.find("col"),T.html(g),ab=T.find("col")}return b}function p(){if(!E){if(E=!0,J){var a=z.width(),b=Q.width();a>b&&z.css("minWidth",a)}z.css(db),S.css(db),S.append(A),B.before(Y),i()}}function q(){E&&(E=!1,J&&z.width(fb),Y.detach(),z.prepend(A),z.css(eb),S.css(eb),z.css("minWidth",gb),z.css("minWidth",z.width()))}function r(a){J!=a&&(J=a,X.css({position:J?"absolute":"fixed"}))}function s(a,b,c,d){return h?c:d?o.getSizingRow(a,b,c):b}function t(){var a,b=n();return function(){var c=s(z,_,bb,g);if(c.length==b&&b>0){if(!V)for(a=0;b>a;a++)_.eq(a).css("width","");q();var d=[];for(a=0;b>a;a++)d[a]=c.get(a).offsetWidth;for(a=0;b>a;a++)ab.eq(a).width(d[a]),_.eq(a).width(d[a]);p()}else S.append(A),z.css(eb),S.css(eb),i()}}function u(a){var b=I.css("border-"+a+"-width"),c=0;return b&&~b.indexOf("px")&&(c=parseInt(b,10)),c}function v(){var a,b=I.scrollTop(),c=0,d=L?K.outerHeight(!0):0,e=M?d:-d,f=X.height(),g=z.offset(),i=0;if(O){var k=I.offset();c=g.top-k.top+b,L&&M&&(c+=d),c-=u("top"),i=u("left")}else a=g.top-C-f+D+F.horizontal;var l=j.scrollTop(),m=j.scrollLeft(),n=I.scrollLeft();return b=I.scrollTop(),function(k){if("windowScroll"==k?(l=j.scrollTop(),m=j.scrollLeft()):"containerScroll"==k?(b=I.scrollTop(),n=I.scrollLeft()):"init"!=k&&(l=j.scrollTop(),m=j.scrollLeft(),b=I.scrollTop(),n=I.scrollLeft()),!h||!(0>l||0>m)){if(R)r("windowScrollDone"==k?!0:!1);else if("windowScrollDone"==k)return null;g=z.offset(),L&&M&&(g.top+=d);var o,s,t=z.outerHeight();if(O&&J){if(c>=b){var u=c-b;o=u>0?u:0}else o=P?0:b;s=i}else!O&&J?(l>a+t+e?o=t-f+e:g.top>l+C?(o=0,q()):(o=C+l-g.top+c+(M?d:0),p()),s=0):O&&!J?(c>b||b-c>t?(o=g.top-l,q()):(o=g.top+b-l-c,p()),s=g.left+n-m):O||J||(l>a+t+e?o=t+C-l+a+e:g.top>l+C?(o=g.top-l,p()):o=C,s=g.left-m);return{top:o,left:s}}}}function w(){var a=null,b=null,c=null;return function(d,e,f){null==d||a==d.top&&b==d.left||(X.css({top:d.top,left:d.left}),a=d.top,b=d.left),e&&l(),f&&i();var g=I.scrollLeft();J&&c==g||(X.scrollLeft(g),c=g)}}function x(){if(I.length){var a=I.width(),b=I.height(),c=z.height(),d=z.width(),e=d>a?G:0,f=c>b?G:0;F.horizontal=d>a-f?G:0,F.vertical=c>b-e?G:0}}var y=k,z=a(this);if(z.data("floatThead-attached"))return!0;if(!z.is("table"))throw new Error('jQuery.floatThead must be run on a table element. ex: $("table").floatThead();');var A=z.find("thead:first"),B=z.find("tbody:first");if(0==A.length)throw new Error("jQuery.floatThead must be run on a table that contains a <thead> element");var C,D,E=!1,F={vertical:0,horizontal:0},G=d(),H=0,I=o.scrollContainer(z)||a([]),J=o.useAbsolutePositioning;null==J&&(J=o.scrollContainer(z).length);var K=z.find("caption"),L=1==K.length;if(L)var M="top"===(K.css("caption-side")||K.attr("align")||"top");var N=a('<fthfoot style="display:table-footer-group;"/>'),O=I.length>0,P=!1,Q=a([]),R=9>=g&&!O&&J,S=a("<table/>"),T=a("<colgroup/>"),U=z.find("colgroup:first"),V=!0;0==U.length&&(U=a("<colgroup/>"),V=!1);var W=a('<fthrow style="display:table-row;height:0;"/>'),X=a('<div style="overflow: hidden;"></div>'),Y=a("<thead/>"),Z=a('<tr class="size-row"/>'),$=a([]),_=a([]),ab=a([]),bb=a([]);if(Y.append(Z),z.prepend(U),h&&(N.append(W),z.append(N)),S.append(T),X.append(S),o.copyTableClass&&S.attr("class",z.attr("class")),S.attr({cellpadding:z.attr("cellpadding"),cellspacing:z.attr("cellspacing"),border:z.attr("border")}),S.css({borderCollapse:z.css("borderCollapse"),border:z.css("border")}),S.addClass(o.floatTableClass).css("margin",0),J){var cb=function(a,b){var c=a.css("position"),d="relative"==c||"absolute"==c;if(!d||b){var e={paddingLeft:a.css("paddingLeft"),paddingRight:a.css("paddingRight")};X.css(e),a=a.wrap("<div class='"+o.floatWrapperClass+"' style='position: relative; clear:both;'></div>").parent(),P=!0}return a};O?(Q=cb(I,!0),Q.append(X)):(Q=cb(z),z.after(X))}else z.after(X);X.css({position:J?"absolute":"fixed",marginTop:0,top:J?0:"auto",zIndex:o.zIndex}),X.addClass(o.floatContainerClass),m();var db={"table-layout":"fixed"},eb={"table-layout":z.css("tableLayout")||"auto"},fb=z[0].style.width||"",gb=z.css("minWidth")||"";x();var hb,ib=function(){(hb=t())()};ib();var jb=v(),kb=w();kb(jb("init"),!0);var lb=f.debounce(function(){kb(jb("windowScrollDone"),!1)},300),mb=function(){kb(jb("windowScroll"),!1),lb()},nb=function(){kb(jb("containerScroll"),!1)},ob=function(){m(),x(),ib(),jb=v(),(kb=w())(jb("resize"),!0,!0)},pb=f.debounce(function(){x(),m(),ib(),jb=v(),kb(jb("reflow"),!0)},1);O?J?I.on(c("scroll"),nb):(I.on(c("scroll"),nb),j.on(c("scroll"),mb)):j.on(c("scroll"),mb),j.on(c("load"),pb),b(o.debounceResizeMs,c("resize"),ob),z.on("reflow",pb),e(z)&&z.on("filter",pb).on("sort",pb).on("page",pb),z.data("floatThead-attached",{destroy:function(){var a=".fth-"+y;q(),z.css(eb),U.remove(),h&&N.remove(),Y.parent().length&&Y.replaceWith(A),z.off("reflow"),I.off(a),P&&(I.length?I.unwrap():z.unwrap()),z.css("minWidth",gb),X.remove(),z.data("floatThead-attached",!1),j.off(a)},reflow:function(){pb()},setHeaderHeight:function(){i()},getFloatContainer:function(){return X},getRowGroups:function(){return E?X.find("thead").add(z.find("tbody,tfoot")):z.find("thead,tbody,tfoot")}}),k++}),this}}(jQuery),function(a){a.floatThead=a.floatThead||{},a.floatThead._=window._||function(){var b={},c=Object.prototype.hasOwnProperty,d=["Arguments","Function","String","Number","Date","RegExp"];return b.has=function(a,b){return c.call(a,b)},b.keys=function(a){if(a!==Object(a))throw new TypeError("Invalid object");var c=[];for(var d in a)b.has(a,d)&&c.push(d);return c},a.each(d,function(){var a=this;b["is"+a]=function(b){return Object.prototype.toString.call(b)=="[object "+a+"]"}}),b.debounce=function(a,b,c){var d,e,f,g,h;return function(){f=this,e=arguments,g=new Date;var i=function(){var j=new Date-g;b>j?d=setTimeout(i,b-j):(d=null,c||(h=a.apply(f,e)))},j=c&&!d;return d||(d=setTimeout(i,b)),j&&(h=a.apply(f,e)),h}},b}()}(jQuery);
/*!
 * Bootstrap v3.3.5 (http://getbootstrap.com)
 * Copyright 2011-2015 Twitter, Inc.
 * Licensed under MIT (https://github.com/twbs/bootstrap/blob/master/LICENSE)
 */

/*!
 * Generated using the Bootstrap Customizer (http://getbootstrap.com/customize/?id=67d0b8f193562c451bcb)
 * Config saved to config.json and https://gist.github.com/67d0b8f193562c451bcb
 */
if("undefined"==typeof jQuery)throw new Error("Bootstrap's JavaScript requires jQuery");+function(t){"use strict";var e=t.fn.jquery.split(" ")[0].split(".");if(e[0]<2&&e[1]<9||1==e[0]&&9==e[1]&&e[2]<1)throw new Error("Bootstrap's JavaScript requires jQuery version 1.9.1 or higher")}(jQuery),+function(t){"use strict";function e(e){var o=e.attr("data-target");o||(o=e.attr("href"),o=o&&/#[A-Za-z]/.test(o)&&o.replace(/.*(?=#[^\s]*$)/,""));var i=o&&t(o);return i&&i.length?i:e.parent()}function o(o){o&&3===o.which||(t(n).remove(),t(s).each(function(){var i=t(this),n=e(i),s={relatedTarget:this};n.hasClass("open")&&(o&&"click"==o.type&&/input|textarea/i.test(o.target.tagName)&&t.contains(n[0],o.target)||(n.trigger(o=t.Event("hide.bs.dropdown",s)),o.isDefaultPrevented()||(i.attr("aria-expanded","false"),n.removeClass("open").trigger("hidden.bs.dropdown",s))))}))}function i(e){return this.each(function(){var o=t(this),i=o.data("bs.dropdown");i||o.data("bs.dropdown",i=new r(this)),"string"==typeof e&&i[e].call(o)})}var n=".dropdown-backdrop",s='[data-toggle="dropdown"]',r=function(e){t(e).on("click.bs.dropdown",this.toggle)};r.VERSION="3.3.5",r.prototype.toggle=function(i){var n=t(this);if(!n.is(".disabled, :disabled")){var s=e(n),r=s.hasClass("open");if(o(),!r){"ontouchstart"in document.documentElement&&!s.closest(".navbar-nav").length&&t(document.createElement("div")).addClass("dropdown-backdrop").insertAfter(t(this)).on("click",o);var a={relatedTarget:this};if(s.trigger(i=t.Event("show.bs.dropdown",a)),i.isDefaultPrevented())return;n.trigger("focus").attr("aria-expanded","true"),s.toggleClass("open").trigger("shown.bs.dropdown",a)}return!1}},r.prototype.keydown=function(o){if(/(38|40|27|32)/.test(o.which)&&!/input|textarea/i.test(o.target.tagName)){var i=t(this);if(o.preventDefault(),o.stopPropagation(),!i.is(".disabled, :disabled")){var n=e(i),r=n.hasClass("open");if(!r&&27!=o.which||r&&27==o.which)return 27==o.which&&n.find(s).trigger("focus"),i.trigger("click");var a=" li:not(.disabled):visible a",l=n.find(".dropdown-menu"+a);if(l.length){var h=l.index(o.target);38==o.which&&h>0&&h--,40==o.which&&h<l.length-1&&h++,~h||(h=0),l.eq(h).trigger("focus")}}}};var a=t.fn.dropdown;t.fn.dropdown=i,t.fn.dropdown.Constructor=r,t.fn.dropdown.noConflict=function(){return t.fn.dropdown=a,this},t(document).on("click.bs.dropdown.data-api",o).on("click.bs.dropdown.data-api",".dropdown form",function(t){t.stopPropagation()}).on("click.bs.dropdown.data-api",s,r.prototype.toggle).on("keydown.bs.dropdown.data-api",s,r.prototype.keydown).on("keydown.bs.dropdown.data-api",".dropdown-menu",r.prototype.keydown)}(jQuery),+function(t){"use strict";function e(e,i){return this.each(function(){var n=t(this),s=n.data("bs.modal"),r=t.extend({},o.DEFAULTS,n.data(),"object"==typeof e&&e);s||n.data("bs.modal",s=new o(this,r)),"string"==typeof e?s[e](i):r.show&&s.show(i)})}var o=function(e,o){this.options=o,this.$body=t(document.body),this.$element=t(e),this.$dialog=this.$element.find(".modal-dialog"),this.$backdrop=null,this.isShown=null,this.originalBodyPad=null,this.scrollbarWidth=0,this.ignoreBackdropClick=!1,this.options.remote&&this.$element.find(".modal-content").load(this.options.remote,t.proxy(function(){this.$element.trigger("loaded.bs.modal")},this))};o.VERSION="3.3.5",o.TRANSITION_DURATION=300,o.BACKDROP_TRANSITION_DURATION=150,o.DEFAULTS={backdrop:!0,keyboard:!0,show:!0},o.prototype.toggle=function(t){return this.isShown?this.hide():this.show(t)},o.prototype.show=function(e){var i=this,n=t.Event("show.bs.modal",{relatedTarget:e});this.$element.trigger(n),this.isShown||n.isDefaultPrevented()||(this.isShown=!0,this.checkScrollbar(),this.setScrollbar(),this.$body.addClass("modal-open"),this.escape(),this.resize(),this.$element.on("click.dismiss.bs.modal",'[data-dismiss="modal"]',t.proxy(this.hide,this)),this.$dialog.on("mousedown.dismiss.bs.modal",function(){i.$element.one("mouseup.dismiss.bs.modal",function(e){t(e.target).is(i.$element)&&(i.ignoreBackdropClick=!0)})}),this.backdrop(function(){var n=t.support.transition&&i.$element.hasClass("fade");i.$element.parent().length||i.$element.appendTo(i.$body),i.$element.show().scrollTop(0),i.adjustDialog(),n&&i.$element[0].offsetWidth,i.$element.addClass("in"),i.enforceFocus();var s=t.Event("shown.bs.modal",{relatedTarget:e});n?i.$dialog.one("bsTransitionEnd",function(){i.$element.trigger("focus").trigger(s)}).emulateTransitionEnd(o.TRANSITION_DURATION):i.$element.trigger("focus").trigger(s)}))},o.prototype.hide=function(e){e&&e.preventDefault(),e=t.Event("hide.bs.modal"),this.$element.trigger(e),this.isShown&&!e.isDefaultPrevented()&&(this.isShown=!1,this.escape(),this.resize(),t(document).off("focusin.bs.modal"),this.$element.removeClass("in").off("click.dismiss.bs.modal").off("mouseup.dismiss.bs.modal"),this.$dialog.off("mousedown.dismiss.bs.modal"),t.support.transition&&this.$element.hasClass("fade")?this.$element.one("bsTransitionEnd",t.proxy(this.hideModal,this)).emulateTransitionEnd(o.TRANSITION_DURATION):this.hideModal())},o.prototype.enforceFocus=function(){t(document).off("focusin.bs.modal").on("focusin.bs.modal",t.proxy(function(t){this.$element[0]===t.target||this.$element.has(t.target).length||this.$element.trigger("focus")},this))},o.prototype.escape=function(){this.isShown&&this.options.keyboard?this.$element.on("keydown.dismiss.bs.modal",t.proxy(function(t){27==t.which&&this.hide()},this)):this.isShown||this.$element.off("keydown.dismiss.bs.modal")},o.prototype.resize=function(){this.isShown?t(window).on("resize.bs.modal",t.proxy(this.handleUpdate,this)):t(window).off("resize.bs.modal")},o.prototype.hideModal=function(){var t=this;this.$element.hide(),this.backdrop(function(){t.$body.removeClass("modal-open"),t.resetAdjustments(),t.resetScrollbar(),t.$element.trigger("hidden.bs.modal")})},o.prototype.removeBackdrop=function(){this.$backdrop&&this.$backdrop.remove(),this.$backdrop=null},o.prototype.backdrop=function(e){var i=this,n=this.$element.hasClass("fade")?"fade":"";if(this.isShown&&this.options.backdrop){var s=t.support.transition&&n;if(this.$backdrop=t(document.createElement("div")).addClass("modal-backdrop "+n).appendTo(this.$body),this.$element.on("click.dismiss.bs.modal",t.proxy(function(t){return this.ignoreBackdropClick?void(this.ignoreBackdropClick=!1):void(t.target===t.currentTarget&&("static"==this.options.backdrop?this.$element[0].focus():this.hide()))},this)),s&&this.$backdrop[0].offsetWidth,this.$backdrop.addClass("in"),!e)return;s?this.$backdrop.one("bsTransitionEnd",e).emulateTransitionEnd(o.BACKDROP_TRANSITION_DURATION):e()}else if(!this.isShown&&this.$backdrop){this.$backdrop.removeClass("in");var r=function(){i.removeBackdrop(),e&&e()};t.support.transition&&this.$element.hasClass("fade")?this.$backdrop.one("bsTransitionEnd",r).emulateTransitionEnd(o.BACKDROP_TRANSITION_DURATION):r()}else e&&e()},o.prototype.handleUpdate=function(){this.adjustDialog()},o.prototype.adjustDialog=function(){var t=this.$element[0].scrollHeight>document.documentElement.clientHeight;this.$element.css({paddingLeft:!this.bodyIsOverflowing&&t?this.scrollbarWidth:"",paddingRight:this.bodyIsOverflowing&&!t?this.scrollbarWidth:""})},o.prototype.resetAdjustments=function(){this.$element.css({paddingLeft:"",paddingRight:""})},o.prototype.checkScrollbar=function(){var t=window.innerWidth;if(!t){var e=document.documentElement.getBoundingClientRect();t=e.right-Math.abs(e.left)}this.bodyIsOverflowing=document.body.clientWidth<t,this.scrollbarWidth=this.measureScrollbar()},o.prototype.setScrollbar=function(){var t=parseInt(this.$body.css("padding-right")||0,10);this.originalBodyPad=document.body.style.paddingRight||"",this.bodyIsOverflowing&&this.$body.css("padding-right",t+this.scrollbarWidth)},o.prototype.resetScrollbar=function(){this.$body.css("padding-right",this.originalBodyPad)},o.prototype.measureScrollbar=function(){var t=document.createElement("div");t.className="modal-scrollbar-measure",this.$body.append(t);var e=t.offsetWidth-t.clientWidth;return this.$body[0].removeChild(t),e};var i=t.fn.modal;t.fn.modal=e,t.fn.modal.Constructor=o,t.fn.modal.noConflict=function(){return t.fn.modal=i,this},t(document).on("click.bs.modal.data-api",'[data-toggle="modal"]',function(o){var i=t(this),n=i.attr("href"),s=t(i.attr("data-target")||n&&n.replace(/.*(?=#[^\s]+$)/,"")),r=s.data("bs.modal")?"toggle":t.extend({remote:!/#/.test(n)&&n},s.data(),i.data());i.is("a")&&o.preventDefault(),s.one("show.bs.modal",function(t){t.isDefaultPrevented()||s.one("hidden.bs.modal",function(){i.is(":visible")&&i.trigger("focus")})}),e.call(s,r,this)})}(jQuery),+function(t){"use strict";function e(e){return this.each(function(){var i=t(this),n=i.data("bs.tooltip"),s="object"==typeof e&&e;(n||!/destroy|hide/.test(e))&&(n||i.data("bs.tooltip",n=new o(this,s)),"string"==typeof e&&n[e]())})}var o=function(t,e){this.type=null,this.options=null,this.enabled=null,this.timeout=null,this.hoverState=null,this.$element=null,this.inState=null,this.init("tooltip",t,e)};o.VERSION="3.3.5",o.TRANSITION_DURATION=150,o.DEFAULTS={animation:!0,placement:"top",selector:!1,template:'<div class="tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>',trigger:"hover focus",title:"",delay:0,html:!1,container:!1,viewport:{selector:"body",padding:0}},o.prototype.init=function(e,o,i){if(this.enabled=!0,this.type=e,this.$element=t(o),this.options=this.getOptions(i),this.$viewport=this.options.viewport&&t(t.isFunction(this.options.viewport)?this.options.viewport.call(this,this.$element):this.options.viewport.selector||this.options.viewport),this.inState={click:!1,hover:!1,focus:!1},this.$element[0]instanceof document.constructor&&!this.options.selector)throw new Error("`selector` option must be specified when initializing "+this.type+" on the window.document object!");for(var n=this.options.trigger.split(" "),s=n.length;s--;){var r=n[s];if("click"==r)this.$element.on("click."+this.type,this.options.selector,t.proxy(this.toggle,this));else if("manual"!=r){var a="hover"==r?"mouseenter":"focusin",l="hover"==r?"mouseleave":"focusout";this.$element.on(a+"."+this.type,this.options.selector,t.proxy(this.enter,this)),this.$element.on(l+"."+this.type,this.options.selector,t.proxy(this.leave,this))}}this.options.selector?this._options=t.extend({},this.options,{trigger:"manual",selector:""}):this.fixTitle()},o.prototype.getDefaults=function(){return o.DEFAULTS},o.prototype.getOptions=function(e){return e=t.extend({},this.getDefaults(),this.$element.data(),e),e.delay&&"number"==typeof e.delay&&(e.delay={show:e.delay,hide:e.delay}),e},o.prototype.getDelegateOptions=function(){var e={},o=this.getDefaults();return this._options&&t.each(this._options,function(t,i){o[t]!=i&&(e[t]=i)}),e},o.prototype.enter=function(e){var o=e instanceof this.constructor?e:t(e.currentTarget).data("bs."+this.type);return o||(o=new this.constructor(e.currentTarget,this.getDelegateOptions()),t(e.currentTarget).data("bs."+this.type,o)),e instanceof t.Event&&(o.inState["focusin"==e.type?"focus":"hover"]=!0),o.tip().hasClass("in")||"in"==o.hoverState?void(o.hoverState="in"):(clearTimeout(o.timeout),o.hoverState="in",o.options.delay&&o.options.delay.show?void(o.timeout=setTimeout(function(){"in"==o.hoverState&&o.show()},o.options.delay.show)):o.show())},o.prototype.isInStateTrue=function(){for(var t in this.inState)if(this.inState[t])return!0;return!1},o.prototype.leave=function(e){var o=e instanceof this.constructor?e:t(e.currentTarget).data("bs."+this.type);return o||(o=new this.constructor(e.currentTarget,this.getDelegateOptions()),t(e.currentTarget).data("bs."+this.type,o)),e instanceof t.Event&&(o.inState["focusout"==e.type?"focus":"hover"]=!1),o.isInStateTrue()?void 0:(clearTimeout(o.timeout),o.hoverState="out",o.options.delay&&o.options.delay.hide?void(o.timeout=setTimeout(function(){"out"==o.hoverState&&o.hide()},o.options.delay.hide)):o.hide())},o.prototype.show=function(){var e=t.Event("show.bs."+this.type);if(this.hasContent()&&this.enabled){this.$element.trigger(e);var i=t.contains(this.$element[0].ownerDocument.documentElement,this.$element[0]);if(e.isDefaultPrevented()||!i)return;var n=this,s=this.tip(),r=this.getUID(this.type);this.setContent(),s.attr("id",r),this.$element.attr("aria-describedby",r),this.options.animation&&s.addClass("fade");var a="function"==typeof this.options.placement?this.options.placement.call(this,s[0],this.$element[0]):this.options.placement,l=/\s?auto?\s?/i,h=l.test(a);h&&(a=a.replace(l,"")||"top"),s.detach().css({top:0,left:0,display:"block"}).addClass(a).data("bs."+this.type,this),this.options.container?s.appendTo(this.options.container):s.insertAfter(this.$element),this.$element.trigger("inserted.bs."+this.type);var p=this.getPosition(),d=s[0].offsetWidth,c=s[0].offsetHeight;if(h){var f=a,u=this.getPosition(this.$viewport);a="bottom"==a&&p.bottom+c>u.bottom?"top":"top"==a&&p.top-c<u.top?"bottom":"right"==a&&p.right+d>u.width?"left":"left"==a&&p.left-d<u.left?"right":a,s.removeClass(f).addClass(a)}var m=this.getCalculatedOffset(a,p,d,c);this.applyPlacement(m,a);var g=function(){var t=n.hoverState;n.$element.trigger("shown.bs."+n.type),n.hoverState=null,"out"==t&&n.leave(n)};t.support.transition&&this.$tip.hasClass("fade")?s.one("bsTransitionEnd",g).emulateTransitionEnd(o.TRANSITION_DURATION):g()}},o.prototype.applyPlacement=function(e,o){var i=this.tip(),n=i[0].offsetWidth,s=i[0].offsetHeight,r=parseInt(i.css("margin-top"),10),a=parseInt(i.css("margin-left"),10);isNaN(r)&&(r=0),isNaN(a)&&(a=0),e.top+=r,e.left+=a,t.offset.setOffset(i[0],t.extend({using:function(t){i.css({top:Math.round(t.top),left:Math.round(t.left)})}},e),0),i.addClass("in");var l=i[0].offsetWidth,h=i[0].offsetHeight;"top"==o&&h!=s&&(e.top=e.top+s-h);var p=this.getViewportAdjustedDelta(o,e,l,h);p.left?e.left+=p.left:e.top+=p.top;var d=/top|bottom/.test(o),c=d?2*p.left-n+l:2*p.top-s+h,f=d?"offsetWidth":"offsetHeight";i.offset(e),this.replaceArrow(c,i[0][f],d)},o.prototype.replaceArrow=function(t,e,o){this.arrow().css(o?"left":"top",50*(1-t/e)+"%").css(o?"top":"left","")},o.prototype.setContent=function(){var t=this.tip(),e=this.getTitle();t.find(".tooltip-inner")[this.options.html?"html":"text"](e),t.removeClass("fade in top bottom left right")},o.prototype.hide=function(e){function i(){"in"!=n.hoverState&&s.detach(),n.$element.removeAttr("aria-describedby").trigger("hidden.bs."+n.type),e&&e()}var n=this,s=t(this.$tip),r=t.Event("hide.bs."+this.type);return this.$element.trigger(r),r.isDefaultPrevented()?void 0:(s.removeClass("in"),t.support.transition&&s.hasClass("fade")?s.one("bsTransitionEnd",i).emulateTransitionEnd(o.TRANSITION_DURATION):i(),this.hoverState=null,this)},o.prototype.fixTitle=function(){var t=this.$element;(t.attr("title")||"string"!=typeof t.attr("data-original-title"))&&t.attr("data-original-title",t.attr("title")||"").attr("title","")},o.prototype.hasContent=function(){return this.getTitle()},o.prototype.getPosition=function(e){e=e||this.$element;var o=e[0],i="BODY"==o.tagName,n=o.getBoundingClientRect();null==n.width&&(n=t.extend({},n,{width:n.right-n.left,height:n.bottom-n.top}));var s=i?{top:0,left:0}:e.offset(),r={scroll:i?document.documentElement.scrollTop||document.body.scrollTop:e.scrollTop()},a=i?{width:t(window).width(),height:t(window).height()}:null;return t.extend({},n,r,a,s)},o.prototype.getCalculatedOffset=function(t,e,o,i){return"bottom"==t?{top:e.top+e.height,left:e.left+e.width/2-o/2}:"top"==t?{top:e.top-i,left:e.left+e.width/2-o/2}:"left"==t?{top:e.top+e.height/2-i/2,left:e.left-o}:{top:e.top+e.height/2-i/2,left:e.left+e.width}},o.prototype.getViewportAdjustedDelta=function(t,e,o,i){var n={top:0,left:0};if(!this.$viewport)return n;var s=this.options.viewport&&this.options.viewport.padding||0,r=this.getPosition(this.$viewport);if(/right|left/.test(t)){var a=e.top-s-r.scroll,l=e.top+s-r.scroll+i;a<r.top?n.top=r.top-a:l>r.top+r.height&&(n.top=r.top+r.height-l)}else{var h=e.left-s,p=e.left+s+o;h<r.left?n.left=r.left-h:p>r.right&&(n.left=r.left+r.width-p)}return n},o.prototype.getTitle=function(){var t,e=this.$element,o=this.options;return t=e.attr("data-original-title")||("function"==typeof o.title?o.title.call(e[0]):o.title)},o.prototype.getUID=function(t){do t+=~~(1e6*Math.random());while(document.getElementById(t));return t},o.prototype.tip=function(){if(!this.$tip&&(this.$tip=t(this.options.template),1!=this.$tip.length))throw new Error(this.type+" `template` option must consist of exactly 1 top-level element!");return this.$tip},o.prototype.arrow=function(){return this.$arrow=this.$arrow||this.tip().find(".tooltip-arrow")},o.prototype.enable=function(){this.enabled=!0},o.prototype.disable=function(){this.enabled=!1},o.prototype.toggleEnabled=function(){this.enabled=!this.enabled},o.prototype.toggle=function(e){var o=this;e&&(o=t(e.currentTarget).data("bs."+this.type),o||(o=new this.constructor(e.currentTarget,this.getDelegateOptions()),t(e.currentTarget).data("bs."+this.type,o))),e?(o.inState.click=!o.inState.click,o.isInStateTrue()?o.enter(o):o.leave(o)):o.tip().hasClass("in")?o.leave(o):o.enter(o)},o.prototype.destroy=function(){var t=this;clearTimeout(this.timeout),this.hide(function(){t.$element.off("."+t.type).removeData("bs."+t.type),t.$tip&&t.$tip.detach(),t.$tip=null,t.$arrow=null,t.$viewport=null})};var i=t.fn.tooltip;t.fn.tooltip=e,t.fn.tooltip.Constructor=o,t.fn.tooltip.noConflict=function(){return t.fn.tooltip=i,this}}(jQuery),+function(t){"use strict";function e(e){return this.each(function(){var i=t(this),n=i.data("bs.popover"),s="object"==typeof e&&e;(n||!/destroy|hide/.test(e))&&(n||i.data("bs.popover",n=new o(this,s)),"string"==typeof e&&n[e]())})}var o=function(t,e){this.init("popover",t,e)};if(!t.fn.tooltip)throw new Error("Popover requires tooltip.js");o.VERSION="3.3.5",o.DEFAULTS=t.extend({},t.fn.tooltip.Constructor.DEFAULTS,{placement:"right",trigger:"click",content:"",template:'<div class="popover" role="tooltip"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div></div>'}),o.prototype=t.extend({},t.fn.tooltip.Constructor.prototype),o.prototype.constructor=o,o.prototype.getDefaults=function(){return o.DEFAULTS},o.prototype.setContent=function(){var t=this.tip(),e=this.getTitle(),o=this.getContent();t.find(".popover-title")[this.options.html?"html":"text"](e),t.find(".popover-content").children().detach().end()[this.options.html?"string"==typeof o?"html":"append":"text"](o),t.removeClass("fade top bottom left right in"),t.find(".popover-title").html()||t.find(".popover-title").hide()},o.prototype.hasContent=function(){return this.getTitle()||this.getContent()},o.prototype.getContent=function(){var t=this.$element,e=this.options;return t.attr("data-content")||("function"==typeof e.content?e.content.call(t[0]):e.content)},o.prototype.arrow=function(){return this.$arrow=this.$arrow||this.tip().find(".arrow")};var i=t.fn.popover;t.fn.popover=e,t.fn.popover.Constructor=o,t.fn.popover.noConflict=function(){return t.fn.popover=i,this}}(jQuery);
