<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Analiza treningu – TCX</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>

<style>
body { font-family: Arial, sans-serif; margin: 30px; }
.controls { margin-bottom: 12px; }
label { margin-right: 15px; }
button { margin-left: 15px; padding: 4px 10px; }

.chart-wrap { height: 300px; }
canvas { max-width: 100%; }

select[multiple] { padding: 4px; }

table {
    border-collapse: collapse;
    margin-bottom: 15px;
    font-size: 14px;
}
th, td {
    border: 1px solid #ccc;
    padding: 4px 8px;
    text-align: right;
}
th { background: #f0f0f0; }
td:first-child, th:first-child { text-align: center; }

#lapTable td:nth-child(even),
#lapTable th:nth-child(even) {
    background-color: #f6f6f6;
}

#lapTable th:first-child {
    position: sticky;
    left: 0;
    background: #eee;
    z-index: 2;
    box-shadow: 2px 0 4px rgba(0,0,0,0.1);
}
</style>
</head>
<body>

<h1>Analiza treningu – TCX</h1>

<div class="controls">
<input type="file" id="fileInput" accept=".tcx">

<label>Wygładzanie mocy:
<select id="powerSmooth">
<option value="1">Brak</option>
<option value="3">3 s</option>
<option value="10" selected>10 s</option>
<option value="30">30 s</option>
</select>
</label>

<label>Tolerancja:
<select id="toleranceSelect">
<option value="none">brak</option>
<option value="10">10 %</option>
<option value="20">20 %</option>
<option value="30">30 %</option>
<option value="40" selected>40 %</option>
<option value="50">50 %</option>
<option value="60">60 %</option>
<option value="70">70 %</option>
<option value="80">80 %</option>
<option value="90">90 %</option>
</select>
</label>

<label>Wygładzanie tętna:
<select id="hrSmooth">
<option value="1">Brak</option>
<option value="3">3 s</option>
<option value="5" selected>5 s</option>
<option value="10">10 s</option>
<option value="30">30 s</option>
</select>
</label>

<label>Ignoruj 0 W:
<select id="zeroSelect">
<option value="0">nie ignoruj</option>
<option value="1">≥1</option>
<option value="2">≥2</option>
<option value="3" selected>≥3</option>
</select>
</label>
</div>

<div class="controls">
<label><input type="checkbox" id="showPower" checked> Moc</label>
<label><input type="checkbox" id="showHR" checked> Tętno</label>
<label><input type="checkbox" id="showSpeed"> Prędkość</label>
<label><input type="checkbox" id="showCad"> Kadencja</label>
<button id="resetZoom">Zeruj przybliżenie</button>
</div>

<div class="controls">
<label>Widoczne okrążenia:</label><br>
<select id="lapSelect" multiple size="6" style="min-width:380px"></select>
</div>

<table id="lapTable">
<thead></thead>
<tbody></tbody>
</table>

<div class="chart-wrap">
<canvas id="chart"></canvas>
</div>

<script>
/* ===================== UTIL ===================== */
const avg = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : 0;

/* ===================== STATE ===================== */
let rawData = [];
let lapMarkers = [];
let lapSummaries = [];
let selectedLaps = null;

/* ===================== LAP LABELS PLUGIN ===================== */
const lapLabelsPlugin = {
    id: 'lapLabels',
    afterDraw(chart) {
        if (!lapMarkers.length) return;

        const { ctx, chartArea, scales } = chart;
        const xScale = scales.x;
        if (!xScale) return;

        ctx.save();
        ctx.fillStyle = 'rgba(120,120,120,0.25)';
        ctx.font = 'bold 16px Arial';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';

        const bounds = [...lapMarkers, rawData.at(-1)?.x ?? 0];

        for (let i = 0; i < lapMarkers.length; i++) {
            const start = bounds[i];
            const end = bounds[i + 1];
            const mid = (start + end) / 2;

            if (mid < xScale.min || mid > xScale.max) continue;

            const x = xScale.getPixelForValue(mid);
            const y = chartArea.bottom - 6;   // 👈 tuż nad osią X

            ctx.fillText(`Lap ${i + 1}`, x, y);
        }

        ctx.restore();
    }
};


