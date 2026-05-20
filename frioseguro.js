// ── STATE ──────────────────────────────────────────────
let ruralMode=false,queueCount=0,alertCount=0;

// ── IDs de alertas ya notificadas por correo (evita duplicados en el cliente) ──
const _alertasNotificadas = new Set();
let heroMap,dashMap,vMarker,offPoly,waitMsg;
let v102Line=null,v102OffLine=null,v102AlertMarkers=[];
let offlineLog=[],offlinePolyGrowing=null,offlineWaypointIdx=0,offlineFinalPoly=null;
const SIM={active:false,tripId:null,intervalId:null,stepIdx:0,offline:false,
  onlinePolylines:[],currentSegPts:[],currentSegColor:null,currentSegLine:null};
let tempChart,donutChart;
let curTemp=5.0,curLat=19.2920,curLng=-99.6570;
let tData=[],tLabels=[];
let lastTelCount=0;


const API_BASE = window.location.origin + '/api';
const apiUrl = (endpoint) => `${API_BASE}${endpoint}`;

async function apiRequest(endpoint, options = {}) {
  const res = await fetch(apiUrl(endpoint), {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {})
    }
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    throw new Error(data.error || `Error HTTP ${res.status}`);
  }
  return data;
}

// Routes
const V102_on=[[19.292,-99.657],[19.260,-99.645],[19.230,-99.628],[19.200,-99.612],[19.175,-99.600],[19.150,-99.584]];
const V102_risk=[[19.150,-99.584],[19.120,-99.562],[19.090,-99.540]];
const V102_crit=[[19.090,-99.540],[19.050,-99.520]];
const V102_off=[[19.050,-99.520],[19.020,-99.505],[18.990,-99.490],[18.960,-99.475]];

const V101_route=[[19.310,-99.700],[19.290,-99.682],[19.265,-99.665]];
const V103_off=[[19.100,-99.480],[19.070,-99.462],[19.040,-99.445],[19.010,-99.428]];
const V105_route=[[19.200,-99.520],[19.170,-99.498],[19.140,-99.476],[19.110,-99.454]];

// Ruta completa de simulación con perfil térmico (verde → ámbar → rojo → offline)
const SIM_ROUTE=[
  {lat:19.292,lng:-99.657,temp:4.2},{lat:19.272,lng:-99.650,temp:4.5},
  {lat:19.256,lng:-99.643,temp:4.8},{lat:19.240,lng:-99.635,temp:5.1},
  {lat:19.225,lng:-99.626,temp:5.4},{lat:19.210,lng:-99.618,temp:5.7},
  {lat:19.195,lng:-99.610,temp:6.1},{lat:19.180,lng:-99.603,temp:6.4},
  {lat:19.165,lng:-99.595,temp:6.9},{lat:19.155,lng:-99.590,temp:7.3},
  {lat:19.145,lng:-99.585,temp:7.7},{lat:19.133,lng:-99.576,temp:7.9},
  {lat:19.120,lng:-99.562,temp:9.4},{lat:19.107,lng:-99.552,temp:11.2},
  {lat:19.090,lng:-99.540,temp:12.8},{lat:19.070,lng:-99.530,temp:12.1},
  {lat:19.050,lng:-99.520,temp:11.5}
];

const alertPt=[19.060,-99.518];
const ctrlSanMiguel=[19.305,-99.616];
const ctrlLaEsperanza=[19.010,-99.440];

// ── NAVIGATION ──────────────────────────────────────────
function showLogin(){
  document.getElementById('page-landing').style.display='none';
  document.getElementById('page-login').style.display='flex';
  document.getElementById('page-dash').style.display='none';
}
function showLanding(){
  document.getElementById('page-landing').style.display='block';
  document.getElementById('page-login').style.display='none';
  document.getElementById('page-dash').style.display='none';
}
function doLogin(){
  const u=document.getElementById('l-user').value;
  const p=document.getElementById('l-pass').value;
  const err=document.getElementById('l-err');
  if((u==='admin'&&p==='admin123')||u.length>2){
    err.style.display='none';
    document.getElementById('page-login').style.display='none';
    document.getElementById('page-dash').style.display='flex';
    document.getElementById('rfab').style.display='flex';
    setTimeout(()=>{initDashMap();initCharts();startLoop();iaCargarUltimo();},150);
  } else {
    err.style.display='block';
    document.getElementById('l-user').style.borderColor='var(--red)';
  }
}

// ── HERO MAP ─────────────────────────────────────────────
function initHeroMap(){
  heroMap=L.map('hero-map',{zoomControl:false,dragging:false,scrollWheelZoom:false,doubleClickZoom:false}).setView([19.15,-99.57],9);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{attribution:'©CartoDB'}).addTo(heroMap);
  L.polyline([...V102_on,...V101_route],{color:'#22c55e',weight:3.5,opacity:.9}).addTo(heroMap);
  L.polyline(V102_risk,{color:'#f59e0b',weight:3.5,opacity:.85}).addTo(heroMap);
  L.polyline(V102_crit,{color:'#ef4444',weight:3.5,opacity:.9}).addTo(heroMap);
  L.polyline([...V102_off,...V103_off],{color:'#f97316',weight:3,dashArray:'7 5',opacity:.8}).addTo(heroMap);
  addVeh(heroMap,[19.265,-99.665],'V-101','#22c55e');
  addVeh(heroMap,[19.050,-99.520],'V-102','#ef4444',true);
  addVeh(heroMap,[19.020,-99.440],'V-103','#3b82f6');
  addVeh(heroMap,[19.125,-99.460],'V-105','#f59e0b');
  addCtrl(heroMap,ctrlSanMiguel,'San Miguel');
  addCtrl(heroMap,ctrlLaEsperanza,'La Esperanza');
}

// ── DASH MAP ─────────────────────────────────────────────
function initDashMap(){
  if(dashMap)return;
  dashMap=L.map('dash-map',{zoomControl:true}).setView([19.15,-99.58],11);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{attribution:'©CartoDB'}).addTo(dashMap);

  // Legend
  const legend=L.control({position:'topleft'});
  legend.onAdd=()=>{
    const d=L.DomUtil.create('div');
    d.innerHTML=`<div style="background:rgba(10,22,40,.9);border:1px solid #1a3a5c;border-radius:9px;padding:12px 14px;font-size:11px;color:#94a3b8;line-height:1.9;min-width:150px">
      <div style="display:flex;align-items:center;gap:7px"><span style="width:24px;height:3px;background:#22c55e;display:inline-block;border-radius:2px"></span>Conexión Activa</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="width:24px;height:3px;background:#f59e0b;display:inline-block;border-radius:2px"></span>En Riesgo</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="width:24px;height:3px;background:#ef4444;display:inline-block;border-radius:2px"></span>Temperatura Crítica</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="width:24px;height:3px;background:#f97316;border-top:2px dashed #f97316;display:inline-block"></span>Offline / Sin Señal</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="font-size:13px">🚛</span>Vehículo</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="font-size:13px">⚠️</span>Alerta Crítica</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="font-size:13px">📍</span>Punto de Control</div>
      <div style="display:flex;align-items:center;gap:7px"><span style="font-size:13px">🏁</span>Destino</div>
    </div>`;
    return d;
  };
  legend.addTo(dashMap);

  // Destinos fijos (puntos de control geográficos reales)
  addCtrl(dashMap,ctrlSanMiguel,'Puesto de Salud<br>San Miguel');
  addCtrl(dashMap,ctrlLaEsperanza,'Centro de Salud<br>La Esperanza');

  // Mensaje de espera hasta que lleguen datos
  waitMsg=L.control({position:'bottomleft'});
  waitMsg.onAdd=()=>{
    const d=L.DomUtil.create('div');
    d.id='map-wait-msg';
    d.innerHTML='<div style="background:rgba(10,22,40,.9);border:1px solid #1a3a5c;border-radius:8px;padding:10px 14px;font-size:12px;color:#94a3b8"><i class="bi bi-broadcast" style="color:#3b82f6;margin-right:6px"></i>Esperando telemetría del vehículo...</div>';
    return d;
  };
  waitMsg.addTo(dashMap);

  // Botón de simulación de viaje completo
  const simCtrl=L.control({position:'bottomright'});
  simCtrl.onAdd=()=>{
    const d=L.DomUtil.create('div');
    d.style.cssText='margin-bottom:8px';
    d.innerHTML=`
      <button id="btn-sim-start" onclick="startTripSim()" style="display:block;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:12px;box-shadow:0 2px 12px rgba(34,197,94,.45);letter-spacing:.3px;width:100%">
        ▶ Simular Viaje Completo
      </button>
      <button id="btn-sim-stop" onclick="stopTripSim()" style="display:none;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:12px;box-shadow:0 2px 12px rgba(239,68,68,.45);letter-spacing:.3px;width:100%;margin-top:4px">
        ⏹ Finalizar Viaje
      </button>`;
    L.DomEvent.disableClickPropagation(d);
    return d;
  };
  simCtrl.addTo(dashMap);
}

function vIcon(label,color,alert=false){
  const ring=alert?`box-shadow:0 0 0 6px ${color}33,0 0 16px ${color}55`:''
  return L.divIcon({
    html:`<div style="background:${color};color:#fff;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap;${ring};font-family:'Inter',sans-serif">${label}</div>`,
    className:'',iconAnchor:[24,12]
  });
}
function addVeh(map,pos,label,color,alert=false){
  L.marker(pos,{icon:vIcon(label,color,alert)}).bindPopup(`<b>${label}</b>`).addTo(map);
}
function addCtrl(map,pos,label){
  const ic=L.divIcon({html:`<div style="font-size:18px;filter:drop-shadow(0 2px 4px rgba(0,0,0,.5))">📍</div>`,className:'',iconAnchor:[9,18]});
  L.marker(pos,{icon:ic}).bindPopup(label).addTo(map);
}
function addAlert(map,pos,label=''){
  const ic=L.divIcon({
    html:`<div style="background:#ef4444;color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;box-shadow:0 0 0 6px rgba(239,68,68,.25),0 0 16px rgba(239,68,68,.5)">⚠</div>`,
    iconSize:[30,30],iconAnchor:[15,15],className:''
  });
  const m=L.marker(pos,{icon:ic}).bindPopup(`<b>⚠ Alerta Térmica</b>${label?'<br>'+label:''}<br><small>${pos[0].toFixed(4)}, ${pos[1].toFixed(4)}</small>`).addTo(map);
  return m;
}

