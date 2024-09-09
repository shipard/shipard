class
ShipardServer{httpServerRoot='';init(){}beginUrl(){return this.httpServerRoot;}httpHeaders(){var
headers={};headers['content-type']='application/json';return headers;}get(url,f,errorFunction,isFullUrl){var
fullUrl=this.httpServerRoot+url;if(isFullUrl)fullUrl=url;var
options={method:"GET",url:fullUrl,headers:this.httpHeaders(),};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{f(data);}).catch((error)=>{console.error("Error:",error);});}post(url,data,f,errorFunction){var
fullUrl=this.httpServerRoot+url;var
options={method:'POST',url:fullUrl,body:JSON.stringify(data),dataType:'json',headers:this.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{console.log("Success:",data);f(data);}).catch((error)=>{console.error("Error:",error);});}api(data,f,errorFunction){var
fullUrl=this.beginUrl()+'api';var
options={method:'POST',url:fullUrl,body:JSON.stringify(data),dataType:'json',headers:this.httpHeaders(),error:(errorFunction!='undefined')?errorFunction:function(data){console.log("========================ERROR: "+fullUrl);}};fetch(fullUrl,options).then((response)=>response.json()).then((data)=>{console.log("Success:",data);f(data);}).catch((error)=>{console.error("Error:",error);});}postForm(url,data,f){var
fullUrl=this.httpServerRoot+url;var
options={type:'POST',url:fullUrl,success:f,data:data,headers:this.httpHeaders(),error:function(data){console.log("========================ERROR: "+fullUrl);}};}setHttpServerRoot(httpServerRoot){this.httpServerRoot=httpServerRoot;}}class
ShipardMqtt{init(){if(typeof
Paho=='undefined')return;for(var
i
in
webSocketServers)this.startClient(i);}startClient(serverIndex){var
ws=webSocketServers[serverIndex];if(ws.fqdn===null||ws.fqdn==='')return;let
portNumber=parseInt(ws.port);if(portNumber===0)return;ws.retryTimer=0;ws.mqttClient=new
Paho.MQTT.Client(ws.fqdn,portNumber,deviceId+'-'+Math.random().toString(36));ws.mqttClient.onConnectionLost=function(xxxx){console.log(xxxx),setTimeout(()=>shc.mqtt.setState(serverIndex,'cnlst'),200);webSocketServers[serverIndex].retryTimer=setTimeout(()=>shc.mqtt.startClient(serverIndex,1),3000);};ws.mqttClient.onMessageArrived=(message)=>shc.mqtt.onMessage(serverIndex,message);ws.mqttClient.connect({onSuccess:()=>{shc.mqtt.setState(serverIndex,'open');shc.mqtt.subscribeAll(serverIndex);},onFailure:()=>{shc.mqtt.setState(serverIndex,'error');webSocketServers[serverIndex].retryTimer=setTimeout(()=>shc.mqtt.startClient(serverIndex,1),3000);},useSSL:true});}subscribeAll(serverIndex){var
ws=webSocketServers[serverIndex];for(const
oneTopic
in
uiData['iotTopicsMap']){ws.mqttClient.subscribe(oneTopic);}for(const
oneTopic
in
uiData['iotTopicsMap']){if(uiData['iotTopicsMap'][oneTopic]['type']==='device'||uiData['iotTopicsMap'][oneTopic]['type']==='scene'){let
message=new
Paho.MQTT.Message('{"state": ""}');if(oneTopic.endsWith('/'))message.destinationName=oneTopic+'get';else
message.destinationName=oneTopic+'/get';console.log("GET: ",message.destinationName);ws.mqttClient.send(message);}}}onMessage(serverIndex,data){var
ws=webSocketServers[serverIndex];console.log("mqtt#"+ws.id+": `"+data.destinationName+"` `"+data.payloadString+"`");shc.mqtt.setElementValue(serverIndex,data);return;}setElementValue(serverIndex,data){let
payload=null;if(data.payloadString[0]==='{'||data.payloadString[0]==='['){payload=JSON.parse(data.payloadString);if('_states'in
payload)payload=payload['_states'];}else
payload={value:data.payloadString};console.log("__PAYLOAD: ",payload);if(uiData['iotTopicsMap']===undefined){console.log("Missing uiData topics map");return;}let
topicInfo=uiData['iotTopicsMap'][data.destinationName];if(topicInfo===undefined){console.log("Missing topic info in uiData");return;}for(let
i=0;i<topicInfo['elids'].length;i++){let
elid=topicInfo['elids'][i];let
mqttItem=document.getElementById(elid);if(!mqttItem){console.log("NOT EXIST",elid);continue;}let
family=mqttItem.getAttribute('data-shp-family');if(family==='iot-sensor'){let
valueElement=mqttItem.querySelector('span.value');valueElement.textContent=payload.value;}else
if(family==='iot-light'){let
switchElement=mqttItem.getElementsByClassName('shp-iot-primary-switch');if(switchElement.length>0){let
propertyId=switchElement[0].getAttribute('data-shp-iot-state-id');if(propertyId===null)propertyId='state';if(payload[propertyId]!==undefined){if(switchElement[0].disabled)switchElement[0].disabled=false;let
valueOn=switchElement[0].getAttribute('data-shp-value-on');if(!valueOn)valueOn='ON';switchElement[0].checked=payload[propertyId]===valueOn;}}if(payload['brightness']!==undefined){let
brElement=mqttItem.getElementsByClassName('shp-iot-br-range');if(brElement.length>0){if(brElement[0].disabled)brElement[0].disabled=false;brElement[0].value=payload['brightness'].toString();}}if(payload['color_temp']!==undefined){let
ctElement=mqttItem.getElementsByClassName('shp-iot-ct-range');if(ctElement.length>0){if(ctElement[0].disabled)ctElement[0].disabled=false;ctElement[0].value=payload['color_temp'].toString();}}setTimeout(function(){shc.mqtt.checkGroups()},100);}else
if(family==='iot-setup-scene'){if(payload['scene']!==undefined){let
scElement=mqttItem.querySelectorAll("[data-shp-scene-id='"+payload['scene']+"']");if(scElement.length>0)scElement[0].checked=true;}}}}checkGroups(){for(const
groupId
in
uiData['iotElementsGroups']){this.checkGroup(groupId);}}checkGroup(groupId){let
groupMainElement=document.getElementById(groupId);if(!groupMainElement){console.log('Invalid element for group: ',groupId);return;}let
stateOnOff=0;for(const
groupElementNdx
in
uiData['iotElementsGroups'][groupId]){let
sid=uiData['iotElementsGroups'][groupId][groupElementNdx];let
topicId=uiData['iotSubjects'][sid]['topic'];let
elid=uiData['iotTopicsMap'][topicId]['elids'][0];let
mqttItem=document.getElementById(elid);if(!mqttItem){console.log('element not exist: ',elid);continue;}let
switchElement=mqttItem.getElementsByClassName('shp-iot-primary-switch');if(switchElement.length>0){if(switchElement[0].checked)stateOnOff=1;}}let
switchElement=groupMainElement.getElementsByClassName('shp-iot-group-switch');if(switchElement.length>0){if(switchElement[0].disabled)switchElement[0].disabled=false;if(stateOnOff&&!switchElement[0].checked)switchElement[0].checked=true;else
if(!stateOnOff&&switchElement[0].checked)switchElement[0].checked=false;}}setState(serverIndex,socketState){var
ws=webSocketServers[serverIndex];}publish(serverIndex,topic,payload){var
ws=webSocketServers[serverIndex];let
message=new
Paho.MQTT.Message(payload);message.destinationName=topic;ws.mqttClient.send(message);}applyUIData(responseUIData){console.log("ShipardMqtt - apply uiData: ",responseUIData);}}class
ShipardCamsPictsLoader{camerasTimer=null;init(){this.reloadImages();}reloadImages(){if(this.camerasTimer){clearTimeout(this.camerasTimer);}for(let
serverNdx
in
uiData['iotCamServers']){const
urlPath=uiData['iotCamServers'][serverNdx]['camUrl']+"campicts";shc.server.get(urlPath,function(data){this.setPictures(serverNdx,data);}.bind(this),function(data){console.log("--load-error--");}.bind(this),1);}this.camerasTimer=setTimeout(function(){this.reloadImages()}.bind(this),3000);}setPictures(serverNdx,data){const
server=uiData['iotCamServers'][serverNdx];for(let
camNdx
in
data){if(data[camNdx]['image']===false){continue;}let
camId='CMP'+camNdx;if(uiData['iotCamPictures'][camId]===undefined)continue;let
pictUrl='';let
ids=uiData['iotCamPictures'][camId]['elms'];for(var
key
in
ids){let
camPictElement=document.getElementById(ids[key]);if(!camPictElement){continue;}let
pictStyle=camPictElement.getAttribute('data-pict-style');if(pictStyle==='video'){let
videoElement=camPictElement.querySelector('video');const
played=parseInt(camPictElement.getAttribute('data-stream-started'));if(!played){this.startVideoRTC(videoElement);camPictElement.setAttribute('data-stream-started','1');}}else{if(pictStyle==='full')pictUrl=server['camUrl']+'imgs/'+camNdx+'/'+data[camNdx]['image'];else
pictUrl=server['camUrl']+'imgs/-w960/-q70/'+camNdx+'/'+data[camNdx]['image'];let
imgElement=camPictElement.querySelector('img');imgElement.src=pictUrl;}}}}startVideoRTC(videoEl){const
url=videoEl.getAttribute('data-stream-url');const
webrtc=new
RTCPeerConnection({iceServers:[{urls:['stun:stun.l.google.com:19302']}],sdpSemantics:'unified-plan'});webrtc.ontrack=function(event){console.log(event.streams.length+' track is delivered');videoEl.srcObject=event.streams[0];videoEl.play();};webrtc.addTransceiver('video',{direction:'sendrecv'});webrtc.onnegotiationneeded=async function
handleNegotiationNeeded(){const
offer=await
webrtc.createOffer();await
webrtc.setLocalDescription(offer);fetch(url,{method:'POST',body:new
URLSearchParams({data:btoa(webrtc.localDescription.sdp)})}).then(response=>response.text()).then(data=>{try{webrtc.setRemoteDescription(new
RTCSessionDescription({type:'answer',sdp:atob(data)}));}catch(e){console.warn(e);}});};const
webrtcSendChannel=webrtc.createDataChannel('rtsptowebSendChannel');webrtcSendChannel.onopen=(event)=>{console.log(`${webrtcSendChannel.label}has
opened`);webrtcSendChannel.send('ping');};webrtcSendChannel.onclose=(_event)=>{console.log(`${webrtcSendChannel.label}has
closed`);startPlay(videoEl,url);};webrtcSendChannel.onmessage=event=>console.log(event.data);}}class
ShipardWidget{rootElm=null;rootId='';numPad=null;init(rootElm){this.rootElm=rootElm;this.rootId=this.rootElm.getAttribute('id');this.on(this,'click','.shp-widget-action',function(e,ownerWidget,event){ownerWidget.widgetAction(e,event)});}widgetAction(e,event){let
actionId=e.getAttribute('data-action');this.doAction(actionId,e);event.stopPropagation();}doAction(actionId,e){console.log("ACTION-WIDGET: ",actionId);switch(actionId){case'inline-action':return this.inlineAction(e);case'select-main-tab':return this.selectMainTab(e);case'select-simple-tab':return this.selectSimpleTab(e);case'open-popup':return this.openPopup(e);case'open-modal':return this.openModal(e);case'closeModal':return this.closeModal(e);case'treeListGroupOC':return this.treeListGroupOC(e);}return 0;}inlineAction(e){if(e.getAttribute('data-object-class-id')===null)return;var
requestParams={};requestParams['object-class-id']=e.getAttribute('data-object-class-id');requestParams['action-type']=e.getAttribute('data-action-type');this.elementPrefixedAttributes(e,'data-action-param-',requestParams);if(e.getAttribute('data-pk')!==null)requestParams['pk']=e.getAttribute('data-pk');console.log("__INLINE_ACTION",requestParams);}openModal(e){const
modalType=e.getAttribute('data-modal-type');var
modalParams={};var
modalAttrs={'parent-widget-id':this.rootElm.getAttribute('id'),'parent-widget-type':'unknown',};this.elementPrefixedAttributes(e,'data-action-param-',modalParams);let
newEnvelope=document.createElement('data-modal-env');newEnvelope.setAttribute('data-request-type','dataModal');newEnvelope.innerHTML="<div class='tlbr'><span class='backIcon shp-widget-action' data-action='closeModal'></span><span class='modalTitle'></span></div><div class='content'></div>";for(const
oneParamId
in
modalParams)newEnvelope.setAttribute('data-action-param-'+oneParamId,modalParams[oneParamId]);newEnvelope.id='shc_meid_'+shc.counter++;document.body.appendChild(newEnvelope);newEnvelope.shpWidget=new
ShipardWidget();newEnvelope.shpWidget.init(newEnvelope);switch(modalType){case'viewer':console.log('Viewer!');break;}let
apiParams={'cgType':2,'requestType':'openModal',};this.elementPrefixedAttributes(e,'data-action-param-',apiParams);console.log("API-CALL-MODAL",apiParams);var
url='api/v2';shc.server.post(url,apiParams,function(data){console.log("--api-call-MODAL-success--",data);this.doWidgetModalResponse(data,newEnvelope.id);}.bind(this),function(data){console.log("--api-call-MODAL-error--");}.bind(this));return 0;}closeModal(e){this.rootElm.remove();return 0;}openPopup(e){const
url=e.getAttribute('data-url');var
height=((screen.availHeight*window.devicePixelRatio)*0.8)|0;var
width=(height*.7+50)|0;let
popUpId='-openPopupAtt';var
nw=window.open(url,"shpd-cl-ng"+popUpId,"location=no,status=no,width="+width+",height="+height);nw.focus();return 0;}selectMainTab(e){const
tabsId=e.getAttribute('data-tabs');const
inputValueId=this.rootId+'_'+tabsId+'_Value';const
inputElement=document.getElementById(inputValueId);inputElement.value=e.getAttribute('data-tab-id');const
tabsElementId=this.rootId+'_'+tabsId;const
tabsElement=document.getElementById(tabsElementId);let
oldActiveTabElement=tabsElement.querySelector('.active');oldActiveTabElement.classList.remove('active');e.classList.add('active');let
apiParams={'cgType':2};this.apiCall('reloadContent',apiParams);}selectSimpleTab(e){const
tabsId=e.getAttribute('data-tabs');const
tabsElement=document.getElementById(tabsId);let
oldActiveTabElement=tabsElement.querySelector('.active');oldActiveTabElement.classList.remove('active');e.classList.add('active');const
tabsOldElementContentId=oldActiveTabElement.getAttribute('data-tab-id');document.getElementById(tabsOldElementContentId).classList.remove('active');const
tabsNewElementContentId=e.getAttribute('data-tab-id');document.getElementById(tabsNewElementContentId).classList.add('active');}treeListGroupOC(e){let
itemElement=e.parentElement;if(itemElement.classList.contains('open')){itemElement.classList.remove('open');itemElement.classList.add('closed');}else{itemElement.classList.remove('closed');itemElement.classList.add('open');}}apiCall(apiActionId,outsideApiParams){var
apiParams={};apiParams['requestType']=this.rootElm.getAttribute('data-request-type');apiParams['classId']=this.rootElm.getAttribute('data-class-id');apiParams['actionId']=apiActionId;apiParams['widgetId']=this.rootElm.id;if(outsideApiParams!==undefined)apiParams={...apiParams,...outsideApiParams};this.detectValues(apiParams);var
url='api/v2';shc.server.post(url,apiParams,function(data){console.log("--api-call-success--");this.doWidgetResponse(data);}.bind(this),function(data){console.log("--api-call-error--");}.bind(this));}apiCallObject(classId,outsideApiParams){var
apiParams={};apiParams['requestType']='object';apiParams['classId']=classId;if(outsideApiParams!==undefined)apiParams={...apiParams,...outsideApiParams};console.log("API-CALL-OBJECT",apiParams);var
url='api/v2';shc.server.post(url,apiParams,function(data){console.log("--api-call-success--");this.doApiObjectResponse(data);}.bind(this),function(data){console.log("--api-call-error--");}.bind(this));}detectValues(data){const
inputs=this.rootElm.querySelectorAll("input");for(let
i=0;i<inputs.length;++i){const
valueKey=inputs[i].getAttribute('name');data[valueKey]=inputs[i].value;}}doWidgetResponse(data){if(data['response']!==undefined&&data['response']['uiData']!==undefined)shc.applyUIData(data['response']['uiData']);console.log(data);}doApiObjectResponse(data){console.log(data);}doWidgetModalResponse(data,targetElementId){if(data['response']!==undefined&&data['response']['uiData']!==undefined)shc.applyUIData(data['response']['uiData']);var
targetModalElement=document.getElementById(targetElementId);var
contentElement=targetModalElement.querySelector('div.content');var
tlbrElement=targetModalElement.querySelector('div.tlbr');console.log("tlbrElement",tlbrElement);var
backIconElement=tlbrElement.querySelector('.backIcon');var
titleElement=tlbrElement.querySelector('.modalTitle');if(data.response.hcBackIcon!==undefined)backIconElement.innerHTML=data.response.hcBackIcon;if(data.response.hcTitle!==undefined)titleElement.innerHTML=data.response.hcTitle;this.setInnerHTML(contentElement,data.response.hcFull);if(data.response.objectType==='dataView')initWidgetTableViewer(data.response.objectId);else{console.log("init-other-widget");let
e=document.getElementById(data.response.objectId);e.shpWidget=new
ShipardWidget();e.shpWidget.init(e);}}on(ownerWidget,eventType,selector,callback){this.rootElm.addEventListener(eventType,function(event){var
ce=event.target.closest(selector);if(ce){callback.call(ce,ce,ownerWidget,event);}});}onClick(ownerWidget,selector,callback){this.on(ownerWidget,'click',selector,callback)};nf(n,c){var
c=isNaN(c=Math.abs(c))?2:c,d='.',t=' ',s=n<0?"-":"",i=parseInt(n=Math.abs(+n||0).toFixed(c))+"",j=(j=i.length)>3?j%3:0;return s+(j?i.substr(0,j)+t:"")+i.substr(j).replace(/(\d{3})(?=\d)/g,"$1"+t)+(c?d+Math.abs(n-i).toFixed(c).slice(2):"");}parseFloat(n){var
str=n.replace(',','.');return parseFloat(str);}round(value,decimals){return Number(Math.round(value+'e'+decimals)+'e-'+decimals);}escapeHtml(str){var
div=document.createElement('div');div.appendChild(document.createTextNode(str));return div.innerHTML;}elmHide(e){e.classList.add('d-none');}elmShow(e){e.classList.remove('d-none');}getNumber(options){const
template=document.createElement('div');template.id='widget_123';template.classList.add('fullScreenModal');document.body.appendChild(template);var
abc=new
ShipardTouchNumPad();abc.options=options;abc.init(template);this.numPad=abc;}elementPrefixedAttributes(iel,prefix,data){for(var
i=0,attrs=iel.attributes,l=attrs.length;i<l;i++){var
attrName=attrs.item(i).nodeName;if(attrName.substring(0,prefix.length)!==prefix)continue;var
attrNameShort=attrName.substring(prefix.length);var
val=attrs.item(i).nodeValue;data[attrNameShort]=val;}}openModalForm(formOp,params,attrs){let
newEnvelope=document.createElement('data-modal-form-env');newEnvelope.setAttribute('data-request-type','dataForm');for(const
oneParamId
in
params)newEnvelope.setAttribute('data-action-param-'+oneParamId,params[oneParamId]);for(const
oneParamId
in
attrs)newEnvelope.setAttribute('data-'+oneParamId,attrs[oneParamId]);newEnvelope.id='shc_meid_'+shc.counter++;newEnvelope.innerHTML="čekejte, prosím, data se načítají...";document.body.appendChild(newEnvelope);newEnvelope.formOp=formOp;newEnvelope.shpWidget=new
ShipardTableForm();newEnvelope.shpWidget.init(newEnvelope);}setInnerHTML(elm,html){elm.innerHTML=html;Array.from(elm.querySelectorAll("script")).forEach(oldScriptEl=>{const
newScriptEl=document.createElement("script");Array.from(oldScriptEl.attributes).forEach(attr=>{newScriptEl.setAttribute(attr.name,attr.value)});const
scriptText=document.createTextNode(oldScriptEl.innerHTML);newScriptEl.appendChild(scriptText);oldScriptEl.parentNode.replaceChild(newScriptEl,oldScriptEl);});}}function
inputCh(){console.log("--CHANGE--");}class
ShipardTableViewer
extends
ShipardWidget{detailModes={panels:0,details:1,};dmDetail=1;elmViewerLines=null;elmViewerRows=null;elmViewerDetail=null;elmViewerDetailContent=null;elmViewerDetailHeader=null;elmViewerDetailTabs=null;detailMode=this.detailModes.panels;doWidgetResponse(data){console.log("ShipardTableViewer::doWidgetResponse");super.doWidgetResponse(data);}init(e){console.log("ShipardTableViewer::init");super.init(e);this.rootElm.style.display='grid';const
id=e.getAttribute('id');this.elmViewerLines=document.getElementById(id+'Items');this.elmViewerRows=this.elmViewerLines.parentElement;this.elmViewerRows.addEventListener('scroll',(event)=>{this.doScroll(event)});this.elmViewerDetail=this.rootElm.querySelector('div.detail');if(this.elmViewerDetail){this.elmViewerDetailContent=this.elmViewerDetail.querySelector('div.content');this.elmViewerDetailHeader=this.elmViewerDetail.querySelector('div.header');this.elmViewerDetailTabs=this.elmViewerDetail.querySelector('div.tabs');}this.on(this,'click','div.rows-list.mainViewer>div.r',function(e,ownerWidget,event){this.rowClick(e,event)}.bind(this));}doAction(actionId,e){console.log("viewerAction",actionId);switch(actionId){case'newform':return this.actionNewForm(e);case'viewerTabsReload':return this.viewerTabsReload(e);case'detailSelect':return this.detailSelect(e);case'viewerPanelTab':return this.viewerPanelTab(e);}return super.doAction(actionId,e);}doScroll(event){const
e=event.target;const
loadOnProgress=parseInt(this.elmViewerRows.getAttribute('data-loadonprogress'));if(loadOnProgress)return;const
heightToEnd=e.scrollHeight-(e.scrollTop+e.clientHeight);if(heightToEnd<=500){this.elmViewerRows.setAttribute('data-loadonprogress',1);window.requestAnimationFrame(()=>{this.viewerRefreshLoadNext();});}}viewerTabsReload(e){let
itemElement=e.parentElement;const
inputElement=itemElement.querySelector('input');inputElement.value=e.getAttribute('data-value');let
oldActiveTabElement=itemElement.querySelector('.active');oldActiveTabElement.classList.remove('active');e.classList.add('active');this.refreshData(e);}viewerPanelTab(e){let
itemElement=e.parentElement;let
oldActiveTabElement=itemElement.querySelector('.active');oldActiveTabElement.classList.remove('active');e.classList.add('active');}viewerRefreshLoadNext(){const
tableName=this.rootElm.getAttribute("data-table");if(!tableName)return;this.df2FillViewerLines();}rowClick(e,event){if(!this.elmViewerDetail)return;if(e.classList.contains('active')){e.classList.remove('active');this.detailClose();return;}let
oldActiveRowElement=this.elmViewerLines.querySelector('.active');if(oldActiveRowElement)oldActiveRowElement.classList.remove('active');e.classList.add('active');this.detailOpen(e);}detailSelect(e){console.log('detailSelect1');if(this.elmViewerDetailTabs){const
activeTabElement=this.elmViewerDetailTabs.querySelector('.active');if(activeTabElement){activeTabElement.classList.remove('active');e.classList.add('active');console.log('detailSelect2');const
activeRowElement=this.elmViewerLines.querySelector('.active');this.detailOpen(activeRowElement);}}return 0;}detailOpen(activeRowElement){this.detailMode=this.detailModes.details;this.rootElm.classList.remove('dmPanels');this.rootElm.classList.add('dmDetails');const
viewId=this.rootElm.getAttribute('data-viewer-view-id');const
tableId=this.rootElm.getAttribute('data-table');const
rowNdx=activeRowElement.getAttribute('data-pk');let
detailId='default';if(this.elmViewerDetailTabs){const
activeTabElement=this.elmViewerDetailTabs.querySelector('.active');if(activeTabElement)detailId=activeTabElement.getAttribute('data-detail');}var
apiParams={};apiParams['requestType']='dataViewerDetail';apiParams['actionId']='loadDetail';apiParams['table']=tableId;apiParams['viewId']=viewId;apiParams['detailId']=detailId;apiParams['pk']=rowNdx;this.detectValues(apiParams);var
url='api/v2';shc.server.post(url,apiParams,function(data){this.doDetailOpenResponse(data);}.bind(this),function(data){console.log("--api-call-error--");}.bind(this));}detailClose(){this.detailMode=this.detailModes.panels;this.rootElm.classList.remove('dmDetails');this.rootElm.classList.add('dmPanels');}doDetailOpenResponse(data){this.setInnerHTML(this.elmViewerDetailHeader,data.response.hcHeader);this.setInnerHTML(this.elmViewerDetailContent,data.response.hcContent);}df2FillViewerLines(){var
tableName=this.rootElm.getAttribute("data-table");if(!tableName)return;var
rowsPageNumber=parseInt(this.elmViewerLines.getAttribute('data-rowspagenumber'))+1;var
viewId=this.rootElm.getAttribute('data-viewer-view-id');let
apiParams={'cgType':2,'table':tableName,'rowsPageNumber':rowsPageNumber,'viewId':viewId,};this.apiCall('loadNextData',apiParams);return true;}refreshData(){var
tableName=this.rootElm.getAttribute("data-table");if(!tableName)return;var
viewId=this.rootElm.getAttribute('data-viewer-view-id');let
apiParams={'cgType':2,'table':tableName,'rowsPageNumber':0,'viewId':viewId,};this.apiCall('refreshData',apiParams);return true;}actionNewForm(e){var
formParams={};var
formAttrs={'parent-widget-id':this.rootElm.getAttribute('id'),'parent-widget-type':'viewer',};this.elementPrefixedAttributes(this.rootElm,'data-form-param-',formParams);this.elementPrefixedAttributes(e,'data-action-param-',formParams);this.openModalForm('new',formParams,formAttrs);}doWidgetResponse(data){super.doWidgetResponse(data);if(data['response']['type']==='loadNextData'){this.appendNextData(data);return;}else
if(data['response']['type']==='refreshData'){this.appendNextData(data,1);return;}}appendNextData(data,clear){if(clear!==undefined){this.elmViewerLines.innerHTML=data['response']['hcRows'];this.elmViewerLines.parentElement.scrollTop=0;}else{this.elmViewerLines.removeChild(this.elmViewerLines.lastElementChild);this.elmViewerLines.innerHTML+=data['response']['hcRows'];}this.elmViewerLines.setAttribute('data-rowspagenumber',data['response']['rowsPageNumber']);this.elmViewerRows.setAttribute('data-loadonprogress',0);}}function
initWidgetTableViewer(id){let
e=document.getElementById(id);e.shpWidget=new
ShipardTableViewer();e.shpWidget.init(e);return 1;}class
ShipardTableForm
extends
ShipardWidget{formData=null;init(e){console.log("ShipardTableForm::init");super.init(e);this.rootElm.style.display='grid';let
apiParams={'cgType':2,'formOp':e.formOp,};this.elementPrefixedAttributes(e,'data-action-param-',apiParams);this.apiCall('createForm',apiParams);}doAction(actionId,e){switch(actionId){case'saveForm':return this.saveForm(e);case'saveform':return this.saveForm(e);case'closeForm':return this.closeForm(e);}return super.doAction(actionId,e);}saveForm(e){const
noClose=parseInt(e.getAttribute('data-noclose'));this.getFormData();let
apiParams={'cgType':2,'formOp':'save','formData':this.formData,'noCloseForm':noClose,};this.elementPrefixedAttributes(this.rootElm,'data-action-param-',apiParams);this.elementPrefixedAttributes(e,'data-action-param-',apiParams);this.apiCall('saveForm',apiParams);return 0;}checkForm(changedInput){this.getFormData();let
apiParams={'cgType':2,'formOp':'check','formData':this.formData,'noCloseForm':1,};this.elementPrefixedAttributes(this.rootElm,'data-action-param-',apiParams);this.apiCall('checkForm',apiParams);return 0;}doWidgetResponse(data){if(data['response']['type']==='createForm'){this.rootElm.innerHTML=data['response']['hcFull'];this.setFormData(data['response']['formData']);this.on(this,'change','input',function(e,ownerWidget){ownerWidget.inputValueChanged(e)});return;}if(data['response']['type']==='saveForm'){let
noCloseForm=data['response']['saveResult']['noCloseForm']??0;if(!noCloseForm){const
parentWidgetType=this.rootElm.getAttribute('data-parent-widget-type');if(parentWidgetType==='viewer'){const
parentWidgetId=this.rootElm.getAttribute('data-parent-widget-id');if(parentWidgetId){const
parentElement=document.getElementById(parentWidgetId);if(parentElement)parentElement.shpWidget.refreshData();}}else
if(parentWidgetType==='board'){const
parentWidgetId=this.rootElm.getAttribute('data-parent-widget-id');if(parentWidgetId){const
parentElement=document.getElementById(parentWidgetId);if(parentElement)parentElement.shpWidget.refreshData();}}this.closeForm();return;}this.rootElm.innerHTML=data['response']['hcFull'];this.setFormData(data['response']['formData']);return;}if(data['response']['type']==='checkForm'){this.rootElm.innerHTML=data['response']['hcFull'];this.setFormData(data['response']['formData']);return;}super.doWidgetResponse(data);}setFormData(data){this.formData=data;const
inputs=this.rootElm.querySelectorAll('input, textarea, select');inputs.forEach(input=>{this.setFormInputValue(input);});}setFormInputValue(input){const
inputId=input.getAttribute('name');if(!inputId)return;const
iv=this.dataInputValue(inputId);if(input.classList.contains('e10-inputDateN')){let
siv=iv;if(iv===null||iv==='0000-00-00')siv='';input.value=siv;return;}if(input.classList.contains('e10-inputLogical')){input.checked=parseInt(iv)==1;return;}input.value=iv;}dataInputValue(inputId){var
iidParts=inputId.split('.');if(iidParts.length==1){return this.formData['recData'][inputId]?this.formData['recData'][inputId]:null;}return null;}getFormData(){const
inputs=this.rootElm.querySelectorAll('input, textarea, select');inputs.forEach(input=>{this.getFormInputValue(input);});}getFormInputValue(input){const
inputId=input.getAttribute('name');if(!inputId)return;const
iv=input.value;let
siv=iv;if(input.classList.contains('e10-inputDateN')){if(iv===null||iv==='0000-00-00'||iv==='')siv=null;}else
if(input.classList.contains('e10-inputLogical')){siv=input.checked?1:0;}this.setDataInputValue(inputId,siv);}setDataInputValue(inputId,value){var
iidParts=inputId.split('.');if(iidParts.length==1){this.formData['recData'][inputId]=value;}}closeForm(e){this.rootElm.remove();return 0;}inputValueChanged(e){if(e.classList.contains('e10-ino-checkOnChange')){this.checkForm(e);}}}class
ShipardWidgetBoard
extends
ShipardWidget{elmContent=null;init(e){console.log("ShipardWidgetBoard::init");super.init(e);this.initContent();}initContent(){this.elmContent=this.rootElm.querySelector('.shp-wb-content');}doSwipe(dir){var
swipeDir=0;if(dir.type==='panleft')swipeDir=1;else
if(dir.type==='panright')swipeDir=2;if(!swipeDir)return;let
apiParams={'cgType':2,'swipe':swipeDir};this.apiCall('reloadContent',apiParams);}doAction(actionId,e){console.log("ACTION-BOARD: ",actionId);switch(actionId){case'set-param-value':return this.setParamValue(e);case'newform':return this.actionNewForm(e);case'edit':return this.actionEditForm(e);}return super.doAction(actionId,e);}doWidgetResponse(data){this.setInnerHTML(this.rootElm,data.response.hcMain);this.initContent();super.doWidgetResponse(data);}setParamValue(e){var
inputElement=e.parentElement.parentElement.querySelector('input');if(!inputElement)inputElement=e.parentElement.parentElement.parentElement.querySelector('input');if(inputElement)inputElement.value=e.getAttribute('data-value');let
apiParams={'cgType':2};this.apiCall('reloadContent',apiParams);}actionNewForm(e){var
formParams={};var
formAttrs={'parent-widget-id':this.rootElm.getAttribute('id'),'parent-widget-type':'board',};this.elementPrefixedAttributes(this.rootElm,'data-form-param-',formParams);this.elementPrefixedAttributes(e,'data-action-param-',formParams);this.openModalForm('new',formParams,formAttrs);}actionEditForm(e){var
formParams={};var
formAttrs={'parent-widget-id':this.rootElm.getAttribute('id'),'parent-widget-type':'board',};this.elementPrefixedAttributes(this.rootElm,'data-form-param-',formParams);this.elementPrefixedAttributes(e,'data-action-param-',formParams);this.openModalForm('edit',formParams,formAttrs);}refreshData(e){let
apiParams={'cgType':2};this.apiCall('reloadContent',apiParams);}}function
initWidgetBoard(id){console.log("INIT_BOARD_2!!!!");let
e=document.getElementById(id);e.shpWidget=new
ShipardWidgetBoard();e.shpWidget.init(e);return 1;}class
ShipardTouchNumPad
extends
ShipardWidget{elmDisplay=null;gnId='gn1234';gnValue='';options=null;init(rootElm){super.init(rootElm);var
c="<div class='shp-numpad-container' id='"+this.gnId+"'>";c+="<table class='shp-numpad-keyboard'>";c+="<tr>";c+="<td class='c shp-widget-action' data-action='pressCancel'>✕</td><td class='m' colspan='3'>";if(this.options.title)c+="<div class='title'>"+this.escapeHtml(this.options.title)+"</div>";if(this.options.subtitle)c+="<div class='e10-small'>"+this.escapeHtml(this.options.subtitle)+"</div>";c+="</td>";c+="</tr>";c+="<tr>";c+="<td class='d shp-widget-action' colspan='3'></td><td class='b shp-widget-action' data-action='pressBackspace'>←</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' data-action='pressKey'>7</td><td class='n shp-widget-action' data-action='pressKey'>8</td><td class='n shp-widget-action' data-action='pressKey'>9</td><td class='ok shp-widget-action' data-action='pressOK' rowspan='4'>✔︎</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' data-action='pressKey'>4</td><td class='n shp-widget-action' data-action='pressKey'>5</td><td class='n shp-widget-action' data-action='pressKey'>6</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action'  data-action='pressKey'>1</td><td class='n shp-widget-action' data-action='pressKey'>2</td><td class='n shp-widget-action' data-action='pressKey'>3</td>";c+="</tr>";c+="<tr>";c+="<td class='n shp-widget-action' colspan='2' data-action='pressKey'>0</td><td class='n shp-widget-action' data-action='pressKey'>,</td>";c+="</tr>";c+="</table>";c+="</div>";this.rootElm.innerHTML=c;this.elmDisplay=this.rootElm.querySelector('td.d');}doAction(actionId,e){switch(actionId){case'pressKey':return this.kbdPressKey(e);case'pressBackspace':return this.kbdPressBackspace(e);case'pressCancel':return this.kbdPressCancel(e);case'pressOK':return this.kbdPressOK(e);}return super.doAction(actionId,e);}kbdPressKey(e){const
c=e.innerText;this.gnValue+=c;this.elmDisplay.innerText=this.gnValue;return 0;}kbdPressBackspace(e){if(!this.gnValue.length)return;this.gnValue=this.gnValue.slice(0,-1);this.elmDisplay.innerText=this.gnValue;return 0;}kbdPressCancel(e){this.rootElm.remove();}kbdPressOK(e){this.options.success(null);}}class
ShipardWidgetDocumentCore
extends
ShipardWidget{docRowsTableElm=null;displayAllElm=null;displayValueElm=null;elmIntro=null;elmContainerSell=null;elmContainerPay=null;elmContainerSave=null;doc=null;mode='';init(rootElm){super.init(rootElm);this.docRowsTableElm=this.rootElm.querySelector('div.rows>table.rows');this.elmIntro=this.rootElm.querySelector('div.cash-box-rows-content>div.docTermIntro');this.displayAllElm=this.rootElm.querySelector('div.display');this.displayValueElm=this.displayAllElm.querySelector('div.total');this.elmContainerSell=this.rootElm.querySelector('div.cash-box-container-sell');this.elmContainerPay=this.rootElm.querySelector('div.cash-box-container-pay');this.elmContainerSave=this.rootElm.querySelector('div.cash-box-container-save');}}class
WidgetCashBox
extends
ShipardWidgetDocumentCore{init(rootElm){super.init(rootElm);this.documentInit();}doAction(actionId,e){switch(actionId){case'addRow':return this.newRow(e);case'quantity-plus':return this.documentQuantityRow(e,1);case'quantity-minus':return this.documentQuantityRow(e,-1);case'remove-row':return this.documentRemoveRow(e);case'terminal-sell':return this.setMode('sell');case'terminal-pay':return this.doPay(e);case'terminal-save':return this.save();case'change-payment-method':return this.changePaymentMethod(e);}return super.doAction(actionId,e);}itemFromElement(e){var
item={pk:e.getAttribute('data-pk'),price:parseFloat(e.getAttribute('data-price')),quantity:(e.getAttribute('data-quantity'))?parseFloat(e.getAttribute('data-quantity')):1,name:e.getAttribute('data-name'),askq:e.getAttribute('data-askq'),askp:e.getAttribute('data-askp'),unit:e.getAttribute('data-unit'),unitName:e.getAttribute('data-unit-name')};return item;}newRow(e){var
askq=parseInt(e.getAttribute('data-askq'));var
askp=parseInt(e.getAttribute('data-askp'));if(!askq&&!askp){this.addDocumentRow(this.itemFromElement(e));return 1;}if(askp){this.getNumber({title:'Zadejte cenu '+'('+e.getAttribute('data-unit-name')+')',subtitle:e.getAttribute('data-name'),srcElement:e,askType:'p',success:function(){this.addDocumentRow()}.bind(this)});return 1;}if(askq){this.getNumber({title:'Zadejte množství '+'('+e.getAttribute('data-unit-name')+')',subtitle:e.getAttribute('data-name'),srcElement:e,askType:'q',success:function(){this.addDocumentRow()}.bind(this)});}return 1;}addDocumentRow(item){var
quantity=1;if(!item){item=this.itemFromElement(this.numPad.options.srcElement);if(this.numPad.options.askType==='p'){var
price=e10.parseFloat(this.numPad.gnValue);if(!price)price=null;if(price!==null)item.price=price;}else
if(!this.numPad.options.askType||this.numPad.options.askType==='q'){quantity=this.parseFloat(this.numPad.gnValue);if(!quantity)quantity=1;}this.numPad.rootElm.remove();this.numPad=null;}else
if(item.quantity)quantity=item.quantity;var
priceStr=this.nf(item.price,2);var
totalPrice=this.round(quantity*item.price,2);var
totalPriceStr=this.nf(totalPrice,2);var
row='<tr'+' data-pk="'+item.pk+'"'+' data-quantity="'+quantity+'"'+' data-price="'+item.price+'"'+' data-totalprice="'+totalPrice+'"'+'>';row+='<td class="shp-widget-action" data-action="remove-row">×</td>';row+='<td class="item">'+'<span class="t">'+this.escapeHtml(item.name)+'</span>'+'<br>'+'<span class="e10-small i e10-terminal-action" data-action="row-price-item-change">'+quantity+' '+item.unitName+' á '+priceStr+' = <b>'+totalPriceStr+'</b>'+'</span>'+'</td>';row+='<td class="q number">'+quantity+'</td>';row+='<td class="shp-widget-action" data-action="quantity-plus">+</td>';row+='<td class="shp-widget-action" data-action="quantity-minus">-</td>';row+='</tr>';this.docRowsTableElm.innerHTML=row+this.docRowsTableElm.innerHTML;let
re=this.docRowsTableElm.rows[0];re.setAttribute('data-unit',item.unit);re.setAttribute('data-unit-name',item.unitName);re.setAttribute('data-name',item.name);this.documentRecalc();}documentInit(clearUI){if(this.doc!==null)delete
this.doc;this.doc={rec:{docType:"cashreg",currency:"czk",paymentMethod:1,taxCalc:parseInt(this.rootElm.getAttribute('data-taxcalc')),automaticRound:1,roundMethod:parseInt(this.rootElm.getAttribute('data-roundmethod')),cashBox:parseInt(this.rootElm.getAttribute('data-cashbox')),warehouse:parseInt(this.rootElm.getAttribute('data-warehouse')),docState:4000,docStateMain:2,toPay:0.0},rows:[]};if(clearUI===true){while(this.docRowsTableElm.rows.length)this.docRowsTableElm.rows[0].remove();this.documentRecalc();}};documentRecalc(){var
rowsCount=0;var
totalPrice=0.0;this.doc.rows.length=0;for(let
i=0;i<this.docRowsTableElm.rows.length;i++){var
r=this.docRowsTableElm.rows[i];var
rowTotalPrice=parseFloat(r.getAttribute('data-totalprice'));totalPrice+=rowTotalPrice;rowsCount++;var
documentRow={item:parseInt(r.getAttribute('data-pk')),text:r.getAttribute('data-name'),quantity:parseFloat(r.getAttribute('data-quantity')),unit:r.getAttribute('data-unit'),priceItem:parseFloat(r.getAttribute('data-price'))};this.doc.rows.push(documentRow);}const
totalPriceStr=this.nf(totalPrice,2);let
toPay=(this.doc.rec.roundMethod==1)?this.round(totalPrice,0):totalPrice;this.doc.toPay=toPay;let
displayToPay=this.elmContainerPay.querySelector('div.paymentAmount>span.money-to-pay');displayToPay.innerText=this.nf(toPay,2);this.displayValueElm.innerText=totalPriceStr;if(rowsCount){this.elmHide(this.elmIntro);this.elmShow(this.docRowsTableElm);}else{this.elmHide(this.docRowsTableElm);this.elmShow(this.elmIntro);}}documentQuantityRow(e,how){var
row=e.parentElement;var
quantity=parseFloat(row.getAttribute('data-quantity'));if(how===-1&&quantity<=1.0)return;quantity+=how;var
price=parseFloat(row.getAttribute('data-price'));var
totalPrice=quantity*price;var
quantityStr=quantity;row.setAttribute('data-quantity',quantity);row.setAttribute('data-totalprice',totalPrice);row.querySelector('td.q').innerText=quantityStr;var
unitName=row.getAttribute('data-unit-name');var
rowInfo=quantityStr+' '+unitName+' á '+this.nf(price,2)+' = <b>'+this.nf(totalPrice,2)+'</b>';row.querySelector('td.item>span.i').innerHTML=rowInfo;this.documentRecalc();return 0;}documentRemoveRow(e){var
row=e.parentElement;row.remove();this.documentRecalc();return 0;}doPay(e){var
paymentMethod=e.getAttribute('data-pay-method');console.log('payment method: ',paymentMethod);this.changePaymentMethod(e);this.setMode('pay');}changePaymentMethod(e){let
paymentMethod=parseInt(e.getAttribute('data-pay-method'));this.doc.rec.paymentMethod=parseInt(paymentMethod);if(this.doc.rec.paymentMethod==2)this.doc.rec.roundMethod=0;else
this.doc.rec.roundMethod=parseInt(this.rootElm.getAttribute('data-roundmethod'));this.documentRecalc();const
radioButtons=this.elmContainerPay.querySelectorAll('input[type="radio"]');console.log('set-pay-method: ',paymentMethod,e);for(const
radioButton
of
radioButtons){console.log(radioButton);if(parseInt(radioButton.value)==paymentMethod)radioButton.checked=true;else
radioButton.checked=false;}return 0;}save(){this.setMode('save');const
docData={requestType:'rest',table:'e10doc.core.heads',operation:'insert',printAfterConfirm:1,data:this.doc};var
url='api/v2';shc.server.post(url,docData,function(data){console.log("--save-success--",data);this.documentInit(true);this.setMode('sell');}.bind(this),function(data){console.log("--save-error--");}.bind(this));return 0;}setMode(mode){if(mode==='pay'){console.log("do-pay",this.elmContainerSell);this.elmHide(this.elmContainerSell);this.elmHide(this.elmContainerSave);this.elmShow(this.elmContainerPay);}else
if(mode==='sell'){this.elmHide(this.elmContainerSave);this.elmHide(this.elmContainerPay);this.elmShow(this.elmContainerSell);}else
if(mode==='save'){this.elmHide(this.elmContainerSell);this.elmHide(this.elmContainerPay);this.elmShow(this.elmContainerSave);}return 0;}}function
initWidgetCashBox(id){let
e=document.getElementById(id);e.shpWidget=new
WidgetCashBox();e.shpWidget.init(e);}class
WidgetVendM
extends
ShipardWidgetBoard{elmTableBoxes=null;elmSBDisplayItemName=null;elmSBDisplayItemPrice=null;elmCardCodeInput=null;itemName='';itemNdx=0;personNdx=0;itemPrice=0;itemBoxId='';itemBoxNdx='';init(rootElm){super.init(rootElm);this.elmTableBoxes=this.rootElm.querySelector('table.vmSelectBox');this.elmSBDisplayItemName=document.getElementById('vm-select-box-display-item-name');this.elmSBDisplayItemPrice=document.getElementById('vm-select-box-display-item-price');this.elmCardCodeInput=document.getElementById('vm-card-code-input');this.elmCardCodeInput.addEventListener("keypress",function(event){if(event.key==="Enter"){event.preventDefault();this.validateCardCode();}}.bind(this));}doAction(actionId,e){console.log("VM-ACTION: ",actionId);switch(actionId){case'vmSelectBox':return this.selectBox(e);case'vmBuyGetCard':return this.buyGetCard(e);case'vmBuyCancel':return this.buyCancel(e);}return super.doAction(actionId,e);}doApiObjectResponse(data){if(data.response.classId==='vendms-validate-code'){this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));this.elmHide(this.rootElm.querySelector('div.statusCardVerify'));if(data.response.validPerson!==1){this.elmShow(this.rootElm.querySelector('div.statusInvalidCode'));this.elmCardCodeInput.value='';return;}let
currentCredit=parseInt(data.response.creditAmount);if(currentCredit<this.itemPrice){this.elmShow(this.rootElm.querySelector('div.statusInvalidCredit'));this.rootElm.querySelector('div.statusInvalidCreditAmount').innerText='Výše kreditu: '+currentCredit+' Kč';this.elmCardCodeInput.value='';return;}this.personNdx=data.response.personNdx;this.setVMMode('do-buy');this.doBuyCreateInvoice();return;}if(data.response.classId==='vendms-create-invoice'){if(data.response.success!==1){return;}this.doBuyEjectItem();}console.log("VM_doApiObjectResponse",data);}selectBox(e){let
oldBoxId='';let
oldActiveElement=this.elmTableBoxes.querySelector('td.active');if(oldActiveElement){oldBoxId=oldActiveElement.getAttribute('data-box-id');oldActiveElement.classList.remove('active');}let
newBoxId=e.getAttribute('data-box-id');e.classList.add('active');this.itemName=e.getAttribute('data-item-name');this.itemPrice=parseFloat(e.getAttribute('data-item-price'));this.itemNdx=parseInt(e.getAttribute('data-item-ndx'));this.itemBoxId=newBoxId;this.itemBoxNdx=e.getAttribute('data-box-ndx');return 1;}buyGetCard(e){this.selectBox(e);this.setVMMode('get-card');this.elmCardCodeInput.value='';this.elmSBDisplayItemName.innerText=this.itemName;this.elmSBDisplayItemPrice.innerText=this.itemPrice;return 1;}buyCancel(e){console.log('buyCancel');this.setVMMode('select');return 1;}validateCardCode(){let
cardCode=this.elmCardCodeInput.value;console.log("validateCardCode: ",cardCode);this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));this.elmShow(this.rootElm.querySelector('div.statusCardVerify'));this.apiCallObject('vendms-validate-code',{'cardCode':cardCode});return 1;}setVMMode(mode){let
oldActiveElement=this.rootElm.querySelector('div.vmMode.active');oldActiveElement.classList.remove('active');if(mode==='select'){document.getElementById('vm-mode-select').classList.add('active');}else
if(mode==='get-card'){this.elmHide(this.rootElm.querySelector('div.statusInvalidCode'));this.elmHide(this.rootElm.querySelector('div.statusInvalidCredit'));this.elmHide(this.rootElm.querySelector('div.statusCardVerify'));document.getElementById('vm-mode-buy-get-card').classList.add('active');this.elmCardCodeInput.value='';this.elmCardCodeInput.focus();}else
if(mode==='do-buy'){document.getElementById('vm-mode-buy-in-progress').classList.add('active');}}doBuyCreateInvoice(){console.log('create_invoice');this.apiCallObject('vendms-create-invoice',{'itemNdx':this.itemNdx,'boxNdx':this.itemBoxNdx,'personNdx':this.personNdx});}doBuyEjectItem(){location.reload();}}function
initWidgetVendM(id){let
e=document.getElementById(id);e.shpWidget=new
WidgetVendM();e.shpWidget.init(e);}class
WidgetVendMSetup
extends
ShipardWidgetBoard{itemName='';itemNdx=0;personNdx=0;itemPrice=0;itemBoxId='';itemBoxNdx='';init(rootElm){super.init(rootElm);}doAction(actionId,e){switch(actionId){case'vmBoxSetQuantity':return this.boxSetQuantity(e);}return super.doAction(actionId,e);}doApiObjectResponse(data){if(data.response.classId==='vendms-box-quantity'){location.reload();return;}}boxSetQuantity(e){let
newBoxId=e.getAttribute('data-box-id');this.itemName=e.getAttribute('data-item-name');this.itemPrice=parseFloat(e.getAttribute('data-item-price'));this.itemNdx=parseInt(e.getAttribute('data-item-ndx'));this.itemBoxId=newBoxId;this.itemBoxNdx=e.getAttribute('data-box-ndx');this.getNumber({title:'Zadejte množství v boxu '+e.getAttribute('data-box-label'),subtitle:e.getAttribute('data-item-name'),srcElement:e,askType:'q',success:function(){this.boxSetQuantityDoIt()}.bind(this)});return 1;}boxSetQuantityDoIt(){let
quantity=this.parseFloat(this.numPad.gnValue);this.numPad.rootElm.remove();this.numPad=null;if(!quantity)return;this.apiCallObject('vendms-box-quantity',{'itemNdx':this.itemNdx,'boxNdx':this.itemBoxNdx,'quantity':quantity});}}function
initWidgetVendMSetup(id){let
e=document.getElementById(id);e.shpWidget=new
WidgetVendMSetup();e.shpWidget.init(e);}class
ShipardWidgetVS
extends
ShipardWidgetBoard{doWidgetResponse(data){super.doWidgetResponse(data);}}function
initWidgetVS(id){let
e=document.getElementById(id);e.shpWidget=new
ShipardWidgetVS();e.shpWidget.init(e);console.log("initWidgetVS",id);}class
ShipardWidgetApplication
extends
ShipardWidget{mainAppContent=null;elmAppTitle=null;elmAppMenu=null;elmAppMenuNG=null;elmAppMenuHandle=null;init(e){this.mainAppContent=document.getElementById('shp-main-app-content');this.elmAppTitle=document.getElementById('shp-app-hdr-title');this.elmAppMenu=document.getElementById('shp-app-menu');this.elmAppMenuNG=document.getElementById('shp-app-menu-ng');this.elmAppMenuHandle=document.getElementById('shp-app-menu-handle');console.log("ShipardWidgetApplication::init");super.init(e);if(this.elmAppMenuHandle)this.elmAppMenuHandle.addEventListener('mouseenter',function(){this.appMenuFloatOn()}.bind(this));if(this.elmAppMenuNG)this.elmAppMenuNG.addEventListener('mouseleave',function(){this.appMenuFloatOff()}.bind(this));this.initContent();}initContent(){}doAction(actionId,e){switch(actionId){case'loadAppMenuItem':return this.loadAppMenuItem(e);case'toggleContent':return this.fullScreenContentToggle(e);case'toggleAppMenu':return this.appMenuToggle(e);}return super.doAction(actionId,e);}appMenuToggle(e){console.log('togle-app-menu');if(this.elmAppMenu.classList.contains('float')){this.elmAppMenu.classList.remove('float');return 0;}if(this.elmAppMenu.classList.contains('open')){this.elmAppMenu.classList.remove('open');this.elmAppMenu.classList.add('closed');}else{this.elmAppMenu.classList.remove('closed');this.elmAppMenu.classList.add('open');}return 0;}appMenuFloatOn(){this.elmAppMenu.classList.add('float');return 0;}appMenuFloatOff(){if(this.elmAppMenu.classList.contains('float'))this.elmAppMenu.classList.remove('float');return 0;}fullScreenContentToggle(e){if(this.rootElm.classList.contains('full-screen-content-on'))this.fullScreenContentOff();else
this.fullScreenContentOn();return 0;}fullScreenContentOn(){this.rootElm.classList.remove('full-screen-content-off');this.rootElm.classList.add('full-screen-content-on');}fullScreenContentOff(){this.rootElm.classList.remove('full-screen-content-on');this.rootElm.classList.add('full-screen-content-off');}doWidgetResponse(data){super.doWidgetResponse(data);}loadAppMenuItem(e){if(this.elmAppMenu.classList.contains('float'))this.elmAppMenu.classList.remove('float');let
activeElement=this.elmAppMenu.querySelector('.app-menu-item.active');if(activeElement)activeElement.classList.remove('active');e.classList.add('active');const
modalType='viewer';var
modalParams={};var
modalAttrs={'parent-widget-id':'','parent-widget-type':'unknown',};switch(modalType){case'viewer':console.log('Viewer!');break;}let
apiParams={'cgType':2,'requestType':'appMenuItem',};this.elementPrefixedAttributes(e,'data-action-param-',apiParams);if(apiParams['object-type']==='viewer')apiParams['object-type']='dataViewer';console.log("API-CALL-MENU-ITEM",apiParams);var
url='api/v2';this.fullScreenContentOn();shc.server.post(url,apiParams,function(data){console.log("--api-call-MENU-ITEM-success--",data);this.doLoadAppMenuItemResponse(data);}.bind(this),function(data){console.log("--api-call-MODAL-error--");}.bind(this));return 0;}doLoadAppMenuItemResponse(data){console.log("doLoadAppMenuItemResponse",data);this.setInnerHTML(this.mainAppContent,data.response.hcFull);if(this.elmAppTitle&&data.response.hcTitle!==undefined){this.elmAppTitle.innerHTML=data.response.hcTitle;}if(data.response.objectType==='dataView')initWidgetTableViewer(data.response.objectId);else{console.log("init-other-widget");let
e=document.getElementById(data.response.objectId);e.shpWidget=new
ShipardWidget();e.shpWidget.init(e);}}}function
initWidgetApplication(id){console.log("INIT_APP!!!!");let
e=document.getElementById(id);e.shpWidget=new
ShipardWidgetApplication();e.shpWidget.init(e);return 1;}class
ShipardClient{server=new
ShipardServer();mqtt=new
ShipardMqtt();iot=new
ShipardClientIoT();appVersion='2.0.1';CLICK_EVENT='click';g_formId=1;openModals=[];progressCount=0;viewerScroll=0;disableKeyDown=0;userInfo=null;numPad=null;mainAppContent=null;counter=1;on(eventType,selector,callback){document.addEventListener(eventType,function(event){var
ce=event.target.closest(selector);if(ce){callback.call(ce,ce);}});}onClick(selector,callback){this.on('click',selector,callback)};simpleTabsEvent(e){console.log('tabs...');let
tabsId=e.getAttribute('data-tabs');let
tabsElement=document.getElementById(tabsId+'-tabs');let
oldActiveTabElement=tabsElement.querySelector('.active');oldActiveTabElement.classList.remove('active');let
oldActiveContentId=oldActiveTabElement.getAttribute('data-tab-id');let
oldActiveContentElement=document.getElementById(oldActiveContentId);oldActiveContentElement.classList.add('d-none');e.classList.add('active');let
newActiveContentId=e.getAttribute('data-tab-id');let
newActiveContentElement=document.getElementById(newActiveContentId);newActiveContentElement.classList.remove('d-none');}searchParentAttr(e,attr){var
p=e;while(p.length){var
attrValue=p.attr(attr);if(p.attr(attr))return p.attr(attr);p=p.parent();if(!p.length)break;}return null;};searchObjectAttr(e,attr){var
p=e;while(p.length){if(p.attr(attr))return p;p=p.parent();if(!p.length)break;}return null;};widgetAction(e){let
actionId=e.getAttribute('data-action');this.doAction(actionId,e);}doAction(actionId,e){switch(actionId){case'setColorMode':return this.setColorMode(e);case'setUserContext':return this.setUserContext(e);case'workplaceLogin':return this.workplaceLogin(e);case'inline-action':return this.inlineAction(e);}console.log("APP-ACTION",actionId);return 0;}inlineAction(e){if(e.getAttribute('data-object-class-id')===null)return;var
requestParams={};requestParams['object-class-id']=e.getAttribute('data-object-class-id');requestParams['action-type']=e.getAttribute('data-action-type');this.elementPrefixedAttributes(e,'data-action-param-',requestParams);if(e.getAttribute('data-pk')!==null)requestParams['pk']=e.getAttribute('data-pk');shc.server.api(requestParams,function(data){}.bind(this));}workplaceLogin(e){console.log('workplaceLogin',e.getAttribute('data-login'));this.getNumber({title:'Zadejte přístupový kód',srcElement:e,userLogin:e.getAttribute('data-login'),success:function(){this.workplaceLoginDoIt()}.bind(this)});return 0;}workplaceLoginDoIt(e){console.log(this.numPad.options.userLogin);console.log("__DO_IT__",this.numPad.options.srcElement.getAttribute('data-login'),this.numPad.gnValue);document.getElementById('e10-login-user').value=this.numPad.options.userLogin;document.getElementById('e10-login-pin').value=this.numPad.gnValue;document.forms['e10-mui-login-form'].submit();}getNumber(options){const
template=document.createElement('div');template.id='widget_123';template.classList.add('fullScreenModal');document.body.appendChild(template);var
abc=new
ShipardTouchNumPad();abc.options=options;abc.init(template);this.numPad=abc;}setColorMode(event){let
colorMode=event.target.value;localStorage.setItem('shpAppThemeVariant',colorMode);this.doColorMode(colorMode);return 0;}setUserContext(e){let
userContextId=e.getAttribute('data-user-context');console.log("User context: ",userContextId);let
apiParams={'userContextId':userContextId,};this.apiCall('setUserContext',apiParams);return 0;}initColorMode(firstCall){if(firstCall){window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',function(){this.initColorMode()}.bind(this));}var
colorMode=localStorage.getItem('shpAppThemeVariant');const
themeVariant=uiThemesVariants[colorMode];if(!themeVariant||!colorMode||colorMode==='auto'){const
isSystemDarkMode=window.matchMedia('(prefers-color-scheme: dark)').matches;if(isSystemDarkMode)colorMode='systemDefaultDark';else
colorMode='systemDefaultLight';}this.doColorMode(colorMode);}doColorMode(colorMode){if(colorMode==='auto'){this.initColorMode();return;}const
themeVariant=uiThemesVariants[colorMode];if(!themeVariant){this.initColorMode();return;}var
linkElement=document.getElementById('themeVariant');if(!linkElement){linkElement=document.createElement('link');linkElement.href=httpDSRootPath+themeVariant.file+'?v='+themeVariant.integrity.sha384;linkElement.type='text/css';linkElement.rel='stylesheet';linkElement.id='themeVariant';document.getElementsByTagName('head')[0].appendChild(linkElement);}else{linkElement.href=httpDSRootPath+themeVariant.file+'?v='+themeVariant.integrity.sha384;}document.body.setAttribute('data-shp-theme-variant',colorMode);document.body.setAttribute('data-shp-dark-mode',themeVariant.dm);}setThemeVariantInput(){var
inputElement=document.getElementById('input-shp-theme-variant');if(!inputElement)return;let
themeVariant=localStorage.getItem('shpAppThemeVariant');if(!themeVariant)themeVariant='auto';inputElement.value=themeVariant;}elementPrefixedAttributes(iel,prefix,data){for(var
i=0,attrs=iel.attributes,l=attrs.length;i<l;i++){var
attrName=attrs.item(i).nodeName;if(attrName.substring(0,prefix.length)!==prefix)continue;var
attrNameShort=attrName.substring(prefix.length);var
val=attrs.item(i).nodeValue;data[attrNameShort]=val;}}initUI(){this.setThemeVariantInput();if(!this.mainAppContent)return 0;if('mainUiObjectId'in
this.mainAppContent.dataset)return this.initUIObject(this.mainAppContent.dataset.mainUiObjectId);}initUIObject(id){let
objectElement=document.getElementById(id);if(!objectElement){console.error('element not exist: #',id);return 0;}const
objectElementType=objectElement.getAttribute('data-object-type');if(!objectElementType){console.error('`data-object-type` attr not found in #',id);return 0;}if(objectElementType==='data-viewer')return initWidgetTableViewer(id);if(objectElementType==='data-widget-board')return initWidgetBoard(id);console.log(objectElementType);return 0;}loadUI(){console.log("client__load_ui");}init(){this.mainAppContent=document.getElementById('shp-main-app-content');this.server.setHttpServerRoot(httpApiRootPath);this.initColorMode(true);this.onClick('.shp-simple-tabs-item',function(){shc.simpleTabsEvent(this);});this.onClick('.shp-app-action',function(e){this.widgetAction(e);}.bind(this));this.initUI();if('serviceWorker'in
navigator&&e10ServiceWorkerURL!==undefined){navigator.serviceWorker.register(e10ServiceWorkerURL).then(function(reg){}).catch(function(err){console.log("Service worker registration error: ",err)});}initWidgetApplication('shp-app-window');}applyUIData(responseUIData){this.mqtt.applyUIData(responseUIData);this.iot.applyUIData(responseUIData);}apiCall(apiActionId,outsideApiParams){var
apiParams={};apiParams['requestType']='appCommand';apiParams['actionId']=apiActionId;if(outsideApiParams!==undefined)apiParams={...apiParams,...outsideApiParams};console.log("CLIENT-API-CALL",apiParams);var
url='api/v2';shc.server.post(url,apiParams,function(data){console.log("--app-api-call-success--");this.doAppAPIResponse(data);}.bind(this),function(data){console.log("--api-app-call-error--");}.bind(this));}doAppAPIResponse(data){window.location.reload(true);}}class
ShipardClientIoT{camPictLoader=null;init(){if(uiData['iotCamServers']!==undefined){this.camPictLoader=new
ShipardCamsPictsLoader();this.camPictLoader.init();}shc.on('change','input.mac-shp-triggger',function(){shc.iot.mainTrigger(this);});}mainTrigger(element){let
payload={};if(element.classList.contains('shp-iot-scene-switch')){let
attrSetupSID=element.getAttribute('data-shp-iot-setup');if(attrSetupSID===undefined){console.log("unknown iot setup");return;}let
attrSetupSceneId=element.getAttribute('data-shp-scene-id');if(attrSetupSceneId===undefined){console.log("unknown iot setup scene id");return;}payload['scene']=attrSetupSceneId;let
setTopic=uiData['iotSubjects'][attrSetupSID]['topic']+'/set';shc.mqtt.publish(uiData['iotSubjects'][attrSetupSID]['wss'],setTopic,JSON.stringify(payload));return;}if(element.classList.contains('shp-iot-primary-switch')||element.classList.contains('shp-iot-group-switch')){let
propertyId=element.getAttribute('data-shp-iot-state-id');if(propertyId===null)propertyId='state';let
valueOn=element.getAttribute('data-shp-value-on');if(!valueOn)valueOn='ON';let
valueOff=element.getAttribute('data-shp-value-off');if(!valueOff)valueOff='OFF';payload[propertyId]=element.checked?valueOn:valueOff;}else
if(element.classList.contains('shp-iot-br-range'))payload['brightness']=element.value;else
if(element.classList.contains('shp-iot-ct-range'))payload['color_temp']=element.value;if(Object.keys(payload).length===0&&payload.constructor===Object)return;let
attrDeviceSID=element.getAttribute('data-shp-iot-device');if(attrDeviceSID===undefined){console.log("unknown iot device");return;}let
deviceSIDs=attrDeviceSID.split(',');for(const
deviceSID
of
deviceSIDs){if(uiData['iotSubjects']===undefined||uiData['iotSubjects'][deviceSID]===undefined){console.log("Invalid device SID: ",deviceSID);continue;}let
setTopic=uiData['iotSubjects'][deviceSID]['topic'];if(setTopic.endsWith('/'))setTopic+='set';else
setTopic+='/set';shc.mqtt.publish(uiData['iotSubjects'][deviceSID]['wss'],setTopic,JSON.stringify(payload));}}initIoT(){shc.mqtt.init();shc.iot.init();}applyUIData(responseUIData){if(!responseUIData)return;if(responseUIData['iotCamServers']!==undefined){if(uiData['iotCamServers']===undefined)uiData['iotCamServers']={};for(let
serverNdx
in
responseUIData['iotCamServers']){if(uiData['iotCamServers'][serverNdx]===undefined)uiData['iotCamServers'][serverNdx]=responseUIData['iotCamServers'][serverNdx];}}if(responseUIData['iotCamPictures']!==undefined){if(uiData['iotCamPictures']===undefined)uiData['iotCamPictures']={};for(let
camId
in
responseUIData['iotCamPictures']){if(uiData['iotCamPictures'][camId]===undefined)uiData['iotCamPictures'][camId]=responseUIData['iotCamPictures'][camId];else{let
ids=responseUIData['iotCamPictures'][camId]['elms'];for(var
key
in
ids){uiData['iotCamPictures'][camId]['elms'][key]=responseUIData['iotCamPictures'][camId]['elms'][key];}}}}if(this.camPictLoader===null)this.camPictLoader=new
ShipardCamsPictsLoader();this.camPictLoader.init();console.log("ShipardClientIoT - apply uiData: ",uiData);}}var
shc=new
ShipardClient();document.addEventListener('DOMContentLoaded',()=>shc.init());