/* ===================== CHART ===================== */
const chart = new Chart(document.getElementById('chart'), {
type:'line',
data:{datasets:[]},
options:{
maintainAspectRatio:false,
interaction:{mode:'nearest',intersect:false},
plugins:{
legend:{display:false},
zoom:{zoom:{drag:{enabled:true},mode:'x'}}
},
scales:{
x:{type:'linear',title:{display:true,text:'Czas [s]'}},
yPower:{position:'left',title:{display:true,text:'Moc / inne'}},
yHR:{
position:'right',
title:{display:true,text:'Tętno [bpm]'},
grid:{drawOnChartArea:false}
}
}
},
plugins:[lapLabelsPlugin]
});

/* ===================== UI ===================== */
const show = id => document.getElementById(id).checked;
document.getElementById('resetZoom').onclick = () => chart.resetZoom();
document.querySelectorAll('input,select').forEach(e => e.onchange = redraw);
document.getElementById('fileInput').onchange = loadTCX;

/* ===================== LOAD TCX ===================== */
function loadTCX(e){
const reader=new FileReader();
reader.onload=ev=>{
const xml=new DOMParser().parseFromString(ev.target.result,'text/xml');

rawData=[]; lapMarkers=[]; lapSummaries=[];
const tps=xml.getElementsByTagName('Trackpoint');
let start=null;

for(const tp of tps){
const t=tp.getElementsByTagName('Time')[0];
if(!t) continue;
const time=new Date(t.textContent);
if(!start) start=time;

rawData.push({
x:(time-start)/1000,
power:+tp.getElementsByTagName('ns3:Watts')[0]?.textContent ?? null,
hr:+tp.getElementsByTagName('HeartRateBpm')[0]?.getElementsByTagName('Value')[0]?.textContent ?? null,
cad:+tp.getElementsByTagName('Cadence')[0]?.textContent ?? null,
speed:tp.getElementsByTagName('ns3:Speed')[0]
? +tp.getElementsByTagName('ns3:Speed')[0].textContent * 3.6
: null
});
}

for(const lap of xml.getElementsByTagName('Lap')){
const st=new Date(lap.getAttribute('StartTime'));
lapMarkers.push((st-start)/1000);
lapSummaries.push({});
}

buildLapSelect();
redraw();
};
reader.readAsText(e.target.files[0]);
}

/* ===================== LAP MULTISELECT ===================== */
function buildLapSelect(){
const sel=document.getElementById('lapSelect');
sel.innerHTML='';
selectedLaps=null;

const bounds=[...lapMarkers,Infinity];

for(let i=0;i<lapMarkers.length;i++){
const start=bounds[i];
const end=bounds[i+1];
const pts=rawData.filter(p=>p.x>=start && p.x<end && p.power!=null);
const avgP=pts.length?Math.round(avg(pts.map(p=>p.power))):'-';

const dur=Math.round((end===Infinity?pts.at(-1)?.x:end)-start);
const mm=String(Math.floor(dur/60)).padStart(2,'0');
const ss=String(dur%60).padStart(2,'0');

const opt=document.createElement('option');
opt.value=i;
opt.selected=true;
opt.textContent=`Lap ${i+1} | ${mm}:${ss} | ${avgP} W`;
sel.appendChild(opt);
}

sel.onchange=()=>{
selectedLaps=Array.from(sel.selectedOptions).map(o=>+o.value);
redraw();
};
}

/* ===================== REDRAW ===================== */
function redraw(){
chart.data.datasets=[];
if(!rawData.length) return;

const tolerance = toleranceSelect.value === 'none'
? Infinity
: +toleranceSelect.value / 100;

const filtered = filterZeroRuns([...rawData], +zeroSelect.value);
const smoothedPower = smoothPowerPerLap(filtered, lapMarkers, +powerSmooth.value, tolerance);

if(show('showPower')){
chart.data.datasets.push({
data:smoothedPower,
borderColor:'#1f77b4',
borderWidth:2,
pointRadius:0,
yAxisID:'yPower'
});
drawLapAverages(smoothedPower);
}

if(show('showHR')){
chart.data.datasets.push({
data:smoothSimple(rawData,'hr',+hrSmooth.value),
borderColor:'#d62728',
borderWidth:1.5,
pointRadius:0,
yAxisID:'yHR'
});
}

if(show('showSpeed')){
chart.data.datasets.push({
data:rawData.filter(p=>p.speed!=null).map(p=>({x:p.x,y:p.speed})),
borderColor:'#2ca02c',
borderWidth:1.2,
pointRadius:0,
yAxisID:'yPower'
});
}

if(show('showCad')){
chart.data.datasets.push({
data:rawData.filter(p=>p.cad!=null).map(p=>({x:p.x,y:p.cad})),
borderColor:'#ff7f0e',
borderWidth:1.2,
pointRadius:0,
yAxisID:'yPower'
});
}

buildLapTable();
chart.update();
}