// ── CHARTS ───────────────────────────────────────────────
function initCharts(){
  // Line chart
  const ctx=document.getElementById('tempChart').getContext('2d');
  tempChart=new Chart(ctx,{
    type:'line',
    data:{
      labels:tLabels,
      datasets:[
        {label:'Temp (°C)',data:[4.2,5.0,5.8,9.5,13.8,7.2,5.4],
          borderColor:function(ctx){
            const v=ctx.dataset.data[ctx.dataIndex];
            return v>8?'#ef4444':'#3b82f6';
          },
          segment:{borderColor:ctx=>{const v=ctx.p1.parsed.y;return v>8?'#ef4444':'#3b82f6';}},
          backgroundColor:'transparent',fill:false,tension:.4,pointRadius:2,borderWidth:2.5
        }
      ]
    },
    options:{
      responsive:true,maintainAspectRatio:false,animation:{duration:300},
      plugins:{legend:{display:false},
        annotation:{annotations:{
          maxL:{type:'line',yMin:8,yMax:8,borderColor:'#ef444488',borderWidth:1,borderDash:[4,3],
            label:{content:'Límite superior (8 °C)',display:true,position:'end',color:'#ef4444',font:{size:9}}},
          minL:{type:'line',yMin:2,yMax:2,borderColor:'#3b82f688',borderWidth:1,borderDash:[4,3],
            label:{content:'Límite inferior (2 °C)',display:true,position:'end',color:'#3b82f6',font:{size:9}}}
        }}
      },
      scales:{
        x:{grid:{color:'rgba(26,58,92,.6)'},ticks:{color:'#475569',font:{family:'JetBrains Mono',size:8}}},
        y:{min:0,max:20,grid:{color:'rgba(26,58,92,.6)'},ticks:{color:'#475569',font:{family:'JetBrains Mono',size:8},stepSize:5}}
      }
    }
  });

  // Donut chart
  const ctx2=document.getElementById('donutChart').getContext('2d');
  donutChart=new Chart(ctx2,{
    type:'doughnut',
    data:{labels:['Seguros','En riesgo','Críticos'],datasets:[{data:[7,3,2],backgroundColor:['#22c55e','#f59e0b','#ef4444'],borderWidth:0,hoverOffset:4}]},
    options:{responsive:false,cutout:'70%',plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`${ctx.label}: ${ctx.formattedValue}`}}},animation:{duration:600}}
  });
}

function changeVehicle(v){
  const presets={
    'V-102':[4.2,5.0,5.8,9.5,13.8,7.2,5.4],
    'V-101':[4.8,5.1,4.9,5.2,5.0,4.8,5.1],
    'V-103':[5.5,5.3,5.6,5.8,5.4,5.7,5.5],
    'V-105':[5.0,5.2,6.1,7.2,7.8,6.5,5.9]
  };
  if(tempChart&&presets[v]){tempChart.data.datasets[0].data=presets[v];tempChart.update();}
}

// ── API CALLS ─────────────────────────────────────────────
function fetchDashboard(){
  fetch(apiUrl('/dashboard'))
    .then(r=>r.json())
    .then(d=>{
      document.getElementById('sc-viajes').textContent = d.viajes_activos ?? 0;
      document.getElementById('sc-lotes').textContent  = d.lotes_transito ?? 0;
      document.getElementById('sc-nosig').textContent  = d.vehiculos_sin_senal ?? 0;
      const ac = d.alertas_criticas ?? 0;
      document.getElementById('sc-alerts').textContent = ac;
      document.getElementById('nav-alert-badge').textContent = ac;
      document.getElementById('tb-nb').textContent = ac;
      alertCount = ac;
      if(d.temp_maxima_24h > 0){
        const tmax = document.getElementById('sc-tmax');
        tmax.textContent = d.temp_maxima_24h.toFixed(1)+'°C';
        tmax.style.color = d.temp_maxima_24h > 8 ? 'var(--red)' : 'var(--green)';
      }
    })
    .catch(()=>{});
}

function fetchAlertas(){
  fetch(apiUrl('/alertas'))
    .then(r=>r.json())
    .then(d=>{
      if(!d.alertas || d.alertas.length===0) return;
      const list = document.getElementById('al-list');
      list.innerHTML = '';
      d.alertas.slice(0,6).forEach(a=>{
        const cls  = a.tipo==='temperatura_critica'?'crit':a.tipo==='temperatura_riesgo'?'warn':'offs';
        const icon = cls==='crit'?'bi-exclamation-triangle-fill':cls==='warn'?'bi-exclamation-circle-fill':'bi-wifi-off';
        const bg   = cls==='crit'?'ab-red':'crit'?'ab-red':cls==='warn'?'ab-amber':'ab-blue';
        const val  = a.valor ? a.valor.toFixed(1)+'°C' : '—';
        const t    = new Date(a.timestamp*1000).toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});
        list.innerHTML += `<div class="al-item ${cls}">
          <div class="al-row1">
            <div class="al-icon-row"><i class="bi ${icon} al-ico"></i><span class="al-type">${a.tipo.replace(/_/g,' ')}</span></div>
            <span class="al-time">Hoy, ${t}</span>
          </div>
          <div class="al-veh">Vehículo ${a.vehiculo_id||'—'}</div>
          <div class="al-med">${a.medicamento||'—'}</div>
          <span class="al-badge ${bg} badge">${val}</span>
        </div>`;

        // ── Notificar por correo alertas nuevas ──────────────────
        notificarAlertaCorreo(a);
      });
    })
    .catch(()=>{});
}

/**
 * Envía una alerta por correo si no ha sido notificada aún en esta sesión.
 * El servidor tiene un cooldown adicional para evitar spam entre sesiones.
 * Solo notifica tipos críticos: temperatura_critica, temperatura_riesgo,
 * puerta_abierta, perdida_senal, lote_comprometido.
 */
function notificarAlertaCorreo(alerta) {
  // Clave única: tipo + id de la alerta (o tipo + timestamp si no hay id)
  const clave = `${alerta.tipo}_${alerta.id || alerta.timestamp}`;

  // Ya notificamos esta alerta en la sesión actual → ignorar
  if (_alertasNotificadas.has(clave)) return;

  // Solo notificar tipos de alerta críticos
  const tiposNotificar = ['temperatura_critica','temperatura_riesgo',
                          'puerta_abierta','perdida_senal','lote_comprometido'];
  if (!tiposNotificar.includes(alerta.tipo)) return;

  _alertasNotificadas.add(clave);

  fetch('/mail/enviar_alerta_correo.php', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      tipo:        alerta.tipo,
      vehiculo:    alerta.vehiculo_id    || '—',
      valor:       alerta.valor          ?? null,
      medicamento: alerta.medicamento    || '—',
      viaje_id:    alerta.viaje_id       || '—',
      descripcion: alerta.descripcion    || alerta.tipo.replace(/_/g,' '),
      lat:         alerta.latitud        ?? null,
      lng:         alerta.longitud       ?? null,
    })
  })
  .then(r => r.json())
  .then(r => { if(r.ok) console.log('[correo]', r.mensaje); })
  .catch(()=>{});
}

// ── MAP DATA FROM API ─────────────────────────────────────
function fetchMapData(){
  if(!dashMap) return;
  if(SIM.active) return; // la simulación controla el mapa directamente
  fetch(apiUrl('/viaje/'+(SIM.tripId||'VJ-2026-047')))
    .then(r=>r.json())
    .then(d=>{
      if(!d.telemetria||d.telemetria.length===0) return;
      if(d.telemetria.length===lastTelCount) return; // sin cambios
      lastTelCount=d.telemetria.length;

      // Ocultar mensaje de espera
      const wm=document.getElementById('map-wait-msg');
      if(wm) wm.style.display='none';

      // Separar segmentos: online vs offline (sincronizado_nube=1)
      const onPts=[],offPts=[];
      d.telemetria.forEach(t=>{
        const pt=[t.latitud_actual,t.longitud_actual];
        if(t.sincronizado_nube) offPts.push(pt);
        else onPts.push(pt);
      });

      // Redibujar líneas
      if(v102Line){dashMap.removeLayer(v102Line);}
      if(v102OffLine){dashMap.removeLayer(v102OffLine);}

      const estado=d.viaje?d.viaje.estado:'activo';
      const onColor=estado==='alerta'?'#ef4444':estado==='sin_senal'?'#f59e0b':'#22c55e';

      if(onPts.length>1)
        v102Line=L.polyline(onPts,{color:onColor,weight:4,opacity:.95}).addTo(dashMap);
      if(offPts.length>1)
        v102OffLine=L.polyline(offPts,{color:'#f97316',weight:4,dashArray:'8 5',opacity:.9}).addTo(dashMap);

      // Mover/crear marcador del vehículo en última posición
      const all=d.telemetria;
      const last=all[all.length-1];
      const lastPos=[last.latitud_actual,last.longitud_actual];
      const isAlert=estado==='alerta'||last.lote_comprometido;
      const markerColor=isAlert?'#ef4444':ruralMode?'#3b82f6':'#22c55e';

      if(vMarker){
        vMarker.setLatLng(lastPos);
        vMarker.setIcon(vIcon('V-102',markerColor,isAlert));
      } else {
        vMarker=L.marker(lastPos,{icon:vIcon('V-102',markerColor,isAlert)})
          .bindPopup(`<b>V-102</b><br>${d.viaje?d.viaje.medicamento:''}<br>GPS: ${lastPos[0].toFixed(4)}, ${lastPos[1].toFixed(4)}`)
          .addTo(dashMap);
        dashMap.setView(lastPos,13);
      }

      // Marcadores ⚠ en coordenadas con temperatura crítica
      v102AlertMarkers.forEach(m=>dashMap.removeLayer(m));
      v102AlertMarkers=[];
      all.filter(t=>t.lote_comprometido).forEach(t=>{
        const m=addAlert(dashMap,[t.latitud_actual,t.longitud_actual],
          t.temperatura_actual.toFixed(1)+'°C');
        v102AlertMarkers.push(m);
      });

      // Actualizar temperatura actual
      curTemp=last.temperatura_actual;
      curLat=last.latitud_actual; curLng=last.longitud_actual;
    })
    .catch(()=>{});
}

