class
VideoRTC
extends
HTMLElement{constructor(){super();this.DISCONNECT_TIMEOUT=5000;this.RECONNECT_TIMEOUT=15000;this.CODECS=['avc1.640029','avc1.64002A','avc1.640033','hvc1.1.6.L153.B0','mp4a.40.2','mp4a.40.5','flac','opus',];this.mode='webrtc,mse,hls,mjpeg';this.media='video,audio';this.background=false;this.visibilityThreshold=0;this.visibilityCheck=true;this.pcConfig={bundlePolicy:'max-bundle',iceServers:[{urls:'stun:stun.l.google.com:19302'}],sdpSemantics:'unified-plan',};this.wsState=WebSocket.CLOSED;this.pcState=WebSocket.CLOSED;this.video=null;this.ws=null;this.wsURL='';this.pc=null;this.connectTS=0;this.mseCodecs='';this.disconnectTID=0;this.reconnectTID=0;this.ondata=null;this.onmessage=null;}set
src(value){if(typeof
value!=='string')value=value.toString();if(value.startsWith('http')){value='ws'+value.substring(4);}else
if(value.startsWith('/')){value='ws'+location.origin.substring(4)+value;}this.wsURL=value;this.onconnect();}play(){this.video.play().catch(()=>{if(!this.video.muted){this.video.muted=true;this.video.play().catch(er=>{console.warn(er);});}});}send(value){if(this.ws)this.ws.send(JSON.stringify(value));}codecs(isSupported){return this.CODECS.filter(codec=>this.media.indexOf(codec.indexOf('vc1')>0?'video':'audio')>=0).filter(codec=>isSupported(`video/mp4;codecs="${codec}"`)).join();}connectedCallback(){if(this.disconnectTID){clearTimeout(this.disconnectTID);this.disconnectTID=0;}if(this.video){const
seek=this.video.seekable;if(seek.length>0){this.video.currentTime=seek.end(seek.length-1);}this.play();}else{this.oninit();}this.onconnect();}disconnectedCallback(){if(this.background||this.disconnectTID)return;if(this.wsState===WebSocket.CLOSED&&this.pcState===WebSocket.CLOSED)return;this.disconnectTID=setTimeout(()=>{if(this.reconnectTID){clearTimeout(this.reconnectTID);this.reconnectTID=0;}this.disconnectTID=0;this.ondisconnect();},this.DISCONNECT_TIMEOUT);}oninit(){this.video=document.createElement('video');this.video.controls=true;this.video.playsInline=true;this.video.preload='auto';this.video.style.display='block';this.video.style.width='100%';this.video.style.height='100%';this.appendChild(this.video);this.video.addEventListener('error',ev=>{console.warn(ev);if(this.ws)this.ws.close();});const
m=window.navigator.userAgent.match(/Version\/(\d+).+Safari/);if(m){const
skip=m[1]<'13'?'mp4a.40.2':m[1]<'14'?'flac':'opus';this.CODECS.splice(this.CODECS.indexOf(skip));}if(this.background)return;if('hidden'in
document&&this.visibilityCheck){document.addEventListener('visibilitychange',()=>{if(document.hidden){this.disconnectedCallback();}else
if(this.isConnected){this.connectedCallback();}});}if('IntersectionObserver'in
window&&this.visibilityThreshold){const
observer=new
IntersectionObserver(entries=>{entries.forEach(entry=>{if(!entry.isIntersecting){this.disconnectedCallback();}else
if(this.isConnected){this.connectedCallback();}});},{threshold:this.visibilityThreshold});observer.observe(this);}}onconnect(){if(!this.isConnected||!this.wsURL||this.ws||this.pc)return false;this.wsState=WebSocket.CONNECTING;this.connectTS=Date.now();this.ws=new
WebSocket(this.wsURL);this.ws.binaryType='arraybuffer';this.ws.addEventListener('open',()=>this.onopen());this.ws.addEventListener('close',()=>this.onclose());return true;}ondisconnect(){this.wsState=WebSocket.CLOSED;if(this.ws){this.ws.close();this.ws=null;}this.pcState=WebSocket.CLOSED;if(this.pc){this.pc.getSenders().forEach(sender=>{if(sender.track)sender.track.stop();});this.pc.close();this.pc=null;}this.video.src='';this.video.srcObject=null;}onopen(){this.wsState=WebSocket.OPEN;this.ws.addEventListener('message',ev=>{if(typeof
ev.data==='string'){const
msg=JSON.parse(ev.data);for(const
mode
in
this.onmessage){this.onmessage[mode](msg);}}else{this.ondata(ev.data);}});this.ondata=null;this.onmessage={};const
modes=[];if(this.mode.indexOf('mse')>=0&&('MediaSource'in
window||'ManagedMediaSource'in
window)){modes.push('mse');this.onmse();}else
if(this.mode.indexOf('hls')>=0&&this.video.canPlayType('application/vnd.apple.mpegurl')){modes.push('hls');this.onhls();}else
if(this.mode.indexOf('mp4')>=0){modes.push('mp4');this.onmp4();}if(this.mode.indexOf('webrtc')>=0&&'RTCPeerConnection'in
window){modes.push('webrtc');this.onwebrtc();}if(this.mode.indexOf('mjpeg')>=0){if(modes.length){this.onmessage['mjpeg']=msg=>{if(msg.type!=='error'||msg.value.indexOf(modes[0])!==0)return;this.onmjpeg();};}else{modes.push('mjpeg');this.onmjpeg();}}return modes;}onclose(){if(this.wsState===WebSocket.CLOSED)return false;this.wsState=WebSocket.CONNECTING;this.ws=null;const
delay=Math.max(this.RECONNECT_TIMEOUT-(Date.now()-this.connectTS),0);this.reconnectTID=setTimeout(()=>{this.reconnectTID=0;this.onconnect();},delay);return true;}onmse(){let
ms;if('ManagedMediaSource'in
window){const
MediaSource=window.ManagedMediaSource;ms=new
MediaSource();ms.addEventListener('sourceopen',()=>{this.send({type:'mse',value:this.codecs(MediaSource.isTypeSupported)});},{once:true});this.video.disableRemotePlayback=true;this.video.srcObject=ms;}else{ms=new
MediaSource();ms.addEventListener('sourceopen',()=>{URL.revokeObjectURL(this.video.src);this.send({type:'mse',value:this.codecs(MediaSource.isTypeSupported)});},{once:true});this.video.src=URL.createObjectURL(ms);this.video.srcObject=null;}this.play();this.mseCodecs='';this.onmessage['mse']=msg=>{if(msg.type!=='mse')return;this.mseCodecs=msg.value;const
sb=ms.addSourceBuffer(msg.value);sb.mode='segments';sb.addEventListener('updateend',()=>{if(sb.updating)return;try{if(bufLen>0){const
data=buf.slice(0,bufLen);bufLen=0;sb.appendBuffer(data);}else
if(sb.buffered&&sb.buffered.length){const
end=sb.buffered.end(sb.buffered.length-1)-15;const
start=sb.buffered.start(0);if(end>start){sb.remove(start,end);ms.setLiveSeekableRange(end,end+15);}}}catch(e){}});const
buf=new
Uint8Array(2*1024*1024);let
bufLen=0;this.ondata=data=>{if(sb.updating||bufLen>0){const
b=new
Uint8Array(data);buf.set(b,bufLen);bufLen+=b.byteLength;}else{try{sb.appendBuffer(data);}catch(e){}}};};}onwebrtc(){const
pc=new
RTCPeerConnection(this.pcConfig);pc.addEventListener('icecandidate',ev=>{if(ev.candidate&&this.mode.indexOf('webrtc/tcp')>=0&&ev.candidate.protocol==='udp')return;const
candidate=ev.candidate?ev.candidate.toJSON().candidate:'';this.send({type:'webrtc/candidate',value:candidate});});pc.addEventListener('connectionstatechange',()=>{if(pc.connectionState==='connected'){const
tracks=pc.getTransceivers().filter(tr=>tr.currentDirection==='recvonly').map(tr=>tr.receiver.track);const
video2=document.createElement('video');video2.addEventListener('loadeddata',()=>this.onpcvideo(video2),{once:true});video2.srcObject=new
MediaStream(tracks);}else
if(pc.connectionState==='failed'||pc.connectionState==='disconnected'){pc.close();this.pcState=WebSocket.CLOSED;this.pc=null;this.onconnect();}});this.onmessage['webrtc']=msg=>{switch(msg.type){case'webrtc/candidate':if(this.mode.indexOf('webrtc/tcp')>=0&&msg.value.indexOf(' udp ')>0)return;pc.addIceCandidate({candidate:msg.value,sdpMid:'0'}).catch(er=>{console.warn(er);});break;case'webrtc/answer':pc.setRemoteDescription({type:'answer',sdp:msg.value}).catch(er=>{console.warn(er);});break;case'error':if(msg.value.indexOf('webrtc/offer')<0)return;pc.close();}};this.createOffer(pc).then(offer=>{this.send({type:'webrtc/offer',value:offer.sdp});});this.pcState=WebSocket.CONNECTING;this.pc=pc;}async createOffer(pc){try{if(this.media.indexOf('microphone')>=0){const
media=await
navigator.mediaDevices.getUserMedia({audio:true});media.getTracks().forEach(track=>{pc.addTransceiver(track,{direction:'sendonly'});});}}catch(e){console.warn(e);}for(const
kind
of['video','audio']){if(this.media.indexOf(kind)>=0){pc.addTransceiver(kind,{direction:'recvonly'});}}const
offer=await
pc.createOffer();await
pc.setLocalDescription(offer);return offer;}onpcvideo(video2){if(this.pc){let
rtcPriority=0,msePriority=0;const
stream=video2.srcObject;if(stream.getVideoTracks().length>0)rtcPriority+=0x220;if(stream.getAudioTracks().length>0)rtcPriority+=0x102;if(this.mseCodecs.indexOf('hvc1.')>=0)msePriority+=0x230;if(this.mseCodecs.indexOf('avc1.')>=0)msePriority+=0x210;if(this.mseCodecs.indexOf('mp4a.')>=0)msePriority+=0x101;if(rtcPriority>=msePriority){this.video.srcObject=stream;this.play();this.pcState=WebSocket.OPEN;this.wsState=WebSocket.CLOSED;if(this.ws){this.ws.close();this.ws=null;}}else{this.pcState=WebSocket.CLOSED;if(this.pc){this.pc.close();this.pc=null;}}}video2.srcObject=null;}onmjpeg(){this.ondata=data=>{this.video.controls=false;this.video.poster='data:image/jpeg;base64,'+VideoRTC.btoa(data);};this.send({type:'mjpeg'});}onhls(){this.onmessage['hls']=msg=>{if(msg.type!=='hls')return;const
url='http'+this.wsURL.substring(2,this.wsURL.indexOf('/ws'))+'/hls/';const
playlist=msg.value.replace('hls/',url);this.video.src='data:application/vnd.apple.mpegurl;base64,'+btoa(playlist);this.play();};this.send({type:'hls',value:this.codecs(type=>this.video.canPlayType(type))});}onmp4(){const
canvas=document.createElement('canvas');let
context;const
video2=document.createElement('video');video2.autoplay=true;video2.playsInline=true;video2.muted=true;video2.addEventListener('loadeddata',()=>{if(!context){canvas.width=video2.videoWidth;canvas.height=video2.videoHeight;context=canvas.getContext('2d');}context.drawImage(video2,0,0,canvas.width,canvas.height);this.video.controls=false;this.video.poster=canvas.toDataURL('image/jpeg');});this.ondata=data=>{video2.src='data:video/mp4;base64,'+VideoRTC.btoa(data);};this.send({type:'mp4',value:this.codecs(this.video.canPlayType)});}static
btoa(buffer){const
bytes=new
Uint8Array(buffer);const
len=bytes.byteLength;let
binary='';for(let
i=0;i<len;i++){binary+=String.fromCharCode(bytes[i]);}return window.btoa(binary);}}class
VideoStream
extends
VideoRTC{set
divMode(value){this.querySelector('.mode').innerText=value;this.querySelector('.status').innerText='';}set
divError(value){const
state=this.querySelector('.mode').innerText;if(state!=='loading')return;this.querySelector('.mode').innerText='error';this.querySelector('.status').innerText=value;}oninit(){console.debug('stream.oninit');super.oninit();this.innerHTML=`<style>video-stream{position:relative;}.info{position:absolute;top:0;left:0;right:0;padding:12px;color:white;display:flex;justify-content:space-between;pointer-events:none;}</style><div
class="info"><div
class="status"></div><div
class="mode"></div></div>`;const
info=this.querySelector('.info');this.insertBefore(this.video,info);}onconnect(){console.debug('stream.onconnect');const
result=super.onconnect();if(result)this.divMode='loading';return result;}ondisconnect(){console.debug('stream.ondisconnect');super.ondisconnect();}onopen(){console.debug('stream.onopen');const
result=super.onopen();this.onmessage['stream']=msg=>{console.debug('stream.onmessge',msg);switch(msg.type){case'error':this.divError=msg.value;break;case'mse':case'hls':case'mp4':case'mjpeg':this.divMode=msg.type.toUpperCase();break;}};return result;}onclose(){console.debug('stream.onclose');return super.onclose();}onpcvideo(ev){console.debug('stream.onpcvideo');super.onpcvideo(ev);if(this.pcState!==WebSocket.CLOSED){this.divMode='RTC';}}}customElements.define('video-stream',VideoStream);