/* ===================== HELPERS ===================== */
function smoothSimple(data,key,w){
if(w<=1) return data.filter(p=>p[key]!=null).map(p=>({x:p.x,y:p[key]}));
let out=[],buf=[];
for(const p of data){
if(p[key]==null) continue;
buf.push(p[key]); if(buf.length>w) buf.shift();
out.push({x:p.x,y:avg(buf)});
}
return out;
}

function filterZeroRuns(data,minRun){
if(!minRun) return data;
let out=[],run=[];
for(const p of data){
if(p.power===0) run.push(p);
else{
if(run.length && run.length<minRun) out.push(...run);
run=[]; out.push(p);
}
}
if(run.length && run.length<minRun) out.push(...run);
return out;
}

function smoothPowerPerLap(data,laps,w,tol){
if(w<=1) return data.filter(p=>p.power!=null).map(p=>({x:p.x,y:p.power}));
const bounds=[...laps,Infinity];
let out=[],lap=[],i=0;
for(const p of data){
if(p.x>=bounds[i+1]){
out.push(...smoothLap(lap,w,tol));
lap=[]; i++;
}
if(p.power!=null) lap.push(p);
}
out.push(...smoothLap(lap,w,tol));
return out;
}

function smoothLap(lap,w,tol){
return lap.map((p,i)=>{
const ref=p.power;
let vals=[];
for(let b=w;b>=0;b--){
const s=Math.max(0,i-b);
const e=Math.min(lap.length,i+(w-b)+1);
const slice=lap.slice(s,e).map(x=>x.power);
if(!slice.length) continue;
const a=avg(slice);
if(tol===Infinity || Math.abs(a-ref)/Math.max(a,ref)<=tol) vals.push(a);
}
return {x:p.x,y:vals.length?avg(vals):ref};
});
}

/* ===================== LAP AVG ===================== */
function drawLapAverages(points){
const bounds=[...lapMarkers,Infinity];
let buf=[],i=0;
for(const p of points){
if(p.x>=bounds[i+1]){
renderLapAvg(buf,bounds[i],bounds[i+1]);
buf=[]; i++;
}
buf.push(p);
}
renderLapAvg(buf,bounds[i],bounds[i+1]);
}

function renderLapAvg(points,start,end){
if(!points.length) return;
const a=avg(points.map(p=>p.y));
const realEnd=end===Infinity?points.at(-1).x:end;
chart.data.datasets.push({
data:[{x:start,y:a},{x:realEnd,y:a}],
type:'line',
borderColor:'rgba(120,120,120,0.9)',
backgroundColor:'rgba(120,120,120,0.15)',
fill:'origin',
pointRadius:0,
borderWidth:2,
yAxisID:'yPower'
});
}

/* ===================== LAP TABLE ===================== */
function buildLapTable(){
const table=document.getElementById('lapTable');
const thead=table.querySelector('thead');
const tbody=table.querySelector('tbody');

thead.innerHTML=''; tbody.innerHTML='';
if(!lapMarkers.length) return;

const visible = selectedLaps === null
? lapMarkers.map((_,i)=>i)
: selectedLaps;

const bounds=[...lapMarkers,Infinity];
const laps=[];

for(const i of visible){
const start=bounds[i];
const end=bounds[i+1];
const hrLap=rawData.filter(p=>p.x>=start && p.x<end && p.hr!=null).map(p=>p.hr);
const endHR=hrLap.length?hrLap.at(-1):'-';

laps.push({
label:`Lap ${i+1}`,
endHR
});
}

let head=`<tr><th></th>`;
for(const lap of laps) head+=`<th>${lap.label}</th>`;
head+=`</tr>`;
thead.innerHTML=head;

let row=`<tr><th>HR koniec [bpm]</th>`;
for(const lap of laps) row+=`<td>${lap.endHR}</td>`;
row+=`</tr>`;
tbody.innerHTML=row;
}
</script>

</body>
</html>