// ── TELEMETRY LOOP ────────────────────────────────────────
function startLoop(){
  // Llamada inmediata al arrancar
  fetchDashboard();
  fetchAlertas();
  fetchMapData();
  // Polling al servidor real
  setInterval(fetchDashboard, 5000);
  setInterval(fetchAlertas,   8000);
  setInterval(fetchMapData,   4000);

  // Simulación local de temperatura para la gráfica
  setInterval(()=>{
    if(ruralMode){
      curTemp+=Math.random()*.28+.05;
      queueCount++;
      // GPS offline: si la sim está activa, continúa por SIM_ROUTE; si no, usa V102_off
      if(SIM.active&&SIM.offline){
        if(SIM.stepIdx<SIM_ROUTE.length){
          curLat=SIM_ROUTE[SIM.stepIdx].lat;
          curLng=SIM_ROUTE[SIM.stepIdx].lng;
          SIM.stepIdx++;
        }
      } else {
        offlineWaypointIdx=Math.min(offlineWaypointIdx+0.18,V102_off.length-1);
        const i=Math.floor(offlineWaypointIdx);
        const ni=Math.min(i+1,V102_off.length-1);
        const f=offlineWaypointIdx-i;
        curLat=V102_off[i][0]+(V102_off[ni][0]-V102_off[i][0])*f;
        curLng=V102_off[i][1]+(V102_off[ni][1]-V102_off[i][1])*f;
      }
      offlineLog.push({lat:curLat,lng:curLng,temp:curTemp});
      // Dibujar línea naranja creciente
      if(dashMap&&offlineLog.length>1){
        if(offlinePolyGrowing)dashMap.removeLayer(offlinePolyGrowing);
        offlinePolyGrowing=L.polyline(offlineLog.map(p=>[p.lat,p.lng]),
          {color:'#f97316',weight:4,dashArray:'8 5',opacity:.85}).addTo(dashMap);
      }
      if(dashMap&&vMarker){
        vMarker.setLatLng([curLat,curLng]);
        vMarker.setIcon(vIcon('V-102','#3b82f6',false));
      }
    } else {
      curTemp+=(Math.random()*.4-.2); curTemp=Math.max(3.5,Math.min(curTemp,7.5));
    }
    curTemp=Math.max(0,Math.min(curTemp,19));
    document.getElementById('rfab-q').textContent=queueCount;
  },2000);
}

// ── RURAL TOGGLE ──────────────────────────────────────────
function toggleRural(){
  ruralMode=!ruralMode;
  const fab=document.getElementById('rfab');
  const lbl=document.getElementById('rfab-lbl');
  const sfDot=document.getElementById('sf-dot');
  const sfSys=document.getElementById('sf-sys');

  if(ruralMode){
    fab.classList.add('active');
    lbl.textContent='DESACTIVAR MODO RURAL';
    document.getElementById('tb-con').textContent='Sin señal';
    document.getElementById('tb-con').className='tc-val';
    document.getElementById('tb-con').style.color='var(--red)';
    document.getElementById('sc-nosig').textContent='3';
    document.getElementById('sc-nosig-s').textContent='Sin comunicación';
    sfDot.classList.remove('green');
    sfDot.style.background='var(--red)';
    sfSys.textContent='Sin señal';
    sfSys.style.color='var(--red)';

    offlineLog=[];
    offlineWaypointIdx=0;
    curTemp=Math.max(curTemp,5.8);
    offlineLog.push({lat:curLat,lng:curLng,temp:curTemp});
    if(SIM.active){SIM.offline=true;clearInterval(SIM.intervalId);}
    if(offlineFinalPoly&&dashMap){dashMap.removeLayer(offlineFinalPoly);offlineFinalPoly=null;}

    addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),'Vehículo V-102 entró a zona sin señal','offs','Pérdida de señal','Zona rural','ab-blue','Activo');
  } else {
    triggerSync();
  }
}

function triggerSync(){
  const total=queueCount;
  if(total===0){finishSync();return;}
  document.getElementById('sync-ov').classList.add('show');
  const fill=document.getElementById('sync-fill');
  const ct=document.getElementById('sync-ct');
  let done=0;
  const iv=setInterval(()=>{
    done+=Math.ceil(total/24);
    const pct=Math.min(100,(done/total)*100);
    fill.style.width=pct+'%';
    ct.textContent=`${Math.min(done,total)} / ${total} registros`;
    if(pct>=100){clearInterval(iv);setTimeout(finishSync,500);}
  },55);
}

function finishSync(){
  document.getElementById('sync-ov').classList.remove('show');
  ruralMode=false;
  const fab=document.getElementById('rfab');
  fab.classList.remove('active');
  document.getElementById('rfab-lbl').textContent='ACTIVAR MODO RURAL';
  document.getElementById('tb-con').textContent='Conectado';
  document.getElementById('tb-con').className='tc-val green';
  document.getElementById('tb-con').style.color='';
  document.getElementById('sc-nosig').textContent='2';
  const sfDot=document.getElementById('sf-dot');
  sfDot.classList.add('green');sfDot.style.background='';
  document.getElementById('sf-sys').textContent='Conectado';
  document.getElementById('sf-sys').style.color='';
  document.getElementById('sf-sync').textContent='Hace un momento';
  document.getElementById('tb-sync').textContent='Hace un momento';

  if(dashMap){
    if(offPoly){dashMap.removeLayer(offPoly);offPoly=null;}
    if(offlinePolyGrowing){dashMap.removeLayer(offlinePolyGrowing);offlinePolyGrowing=null;}
    // "Momento drama": dibujar de golpe la ruta completa recorrida sin señal
    const offPts=offlineLog.length>1?offlineLog.map(p=>[p.lat,p.lng]):V102_off;
    offlineFinalPoly=L.polyline(offPts,{color:'#f97316',weight:4,dashArray:'8 5',opacity:.9}).addTo(dashMap);
    // Colocar marcadores ⚠ en coordenadas EXACTAS donde la temp superó el límite
    const TEMP_LIMITE=8;
    let alertasColocadas=0;
    offlineLog.forEach(p=>{
      if(p.temp>TEMP_LIMITE){
        addAlert(dashMap,[p.lat,p.lng],`${p.temp.toFixed(1)}°C — detectado retroactivamente`);
        alertasColocadas++;
      }
    });
    if(alertasColocadas===0){
      const mid=offPts[Math.floor(offPts.length/2)]||[19.050,-99.518];
      addAlert(dashMap,mid,`${curTemp.toFixed(1)}°C — Anomalía detectada en zona offline`);
    }
    if(vMarker&&offPts.length>0){
      const last=offPts[offPts.length-1];
      vMarker.setLatLng(last);
      vMarker.setIcon(vIcon('V-102','#22c55e',false));
    }
  }
  // Enviar batch offline a la API y cerrar viaje
  if(SIM.tripId&&offlineLog.length>0){
    const baseTs=Math.floor(Date.now()/1000)-offlineLog.length*3;
    offlineLog.forEach((p,i)=>{
      fetch('/api/telemetria',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({viaje_id:SIM.tripId,temperatura_actual:p.temp,latitud_actual:p.lat,
          longitud_actual:p.lng,timestamp_lectura_real:baseTs+i*3,sincronizado_nube:1,sensor_puerta:0})
      }).catch(()=>{});
    });
    setTimeout(()=>{
      fetch('/api/viaje/'+SIM.tripId,{method:'PUT',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({estado:'completado'})}).catch(()=>{});
      SIM.active=false; SIM.offline=false;
      const bs=document.getElementById('btn-sim-start'); if(bs)bs.style.display='';
      const be=document.getElementById('btn-sim-stop');  if(be)be.style.display='none';
    },2500);
  }

  const synced=queueCount;
  queueCount=0;
  document.getElementById('rfab-q').textContent=0;
  alertCount+=2;
  document.getElementById('sc-alerts').textContent=alertCount;
  document.getElementById('nav-alert-badge').textContent=alertCount;
  document.getElementById('tb-nb').textContent=alertCount;

  addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),
    `V-102 sincronizó ${synced} registros`,'offs','Sync completado',`${synced} registros procesados`,'ab-blue','OK');

  if(curTemp>8){
    addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),
      'Vehículo V-102','crit','Temperatura crítica','Detectada retroactivamente','ab-red',curTemp.toFixed(1)+'°C por '+Math.floor(queueCount/3||5)+' min');
  }
  // Auto-trigger IA después de sincronización
  setTimeout(()=>{
    iaSetStatus('analyzing','Analizando datos post-sync...');
    iaAnalizar();
  }, 1500);
}

function addAlertItem(time,vehicle,cls,type,med,badgeCls,badgeText){
  const icons={'crit':'bi-exclamation-triangle-fill','warn':'bi-exclamation-circle-fill','offs':'bi-wifi-off'};
  const typeColors={'crit':'var(--red)','warn':'var(--amber)','offs':'var(--blue)'};
  const list=document.getElementById('al-list');
  const d=document.createElement('div');
  d.className=`al-item ${cls}`;
  d.style.animation='fadeUp .3s ease';
  d.innerHTML=`<div class="al-row1"><div class="al-icon-row"><i class="bi ${icons[cls]} al-ico"></i><span class="al-type" style="color:${typeColors[cls]}">${type}</span></div><span class="al-time">${time}</span></div><div class="al-veh">${vehicle}</div><div class="al-med">${med}</div><span class="al-badge ${badgeCls}">${badgeText}</span>`;
  list.insertBefore(d,list.firstChild);
  if(list.children.length>5)list.removeChild(list.lastChild);
}


// ── DASHBOARD VIEWS + CRUD USUARIOS / VEHÍCULOS ───────────
function setTopbar(title, subtitle) {
  const h = document.querySelector('.tb-left h1');
  const p = document.querySelector('.tb-left p');
  if (h) h.textContent = title;
  if (p) p.textContent = subtitle;
}

function showDashView(view, el = null) {
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');

  const resumenItems    = document.querySelectorAll('.view-resumen');
  const usuarios        = document.getElementById('view-usuarios');
  const vehiculos       = document.getElementById('view-vehiculos');
  const medicamentos    = document.getElementById('view-medicamentos');
  const viajesActivos   = document.getElementById('view-viajes-activos');

  usuarios?.classList.remove('active');
  vehiculos?.classList.remove('active');
  medicamentos?.classList.remove('active');
  viajesActivos?.classList.remove('active');
  document.getElementById('view-config')?.classList.remove('active');

  if (view === 'medicamentos') {
    resumenItems.forEach(x => x.classList.add('hidden-resumen'));
    medicamentos?.classList.add('active');
    setTopbar('Medicamentos y Control de Temperatura', 'Catálogo de medicamentos con rangos de temperatura — el sistema genera alertas automáticas por viaje');
    cargarMedicamentos();
    return;
  }

  if (view === 'usuarios') {
    resumenItems.forEach(x => x.classList.add('hidden-resumen'));
    usuarios?.classList.add('active');
    setTopbar('Gestión de Usuarios', 'Alta, consulta, modificación y eliminación de usuarios');
    cargarUsuarios();
    return;
  }

  if (view === 'vehiculos') {
    resumenItems.forEach(x => x.classList.add('hidden-resumen'));
    vehiculos?.classList.add('active');
    setTopbar('Gestión de Vehículos', 'Control administrativo de unidades de transporte');
    cargarVehiculos();
    return;
  }

  if (view === 'viajes-activos') {
    resumenItems.forEach(x => x.classList.add('hidden-resumen'));
    viajesActivos?.classList.add('active');
    setTopbar('Viajes', 'Monitoreo activo e historial de rutas de distribución');
    switchViajesTab('activos');
    return;
  }

  if (view === 'config') {
    resumenItems.forEach(x => x.classList.add('hidden-resumen'));
    document.getElementById('view-config')?.classList.add('active');
    setTopbar('Configuración del Sistema', 'Preferencias, notificaciones y ajustes generales');
    cargarConfig();
    return;
  }

  resumenItems.forEach(x => x.classList.remove('hidden-resumen'));
  setTopbar('Resumen General', 'Monitoreo en tiempo real de la distribución de insumos médicos');
  setTimeout(() => dashMap?.invalidateSize(), 100);
}

function showCrudMsg(id, msg, ok = true) {
  const el = document.getElementById(id);
  if (!el) return;
  el.className = `crud-alert ${ok ? 'ok' : 'err'}`;
  el.textContent = msg;
  setTimeout(() => { el.className = 'crud-alert'; el.textContent = ''; }, 3500);
}

function esc(v) {
  return String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

// USUARIOS
async function cargarUsuarios() {
  const tbody = document.getElementById('tablaUsuarios');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7" class="crud-empty">Cargando usuarios...</td></tr>';
  try {
    const data = await apiRequest('/usuarios');
    const usuarios = data.usuarios || [];
    if (!usuarios.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="crud-empty">No hay usuarios registrados.</td></tr>';
      return;
    }
    tbody.innerHTML = usuarios.map(u => `
      <tr>
        <td>${esc(u.id)}</td>
        <td>${esc(u.nombre)}</td>
        <td>${esc(u.email)}</td>
        <td>${esc(u.telefono || '—')}</td>
        <td>${esc(u.rol)}</td>
        <td><span class="crud-badge ${esc(u.estado)}">${esc(u.estado)}</span></td>
        <td>
          <button class="btn-blue-soft" onclick='editarUsuario(${JSON.stringify(u)})'><i class="bi bi-pencil"></i></button>
          <button class="btn-danger-soft" onclick="eliminarUsuario(${u.id})"><i class="bi bi-trash"></i></button>
        </td>
      </tr>
    `).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="7" class="crud-empty">${esc(err.message)}</td></tr>`;
  }
}

function resetUsuarioForm() {
  document.getElementById('formUsuario')?.reset();
  document.getElementById('u_id').value = '';
  document.getElementById('u_estado_group').style.display = 'none';
  document.getElementById('u_password_group').style.display = 'block';
  document.getElementById('usuario-form-title').textContent = 'Registrar usuario';
  document.getElementById('btnUsuarioGuardar').textContent = 'Guardar';
  document.getElementById('u_rol').value = 'operador';
}

function editarUsuario(u) {
  document.getElementById('u_id').value = u.id;
  document.getElementById('u_nombre').value = u.nombre || '';
  document.getElementById('u_email').value = u.email || '';
  document.getElementById('u_telefono').value = u.telefono || '';
  document.getElementById('u_rol').value = u.rol || 'operador';
  document.getElementById('u_estado').value = u.estado || 'activo';
  document.getElementById('u_estado_group').style.display = 'block';
  document.getElementById('u_password_group').style.display = 'none';
  document.getElementById('usuario-form-title').textContent = 'Modificar usuario';
  document.getElementById('btnUsuarioGuardar').textContent = 'Actualizar';
}

async function eliminarUsuario(id) {
  if (!confirm('¿Eliminar este usuario?')) return;
  try {
    await apiRequest(`/usuarios/${id}`, { method: 'DELETE' });
    showCrudMsg('usuarios-msg', 'Usuario eliminado correctamente.');
    cargarUsuarios();
  } catch (err) {
    showCrudMsg('usuarios-msg', err.message, false);
  }
}

// VEHÍCULOS
// ── MEDICAMENTOS ─────────────────────────────────────────────
async function cargarMedicamentos() {
  const tbody = document.getElementById('tablaMedicamentos');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" class="crud-empty">Cargando medicamentos...</td></tr>';
  try {
    const data = await apiRequest('/medicamentos');
    const meds = data.medicamentos || [];
    if (!meds.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="crud-empty">No hay medicamentos registrados.</td></tr>';
      return;
    }
    tbody.innerHTML = meds.map(m => {
      const rangeColor = m.temp_min < 0 ? 'var(--indigo)' : (m.temp_max <= 8 ? 'var(--blue)' : 'var(--amber)');
      return `
      <tr>
        <td style="font-weight:600"><i class="bi bi-capsule-pill" style="color:${rangeColor};margin-right:5px"></i>${esc(m.nombre)}</td>
        <td>
          <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(59,130,246,.12);color:${rangeColor};border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600">
            <i class="bi bi-thermometer-half"></i> ${m.temp_min} °C — ${m.temp_max} °C
          </span>
        </td>
        <td style="color:var(--muted);font-size:12px">${esc(m.descripcion || '—')}</td>
        <td><span class="crud-badge ${m.activo ? 'activo' : 'inactivo'}">${m.activo ? 'Activo' : 'Inactivo'}</span></td>
        <td>
          <button class="btn-blue-soft" onclick='editarMedicamento(${JSON.stringify(m)})'><i class="bi bi-pencil"></i></button>
          <button class="btn-danger-soft" onclick="eliminarMedicamento(${m.id})"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;
    }).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="5" class="crud-empty">${esc(err.message)}</td></tr>`;
  }
}

function resetMedicamentoForm() {
  document.getElementById('formMedicamento')?.reset();
  document.getElementById('med_id').value = '';
  document.getElementById('med_activo_group').style.display = 'none';
  document.getElementById('med-form-title').textContent = 'Registrar medicamento';
  document.getElementById('btnMedGuardar').textContent = 'Guardar';
}

function editarMedicamento(m) {
  document.getElementById('med_id').value = m.id;
  document.getElementById('med_nombre').value = m.nombre || '';
  document.getElementById('med_descripcion').value = m.descripcion || '';
  document.getElementById('med_temp_min').value = m.temp_min ?? '';
  document.getElementById('med_temp_max').value = m.temp_max ?? '';
  document.getElementById('med_activo').value = m.activo ? '1' : '0';
  document.getElementById('med_activo_group').style.display = 'block';
  document.getElementById('med-form-title').textContent = 'Modificar medicamento';
  document.getElementById('btnMedGuardar').textContent = 'Actualizar';
}

async function eliminarMedicamento(id) {
  if (!confirm('¿Eliminar este medicamento del catálogo?')) return;
  try {
    await apiRequest(`/medicamentos/${id}`, { method: 'DELETE' });
    showCrudMsg('medicamentos-msg', 'Medicamento eliminado correctamente.');
    cargarMedicamentos();
  } catch (err) {
    showCrudMsg('medicamentos-msg', err.message, false);
  }
}

async function cargarVehiculos() {
  const tbody = document.getElementById('tablaVehiculos');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="9" class="crud-empty">Cargando vehículos...</td></tr>';
  try {
    const data = await apiRequest('/vehiculos');
    const vehiculos = data.vehiculos || [];
    if (!vehiculos.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="crud-empty">No hay vehículos registrados.</td></tr>';
      return;
    }
    tbody.innerHTML = vehiculos.map(v => `
      <tr>
        <td>${esc(v.id)}</td>
        <td>${esc(v.placas)}</td>
        <td>${esc(v.marca)}</td>
        <td>${esc(v.modelo)}</td>
        <td>${esc(v.anio || '—')}</td>
        <td>${esc(v.conductor || '—')}</td>
        <td>${esc(v.capacidad || '—')}</td>
        <td><span class="crud-badge ${esc(v.estado)}">${esc(v.estado)}</span></td>
        <td>
          <button class="btn-blue-soft" onclick='editarVehiculo(${JSON.stringify(v)})'><i class="bi bi-pencil"></i></button>
          <button class="btn-danger-soft" onclick="eliminarVehiculo('${esc(v.id)}')"><i class="bi bi-trash"></i></button>
        </td>
      </tr>
    `).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="9" class="crud-empty">${esc(err.message)}</td></tr>`;
  }
}

function resetVehiculoForm() {
  document.getElementById('formVehiculo')?.reset();
  document.getElementById('v_id').value = '';
  document.getElementById('v_estado_group').style.display = 'none';
  document.getElementById('vehiculo-form-title').textContent = 'Registrar vehículo';
  document.getElementById('btnVehiculoGuardar').textContent = 'Guardar';
}

function editarVehiculo(v) {
  document.getElementById('v_id').value = v.id;
  document.getElementById('v_placas').value = v.placas || '';
  document.getElementById('v_marca').value = v.marca || '';
  document.getElementById('v_modelo').value = v.modelo || '';
  document.getElementById('v_anio').value = v.anio || '';
  document.getElementById('v_conductor').value = v.conductor || '';
  document.getElementById('v_capacidad').value = v.capacidad || '';
  document.getElementById('v_estado').value = v.estado || 'activo';
  document.getElementById('v_estado_group').style.display = 'block';
  document.getElementById('vehiculo-form-title').textContent = 'Modificar vehículo';
  document.getElementById('btnVehiculoGuardar').textContent = 'Actualizar';
}

async function eliminarVehiculo(id) {
  if (!confirm('¿Eliminar este vehículo?')) return;
  try {
    await apiRequest(`/vehiculos/${id}`, { method: 'DELETE' });
    showCrudMsg('vehiculos-msg', 'Vehículo eliminado correctamente.');
    cargarVehiculos();
  } catch (err) {
    showCrudMsg('vehiculos-msg', err.message, false);
  }
}

// ── VIAJES (ACTIVOS + HISTORIAL) ──────────────────────────
let _viajesTab = 'activos';

function switchViajesTab(tab) {
  _viajesTab = tab;
  const panelActivos   = document.getElementById('panel-activos');
  const panelHistorial = document.getElementById('panel-historial');
  document.getElementById('tab-activos').classList.toggle('active',   tab === 'activos');
  document.getElementById('tab-historial').classList.toggle('active', tab === 'historial');
  if (panelActivos)   panelActivos.style.display   = tab === 'activos'   ? 'flex' : 'none';
  if (panelHistorial) panelHistorial.style.display = tab === 'historial' ? 'block' : 'none';
  if (tab === 'activos')   cargarViajesActivos();
  if (tab === 'historial') cargarHistorialViajes();
}

function refrescarViajesTab() {
  if (_viajesTab === 'activos')   cargarViajesActivos();
  if (_viajesTab === 'historial') cargarHistorialViajes();
}

async function cargarHistorialViajes() {
  const tbody = document.getElementById('tablaHistorial');
  if (!tbody) return;

  const icon = document.getElementById('va-refresh-icon');
  if (icon) icon.style.animation = 'spin 1s linear infinite';

  try {
    const data   = await apiRequest('/viajes-historial');
    const viajes = data.viajes || [];

    document.getElementById('va-hist-update').textContent =
      'Actualizado: ' + new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

    if (!viajes.length) {
      tbody.innerHTML = `<tr class="va-empty-row"><td colspan="8">
        <i class="bi bi-clock-history" style="font-size:24px;display:block;margin-bottom:8px;color:var(--muted)"></i>
        No hay viajes en el historial.</td></tr>`;
      return;
    }

    tbody.innerHTML = viajes.map(v => {
      // Periodo
      let periodo = '—';
      if (v.primera_lectura && v.ultima_lectura) {
        const fmt = ts => new Date(ts * 1000).toLocaleDateString('es-MX',{day:'2-digit',month:'short',year:'2-digit'});
        periodo = fmt(v.primera_lectura) + ' – ' + fmt(v.ultima_lectura);
      } else if (v.viaje_created) {
        periodo = new Date(v.viaje_created * 1000).toLocaleDateString('es-MX',{day:'2-digit',month:'short',year:'2-digit'});
      }

      // Temp máxima con color
      const tmax = v.temp_maxima != null ? parseFloat(v.temp_maxima) : null;
      const tmaxClass = tmax == null ? 'na' : tmax > v.temp_max ? 'crit' : tmax > v.temp_max * 0.9 ? 'warn' : 'ok';
      const tmaxStr   = tmax != null ? tmax.toFixed(1) + ' °C' : '—';

      // Resultado (alertas/lecturas críticas)
      let resHtml;
      if (!v.total_lecturas) {
        resHtml = '<span class="va-alerta-pill ok"><i class="bi bi-dash"></i> Sin datos</span>';
      } else if (v.lecturas_criticas > 0) {
        resHtml = `<span class="va-alerta-pill crit"><i class="bi bi-exclamation-triangle-fill"></i> ${v.lecturas_criticas} alertas</span>`;
      } else {
        resHtml = '<span class="va-alerta-pill ok"><i class="bi bi-check-circle-fill"></i> Sin alertas</span>';
      }

      // Estado badge
      const est   = (v.viaje_estado || 'otro').toLowerCase();
      const estCls = est === 'completado' ? 'completado' : est === 'cancelado' ? 'cancelado' : 'otro';
      const estIcon = est === 'completado' ? 'bi-check-circle' : est === 'cancelado' ? 'bi-x-circle' : 'bi-archive';

      return `<tr>
        <td>
          <div class="va-veh-cell">
            <span class="va-veh-badge">${esc(v.vehiculo_id)}</span>
            <div>
              <div style="font-size:12px;font-weight:600">${esc(v.placas || '—')}</div>
              <div class="va-veh-sub">${esc((v.marca||'') + ' ' + (v.modelo||'')).trim() || '—'}</div>
            </div>
          </div>
        </td>
        <td>
          <div style="font-size:12px;font-weight:500">${esc(v.viaje_id)}</div>
          <div style="font-size:11px;color:var(--sub);margin-top:2px"><i class="bi bi-capsule" style="font-size:10px"></i> ${esc(v.medicamento)}</div>
        </td>
        <td>
          <div class="va-ruta">
            <span class="va-r-loc">${esc(v.origen)}</span>
            <i class="bi bi-arrow-right va-r-arr"></i>
            <span class="va-r-loc">${esc(v.destino)}</span>
          </div>
        </td>
        <td style="font-size:11px;color:var(--sub);white-space:nowrap">${periodo}</td>
        <td><span class="va-temp ${tmaxClass}" style="font-size:12px">${tmaxStr}</span></td>
        <td style="font-size:12px;color:var(--sub)">${v.total_lecturas || '—'}</td>
        <td>${resHtml}</td>
        <td>
          <span class="va-estado-hist ${estCls}">
            <i class="bi ${estIcon}"></i> ${esc(v.viaje_estado || '—')}
          </span>
        </td>
      </tr>`;
    }).join('');

  } catch (err) {
    tbody.innerHTML = `<tr class="va-empty-row"><td colspan="8">
      <i class="bi bi-exclamation-triangle" style="font-size:22px;display:block;margin-bottom:8px;color:var(--red)"></i>
      ${esc(err.message)}</td></tr>`;
  } finally {
    if (icon) icon.style.animation = '';
  }
}

async function cargarViajesActivos() {
  const tbody = document.getElementById('tablaViajesActivos');
  if (!tbody) return;

  const icon = document.getElementById('va-refresh-icon');
  if (icon) icon.style.animation = 'spin 1s linear infinite';

  try {
    const data  = await apiRequest('/viajes-activos');
    const viajes = data.viajes || [];

    const total    = viajes.length;
    const enRuta   = viajes.filter(v => v.estado_conexion === 'en_ruta').length;
    const detenido = viajes.filter(v => v.estado_conexion === 'detenido').length;
    const sinSenal = viajes.filter(v => ['sin_senal','sin_datos'].includes(v.estado_conexion)).length;

    document.getElementById('va-total').textContent    = total;
    document.getElementById('va-en-ruta').textContent  = enRuta;
    document.getElementById('va-detenido').textContent = detenido;
    document.getElementById('va-sin-senal').textContent= sinSenal;
    const badge = document.getElementById('tab-badge-activos');
    if (badge) badge.textContent = total;
    document.getElementById('va-last-update').textContent =
      'Actualizado: ' + new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

    if (!viajes.length) {
      tbody.innerHTML = `<tr class="va-empty-row"><td colspan="8">
        <i class="bi bi-truck" style="font-size:24px;display:block;margin-bottom:8px;color:var(--muted)"></i>
        No hay viajes activos en este momento.</td></tr>`;
      return;
    }

    tbody.innerHTML = viajes.map(v => {
      const temp      = v.temperatura_actual != null ? parseFloat(v.temperatura_actual) : null;
      const tempClass = temp == null   ? 'na'
                      : temp > v.temp_max ? 'crit'
                      : temp < v.temp_min ? 'warn' : 'ok';
      const tempStr   = temp != null ? temp.toFixed(1) + '°C' : '—';

      const estadoClass = v.estado_conexion === 'en_ruta'   ? 'en-ruta'
                        : v.estado_conexion === 'detenido'  ? 'detenido'
                        : v.estado_conexion === 'sin_senal' ? 'sin-senal' : 'sin-datos';
      const estadoIcon  = v.estado_conexion === 'en_ruta'   ? 'bi-truck'
                        : v.estado_conexion === 'detenido'  ? 'bi-pause-circle'
                        : v.estado_conexion === 'sin_senal' ? 'bi-wifi-off' : 'bi-question-circle';

      let connStr = '—', connClass = '';
      if (v.ultima_lectura) {
        const secAgo = Math.round(Date.now() / 1000 - parseInt(v.ultima_lectura));
        const minAgo = Math.floor(secAgo / 60);
        if (secAgo < 60)       { connStr = 'Hace ' + secAgo + ' seg'; connClass = 'fresh'; }
        else if (minAgo < 60)  connStr = 'Hace ' + minAgo + ' min';
        else                   connStr = 'Hace ' + Math.floor(minAgo/60) + 'h ' + (minAgo%60) + 'm';
      }

      const hasGps = v.latitud_actual != null && v.longitud_actual != null;
      const lat    = hasGps ? parseFloat(v.latitud_actual)  : 0;
      const lng    = hasGps ? parseFloat(v.longitud_actual) : 0;
      const mapBtn = hasGps
        ? `<button class="va-btn-map" onclick="event.stopPropagation();irAVehiculo(${lat},${lng},'${esc(v.vehiculo_id)}')"><i class="bi bi-geo-alt"></i>Ver mapa</button>`
        : `<span style="color:var(--muted);font-size:11px">Sin GPS</span>`;

      const rowClick = hasGps
        ? `onclick="irAVehiculo(${lat},${lng},'${esc(v.vehiculo_id)}')" title="Ver en mapa"`
        : '';

      return `<tr ${rowClick}>
        <td>
          <div class="va-veh-cell">
            <span class="va-veh-badge">${esc(v.vehiculo_id)}</span>
            <div>
              <div style="font-size:12px;font-weight:600">${esc(v.placas || '—')}</div>
              <div class="va-veh-sub">${esc((v.marca||'') + ' ' + (v.modelo||'')).trim() || '—'}</div>
            </div>
          </div>
        </td>
        <td>
          <div style="font-size:12px;font-weight:500;color:var(--text)">${esc(v.viaje_id)}</div>
          <div style="font-size:11px;color:var(--sub);margin-top:2px"><i class="bi bi-capsule" style="font-size:10px"></i> ${esc(v.medicamento)}</div>
        </td>
        <td>
          <div class="va-ruta">
            <span class="va-r-loc">${esc(v.origen)}</span>
            <i class="bi bi-arrow-right va-r-arr"></i>
            <span class="va-r-loc">${esc(v.destino)}</span>
          </div>
        </td>
        <td>
          <div class="va-temp ${tempClass}">${tempStr}</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">Rango: ${v.temp_min}–${v.temp_max} °C</div>
        </td>
        <td>
          <span class="va-estado ${estadoClass}">
            <span class="va-dot"></span>
            <i class="bi ${estadoIcon}"></i>
            ${esc(v.estado_label)}
          </span>
        </td>
        <td><div class="va-last-conn ${connClass}">${connStr}</div></td>
        <td style="font-size:12px">${esc(v.conductor || '—')}</td>
        <td>${mapBtn}</td>
      </tr>`;
    }).join('');

  } catch (err) {
    tbody.innerHTML = `<tr class="va-empty-row"><td colspan="8">
      <i class="bi bi-exclamation-triangle" style="font-size:22px;display:block;margin-bottom:8px;color:var(--red)"></i>
      ${esc(err.message)}</td></tr>`;
  } finally {
    if (icon) icon.style.animation = '';
  }
}

function irAVehiculo(lat, lng, vehiculoId) {
  if (!lat || !lng) return;
  showDashView('resumen');
  document.getElementById('nav-resumen')?.classList.add('active');
  setTimeout(() => {
    if (dashMap) {
      dashMap.invalidateSize();
      dashMap.flyTo([lat, lng], 15, { animate: true, duration: 1.2 });
    }
  }, 250);
}

// ── STATS COUNTER ─────────────────────────────────────────
function countUp(id,target){
  const el=document.getElementById(id);let c=0;
  const iv=setInterval(()=>{c=Math.min(c+Math.ceil(target/50),target);el.textContent=c;if(c>=target)clearInterval(iv);},20);
}

// ══════════════════════════════════════════════════════════
// ── IA PREDICTIVA LOCAL ────────────────────────────────────
// ══════════════════════════════════════════════════════════
// ── NAVEGACIÓN DE VISTAS ─────────────────────────────────
function navPending(name) {
  const t = document.getElementById('toast-pending');
  if (t) { t.querySelector('.tp-name').textContent = name; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 2500); return; }
  const el = document.createElement('div');
  el.id = 'toast-pending';
  el.innerHTML = `<i class="bi bi-hourglass-split"></i> <span class="tp-name">${name}</span>: módulo en desarrollo`;
  el.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e3a5f;color:#f1f5f9;padding:10px 20px;border-radius:8px;font-size:13px;z-index:9999;display:flex;gap:8px;align-items:center;box-shadow:0 4px 16px rgba(0,0,0,.4);transition:opacity .3s;';
  document.body.appendChild(el);
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 2500);
}

function navPage(page){
  document.getElementById('view-resumen').style.display = page==='resumen' ? 'flex' : 'none';
  document.getElementById('view-ia').style.display      = page==='ia'      ? 'flex' : 'none';
  document.getElementById('nav-resumen').classList.toggle('active', page==='resumen');
  document.getElementById('nav-ia').classList.toggle('active',      page==='ia');
  document.querySelector('.topbar h1').textContent = page==='ia' ? 'IA Predictiva Local' : 'Resumen General';
  document.querySelector('.topbar p').textContent  = page==='ia'
    ? 'Análisis de riesgo térmico con Ollama — gemma3:4b'
    : 'Monitoreo en tiempo real de la distribución de insumos médicos';
  if (page === 'resumen') {
    // reset cualquier crud/subvista activa
    document.querySelectorAll('.dash-view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.view-resumen').forEach(v => v.classList.remove('hidden-resumen'));
    setTimeout(() => dashMap?.invalidateSize(), 100);
  }
  if(page==='ia') iaCargarUltimo();
}

const IA_VIAJE_ID = 'VJ-2026-047';
const IA_COLORES  = {
  seguro:'#22c55e', vigilancia:'#3b82f6', preventivo:'#f59e0b',
  alto:'#f97316',   critico:'#ef4444',    comprometido:'#a855f7'
};

function iaAnalizar(){
  const btn = document.getElementById('ia-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Analizando...';
  iaSetStatus('analyzing','Analizando con Ollama...');
  iaShowLoader();

  fetch('/api/ia/analizar-riesgo', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id_viaje: SIM.tripId||IA_VIAJE_ID})
  })
  .then(r => r.json())
  .then(d => {
    if (d.error) { iaShowError(d.error); return; }
    iaRenderResult(d);
    // Trigger auto-análisis si riesgo alto después de sincronizar
    if(['critico','comprometido','alto'].includes(d.analisis?.nivel_riesgo)){
      document.getElementById('sc-alerts').style.color='var(--red)';
    }
  })
  .catch(e => iaShowError('Error de conexión: ' + e.message))
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-analizar';
  });
}

function iaCargarUltimo(){
  fetch('/api/ia/ultimo-analisis?id_viaje=' + (SIM.tripId||IA_VIAJE_ID))
    .then(r => r.json())
    .then(d => {
      if (d.ok && d.analisis) iaRenderResult({
        analisis: {
          nivel_riesgo:         d.analisis.nivel_riesgo,
          titulo_alerta:        d.analisis.titulo_alerta,
          mensaje_dashboard:    d.analisis.mensaje_dashboard,
          resumen_operador:     d.analisis.resumen_operador,
          prediccion:           {estado: d.analisis.prediccion_estado, tiempo_estimado: d.analisis.prediccion_tiempo, motivo: d.analisis.prediccion_motivo},
          posibles_causas:      d.analisis.posibles_causas,
          acciones_recomendadas: d.analisis.acciones_recomendadas,
          confianza:            d.analisis.confianza,
          requiere_revision:    d.analisis.requiere_revision,
        },
        origen:    d.analisis.origen,
        modelo:    d.analisis.modelo_ollama,
        creado_en: d.analisis.creado_en_fmt
      });
    })
    .catch(() => {});
}

function iaRenderResult(d){
  const a      = d.analisis || {};
  const nivel  = a.nivel_riesgo || 'vigilancia';
  const color  = IA_COLORES[nivel] || '#94a3b8';
  const pred   = a.prediccion   || {};
  const recom  = a.acciones_recomendadas || [];

  // Borde coloreado de la tarjeta
  document.getElementById('ia-card').setAttribute('data-riesgo', nivel);

  // Estado dot
  const dot = document.getElementById('ia-dot');
  dot.className = 'ia-status-dot online';
  dot.style.background = color;
  dot.style.boxShadow  = `0 0 6px ${color}88`;

  iaSetStatus('online', d.origen === 'fallback_matematico'
    ? 'Motor matemático base (Ollama no disponible)'
    : `Modelo: ${d.modelo || 'ollama'}`);

  // Subtítulo header
  document.getElementById('ia-hdr-sub').textContent =
    a.titulo_alerta || 'Análisis completado';

  // Cuerpo principal
  document.getElementById('ia-body').innerHTML = `
    <div class="ia-card-body">

      <!-- Columna 1: Nivel de riesgo + predicción -->
      <div style="display:flex;flex-direction:column;gap:12px">
        <div class="ia-metric-block">
          <div class="ia-metric-lbl">Nivel de Riesgo</div>
          <div style="margin-bottom:8px">
            <span class="ia-risk-badge ia-risk-${nivel}">
              <i class="bi bi-shield-exclamation"></i>${nivel.toUpperCase()}
            </span>
          </div>
          <div class="ia-metric-lbl">Confianza</div>
          <div class="ia-metric-val">${a.confianza || '—'}</div>
        </div>
        <div class="ia-metric-block">
          <div class="ia-metric-lbl">Predicción</div>
          <div class="ia-metric-val" style="color:${color};font-size:12px">${pred.estado || '—'}</div>
          <div style="font-size:11px;color:var(--sub);margin-top:4px">${pred.tiempo_estimado || ''}</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">${pred.motivo || ''}</div>
        </div>
      </div>

      <!-- Columna 2: Mensaje + resumen operador -->
      <div class="ia-metric-block" style="display:flex;flex-direction:column;gap:10px">
        <div>
          <div class="ia-metric-lbl">Diagnóstico</div>
          <div class="ia-metric-val" style="font-size:12px;line-height:1.6">${a.mensaje_dashboard || '—'}</div>
        </div>
        <div>
          <div class="ia-metric-lbl">Resumen para operador</div>
          <div style="font-size:11px;color:var(--sub);line-height:1.6">${a.resumen_operador || '—'}</div>
        </div>
        ${a.requiere_revision ? `<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--amber);margin-top:4px"><i class="bi bi-exclamation-triangle"></i>Requiere revisión antes de entrega</div>` : ''}
      </div>

      <!-- Columna 3: Recomendaciones -->
      <div class="ia-metric-block">
        <div class="ia-metric-lbl">Acciones recomendadas</div>
        <ul class="ia-recom-list">
          ${recom.length ? recom.slice(0,5).map(r=>`<li>${r}</li>`).join('') : '<li>Sin recomendaciones</li>'}
        </ul>
      </div>

    </div>`;

  // Footer
  const footer = document.getElementById('ia-footer');
  footer.style.display = 'flex';
  document.getElementById('ia-footer-origen').innerHTML =
    d.origen === 'fallback_matematico'
      ? '<span style="color:var(--amber)"><i class="bi bi-exclamation-circle"></i> IA no disponible — motor matemático base</span>'
      : `<span style="color:var(--green)"><i class="bi bi-check-circle"></i> Ollama · ${d.modelo || ''}</span>`;
  document.getElementById('ia-footer-time').textContent = 'Último análisis: ' + (d.creado_en || new Date().toLocaleString('es-MX'));
}

function iaShowLoader(){
  document.getElementById('ia-body').innerHTML = `
    <div class="ia-loader">
      <div class="ia-loader-spin"></div>
      <div>Analizando telemetría con Ollama…</div>
      <div style="font-size:11px;color:var(--muted)">Esto puede tardar hasta 35 segundos</div>
    </div>`;
}

function iaShowError(msg){
  iaSetStatus('offline','Error al analizar');
  document.getElementById('ia-body').innerHTML = `
    <div class="ia-empty">
      <i class="bi bi-exclamation-triangle" style="font-size:24px;color:var(--amber);display:block;margin-bottom:8px"></i>
      <div style="font-weight:600;margin-bottom:4px">Error en el análisis IA</div>
      <div style="font-size:11px">${msg}</div>
      <div style="font-size:11px;margin-top:8px;color:var(--muted)">Verifica que Ollama esté corriendo:<br><code style="color:var(--blue)">ollama serve</code> y luego <code style="color:var(--blue)">ollama run llama3.2</code></div>
    </div>`;
}

function iaSetStatus(state, txt){
  const dot = document.getElementById('ia-dot');
  dot.className = 'ia-status-dot ' + state;
  dot.style.background = '';
  dot.style.boxShadow  = '';
  document.getElementById('ia-status-txt').textContent = txt;
}

// Disparar IA automáticamente cuando ocurre un batch sync
const _origFinishSync = typeof finishSync === 'function' ? finishSync : null;

// ── INIT ─────────────────────────────────────────────────
window.addEventListener('load',()=>{
  initHeroMap();
  setInterval(()=>{const t=(4.2+Math.random()*2.5).toFixed(1);document.getElementById('h-temp').textContent=t+'°C';},3000);

  const obs=new IntersectionObserver(e=>{
    e.forEach(x=>{if(x.isIntersecting){countUp('l1',284);countUp('l2',47);countUp('l3',23);countUp('l4',1840);obs.disconnect();}});
  });
  obs.observe(document.querySelector('.land-stats'));


  document.getElementById('formUsuario')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('u_id').value;
    const payload = {
      nombre: document.getElementById('u_nombre').value.trim(),
      email: document.getElementById('u_email').value.trim(),
      telefono: document.getElementById('u_telefono').value.trim(),
      rol: document.getElementById('u_rol').value,
      estado: document.getElementById('u_estado').value
    };
    if (!id) payload.password = document.getElementById('u_password').value;
    try {
      await apiRequest(id ? `/usuarios/${id}` : '/usuarios', {
        method: id ? 'PUT' : 'POST',
        body: JSON.stringify(payload)
      });
      showCrudMsg('usuarios-msg', id ? 'Usuario actualizado correctamente.' : 'Usuario registrado correctamente.');
      resetUsuarioForm();
      cargarUsuarios();
    } catch (err) {
      showCrudMsg('usuarios-msg', err.message, false);
    }
  });

  document.getElementById('formVehiculo')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('v_id').value;
    const payload = {
      placas: document.getElementById('v_placas').value.trim(),
      marca: document.getElementById('v_marca').value.trim(),
      modelo: document.getElementById('v_modelo').value.trim(),
      anio: Number(document.getElementById('v_anio').value || 0),
      conductor: document.getElementById('v_conductor').value.trim(),
      capacidad: Number(document.getElementById('v_capacidad').value || 0),
      estado: document.getElementById('v_estado').value
    };
    try {
      await apiRequest(id ? `/vehiculos/${id}` : '/vehiculos', {
        method: id ? 'PUT' : 'POST',
        body: JSON.stringify(payload)
      });
      showCrudMsg('vehiculos-msg', id ? 'Vehículo actualizado correctamente.' : 'Vehículo registrado correctamente.');
      resetVehiculoForm();
      cargarVehiculos();
    } catch (err) {
      showCrudMsg('vehiculos-msg', err.message, false);
    }
  });

  document.getElementById('formMedicamento')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('med_id').value;
    const tMin = parseFloat(document.getElementById('med_temp_min').value);
    const tMax = parseFloat(document.getElementById('med_temp_max').value);
    if (tMin >= tMax) {
      showCrudMsg('medicamentos-msg', 'La temperatura mínima debe ser menor que la máxima.', false);
      return;
    }
    const payload = {
      nombre:      document.getElementById('med_nombre').value.trim(),
      descripcion: document.getElementById('med_descripcion').value.trim(),
      temp_min:    tMin,
      temp_max:    tMax,
      activo:      parseInt(document.getElementById('med_activo').value || '1'),
    };
    try {
      await apiRequest(id ? `/medicamentos/${id}` : '/medicamentos', {
        method: id ? 'PUT' : 'POST',
        body: JSON.stringify(payload)
      });
      showCrudMsg('medicamentos-msg', id ? 'Medicamento actualizado correctamente.' : 'Medicamento registrado correctamente.');
      resetMedicamentoForm();
      cargarMedicamentos();
    } catch (err) {
      showCrudMsg('medicamentos-msg', err.message, false);
    }
  });

  document.getElementById('l-pass').addEventListener('keydown',e=>{if(e.key==='Enter')doLogin();});
  document.getElementById('l-user').addEventListener('input',()=>{
    document.getElementById('l-user').style.borderColor='';
    document.getElementById('l-err').style.display='none';
  });

  // Aplicar tema guardado al cargar
  applyTheme(localStorage.getItem('frioseguro_theme') || 'dark');
});

// ── TEMA ─────────────────────────────────────────────────────────────
function applyTheme(t) {
  document.body.classList.toggle('light-mode', t === 'light');
  document.getElementById('theme-dark')?.classList.toggle('selected', t === 'dark');
  document.getElementById('theme-light')?.classList.toggle('selected', t === 'light');
}
function setTheme(t) {
  localStorage.setItem('frioseguro_theme', t);
  applyTheme(t);
  apiRequest('/config', {method:'POST', body: JSON.stringify({})}).catch(()=>{});
}

// ── CONFIGURACIÓN ────────────────────────────────────────────────────
function toggleTwilio() {
  const p = document.getElementById('twilio-panel');
  const c = document.getElementById('twilio-chevron');
  const open = p.style.display === 'none';
  p.style.display = open ? 'block' : 'none';
  c.className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  c.style.marginLeft = 'auto'; c.style.fontSize = '12px';
}

async function cargarConfig() {
  try {
    const d = await apiRequest('/config');
    const v = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    const c = (id, val) => { const el = document.getElementById(id); if (el) el.checked = val !== '0'; };
    v('cfg-wa-phone',  d.wa_phone  || '');
    v('cfg-wa-apikey', d.wa_apikey || '');
    c('cfg-sms-activo', d.sms_activo ?? '1');
    applyTheme(localStorage.getItem('frioseguro_theme') || 'dark');
  } catch(e) { console.warn('cargarConfig:', e); }
}

function cfgToggle(clave, val) {
  apiRequest('/config', {method:'POST', body: JSON.stringify({[clave]: val ? '1' : '0'})}).catch(()=>{});
}

async function cfgGuardarNotif() {
  const s = id => document.getElementById(id)?.value?.trim() || '';
  const data = {
    wa_phone:   s('cfg-wa-phone'),
    wa_apikey:  s('cfg-wa-apikey'),
    sms_activo: document.getElementById('cfg-sms-activo')?.checked ? '1' : '0',
  };
  try {
    await apiRequest('/config', {method:'POST', body: JSON.stringify(data)});
    cfgMsg('cfg-msg-notif', 'Guardado. Usa "Enviar WhatsApp de prueba" para verificar.', true);
  } catch(e) { cfgMsg('cfg-msg-notif', 'Error al guardar.', false); }
}

async function cfgTestSMS() {
  cfgMsg('cfg-msg-notif', 'Enviando WhatsApp de prueba...', true);
  try {
    const r = await apiRequest('/config/test-sms', {method:'POST', body:'{}'});
    cfgMsg('cfg-msg-notif', r.mensaje, r.ok);
  } catch(e) { cfgMsg('cfg-msg-notif', 'Error de conexión con el servidor.', false); }
}

function cfgMsg(id, msg, ok) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className = 'cfg-msg ' + (ok ? 'ok' : 'err');
  setTimeout(() => { el.className = 'cfg-msg'; }, 4000);
}

// ── SIMULACIÓN DE VIAJE COMPLETO ─────────────────────────────────────────────
function simTempColor(temp){
  if(temp>8.0)return '#ef4444';
  if(temp>6.5)return '#f59e0b';
  return '#22c55e';
}

function simAddMapPoint(lat,lng,temp){
  if(!dashMap)return;
  const color=simTempColor(temp);
  const pt=[lat,lng];
  if(SIM.currentSegColor===null){
    SIM.currentSegColor=color; SIM.currentSegPts=[pt];
  } else if(color!==SIM.currentSegColor){
    const last=SIM.currentSegPts[SIM.currentSegPts.length-1];
    SIM.currentSegColor=color; SIM.currentSegPts=[last,pt];
    const l=L.polyline([...SIM.currentSegPts],{color,weight:4.5,opacity:.97,lineCap:'round',lineJoin:'round'}).addTo(dashMap);
    SIM.onlinePolylines.push(l); SIM.currentSegLine=l;
  } else {
    SIM.currentSegPts.push(pt);
    if(!SIM.currentSegLine&&SIM.currentSegPts.length>=2){
      const l=L.polyline([...SIM.currentSegPts],{color,weight:4.5,opacity:.97,lineCap:'round',lineJoin:'round'}).addTo(dashMap);
      SIM.onlinePolylines.push(l); SIM.currentSegLine=l;
    } else if(SIM.currentSegLine){
      SIM.currentSegLine.setLatLngs([...SIM.currentSegPts]);
    }
  }
}

function simTick(){
  if(!SIM.active||SIM.offline)return;
  if(SIM.stepIdx>=SIM_ROUTE.length){stopTripSim();return;}
  const wp=SIM_ROUTE[SIM.stepIdx];
  const noise=(Math.random()-.5)*.3;
  const temp=parseFloat((wp.temp+noise).toFixed(1));
  curLat=wp.lat; curLng=wp.lng; curTemp=temp;

  simAddMapPoint(wp.lat,wp.lng,temp);

  const color=simTempColor(temp);
  const isAlert=temp>8;
  if(vMarker){
    vMarker.setLatLng([wp.lat,wp.lng]);
    vMarker.setIcon(vIcon('V-102',color,isAlert));
    vMarker.getPopup()&&vMarker.setPopupContent(`<b>V-102</b><br>Vacunas BCG<br>${temp.toFixed(1)}°C<br>${wp.lat.toFixed(4)}, ${wp.lng.toFixed(4)}`);
  } else {
    vMarker=L.marker([wp.lat,wp.lng],{icon:vIcon('V-102',color,isAlert)})
      .bindPopup(`<b>V-102</b><br>Vacunas BCG<br>${temp.toFixed(1)}°C`)
      .addTo(dashMap);
  }

  // Gráfica de temperatura en tiempo real
  if(tempChart){
    const lbl=new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if(tempChart.data.labels.length>20){tempChart.data.labels.shift();tempChart.data.datasets[0].data.shift();}
    tempChart.data.labels.push(lbl);
    tempChart.data.datasets[0].data.push(temp);
    tempChart.update('none');
  }

  // Contadores del dashboard
  document.getElementById('sc-viajes').textContent='1';
  document.getElementById('sc-lotes').textContent='1';
  const tmax=document.getElementById('sc-tmax');
  if(tmax){tmax.textContent=temp.toFixed(1)+'°C';tmax.style.color=isAlert?'var(--red)':'var(--green)';}

  // Enviar telemetría a la API
  if(SIM.tripId){
    fetch('/api/telemetria',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({viaje_id:SIM.tripId,temperatura_actual:temp,latitud_actual:wp.lat,
        longitud_actual:wp.lng,timestamp_lectura_real:Math.floor(Date.now()/1000),
        sincronizado_nube:0,sensor_puerta:0})
    }).catch(()=>{});
  }

  // Alerta al cruzar 8°C (solo la primera vez)
  if(isAlert&&SIM.stepIdx>0&&SIM_ROUTE[SIM.stepIdx-1].temp<=8){
    addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),
      'Vehículo V-102','crit','Temperatura crítica','Vacunas BCG','ab-red',temp.toFixed(1)+'°C');
    alertCount++;
    document.getElementById('sc-alerts').textContent=alertCount;
    document.getElementById('nav-alert-badge').textContent=alertCount;
    document.getElementById('tb-nb').textContent=alertCount;
  }

  SIM.stepIdx++;
  if(SIM.stepIdx%4===0)dashMap.panTo([wp.lat,wp.lng]);
}

async function startTripSim(){
  if(SIM.active)return;

  // Crear vehículo V-102 si no existe (puede fallar si ya existe, se ignora)
  fetch('/api/vehiculos',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({id:'V-102',placas:'EMX-102-FRG',marca:'Mercedes-Benz',
      modelo:'Sprinter',anio:2023,conductor:'Carlos Méndez',capacidad:500})
  }).catch(()=>{});

  // Crear viaje en la BD
  const tripId='VJ-SIM-'+Date.now();
  try{
    await fetch('/api/viaje',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id:tripId,vehiculo_id:'V-102',medicamento:'Vacunas BCG',
        origen:'Toluca, Estado de México',destino:'Sultepec, Estado de México',
        temp_min:2.0,temp_max:8.0})
    });
  }catch(e){}
  SIM.tripId=tripId;

  // Resetear estado
  SIM.active=true; SIM.offline=false; SIM.stepIdx=0;
  SIM.onlinePolylines=[]; SIM.currentSegPts=[]; SIM.currentSegColor=null; SIM.currentSegLine=null;

  // Limpiar mapa de datos anteriores
  if(v102Line){dashMap.removeLayer(v102Line);v102Line=null;}
  if(v102OffLine){dashMap.removeLayer(v102OffLine);v102OffLine=null;}
  v102AlertMarkers.forEach(m=>dashMap.removeLayer(m)); v102AlertMarkers=[];
  if(offlineFinalPoly){dashMap.removeLayer(offlineFinalPoly);offlineFinalPoly=null;}
  if(vMarker){dashMap.removeLayer(vMarker);vMarker=null;}
  offlineLog=[]; offlineWaypointIdx=0; queueCount=0;
  document.getElementById('rfab-q').textContent=0;

  // Limpiar gráfica
  if(tempChart){tempChart.data.labels=[];tempChart.data.datasets[0].data=[];tempChart.update('none');}

  const wm=document.getElementById('map-wait-msg'); if(wm)wm.style.display='none';

  // Centrar mapa en el punto de inicio
  dashMap.flyTo([SIM_ROUTE[0].lat,SIM_ROUTE[0].lng],12,{animate:true,duration:1.5});

  // UI: cambiar botones
  const bs=document.getElementById('btn-sim-start'); if(bs)bs.style.display='none';
  const be=document.getElementById('btn-sim-stop');  if(be)be.style.display='block';

  addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),
    'V-102 — '+tripId,'offs','Viaje iniciado','Toluca → Sultepec','ab-blue','En Ruta');

  simTick();
  SIM.intervalId=setInterval(simTick,3000);
}

function stopTripSim(){
  clearInterval(SIM.intervalId);
  if(SIM.tripId){
    fetch('/api/viaje/'+SIM.tripId,{method:'PUT',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({estado:'completado'})}).catch(()=>{});
  }
  SIM.active=false; SIM.offline=false;
  const bs=document.getElementById('btn-sim-start'); if(bs)bs.style.display='block';
  const be=document.getElementById('btn-sim-stop');  if(be)be.style.display='none';
  addAlertItem('Hoy, '+new Date().toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'}),
    'V-102','offs','Viaje finalizado','Completado y guardado en historial','ab-blue','OK');
}

// ── CERRAR SESIÓN ────────────────────────────────────────────────────
function cerrarSesion() {
  if (!confirm('¿Cerrar sesión?')) return;
  document.getElementById('page-dash').style.display = 'none';
  document.getElementById('rfab').style.display      = 'none';
  document.getElementById('page-login').style.display = 'flex';
  // Limpiar estado
  document.getElementById('l-user').value = '';
  document.getElementById('l-pass').value = '';
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('nav-resumen')?.classList.add('active');
  showDashView('resumen');
}
