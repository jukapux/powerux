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
.overview-wrap { height: 80px; margin-top: 10px; }

canvas { max-width: 100%; }

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

<label>Ignoruj 0 W:
<select id="zeroSelect">
<option value="0">nie ignoruj</option>
<option value="1">≥1</option>
<option value="2">≥2</option>
<option value="3" selected>≥3</option>
</select>
</label>

<button id="resetZoom">Zeruj przybliżenie</button>
</div>

<table id="lapTable">
<thead></thead>
<tbody></tbody>
</table>

<div class="chart-wrap">
<canvas id="chart"></canvas>
</div>

<div class="overview-wrap">
<canvas id="overview"></canvas>
</div>

<script>
const avg = a => a.length ? a.reduce((x,y)=>x+y,0)/a.length : 0;

let rawData=[], lapMarkers=[], lapSummaries=[];
let overviewChart;

/* ===================== MAIN CHART ===================== */
const chart = new Chart(chartEl = document.getElementById('chart'), {
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
y:{title:{display:true,text:'Moc'}}
}
}
});

/* ===================== OVERVIEW CHART ===================== */
overviewChart = new Chart(document.getElementById('overview'), {
type:'line',
data:{datasets:[]},
options:{
maintainAspectRatio:false,
animation:false,
plugins:{
legend:{display:false},
zoom:{
zoom:{
drag:{enabled:true},
mode:'x',
onZoomComplete({chart}) {
const x = chart.scales.x;
chartMainZoom(x.min, x.max);
}
}
}
},
scales:{
x:{type:'linear',display:false},
y:{display:false}
}
}
});

function chartMainZoom(min,max){
chart.options.scales.x.min=min;
chart.options.scales.x.max=max;
chart.update('none');
}

/* ===================== UI ===================== */
document.getElementById('resetZoom').onclick=()=>{
chart.resetZoom();
overviewChart.resetZoom();
};

document.getElementById('fileInput').onchange=loadTCX;
document.querySelectorAll('select').forEach(e=>e.onchange=redraw);

/* ===================== LOAD TCX ===================== */
function loadTCX(e){
const r=new FileReader();
r.onload=ev=>{
const xml=new DOMParser().parseFromString(ev.target.result,'text/xml');
rawData=[]; lapMarkers=[]; lapSummaries=[];
const tps=xml.getElementsByTagName('Trackpoint');
let start=null;

for(let tp of tps){
const t=tp.getElementsByTagName('Time')[0];
if(!t) continue;
const time=new Date(t.textContent);
if(!start) start=time;
rawData.push({
x:(time-start)/1000,
power:+tp.getElementsByTagName('ns3:Watts')[0]?.textContent||null
});
}

for(const lap of xml.getElementsByTagName('Lap')){
const st=new Date(lap.getAttribute('StartTime'));
lapMarkers.push((st-start)/1000);
lapSummaries.push({});
}
redraw();
};
r.readAsText(e.target.files[0]);
}

/* ===================== REDRAW ===================== */
function redraw(){
chart.data.datasets=[];
overviewChart.data.datasets=[];
if(!rawData.length) return;

const filtered=filterZeroRuns([...rawData],+zeroSelect.value);
const smoothed=smoothPowerPerLap(filtered,lapMarkers,+powerSmooth.value);

chart.data.datasets.push({
data:smoothed,
borderColor:'#1f77b4',
borderWidth:2,
pointRadius:0
});

overviewChart.data.datasets.push({
data:smoothed,
borderColor:'rgba(120,120,120,0.9)',
borderWidth:1,
pointRadius:0
});

chart.update();
overviewChart.resetZoom();
overviewChart.update();
}

/* ===================== UTILS ===================== */
function filterZeroRuns(data,min){
if(!min) return data;
let out=[],run=[];
for(const p of data){
if(p.power===0) run.push(p);
else{
if(run.length && run.length<min) out.push(...run);
run=[]; out.push(p);
}
}
if(run.length && run.length<min) out.push(...run);
return out;
}

function smoothPowerPerLap(data,laps,w){
if(w<=1) return data.filter(p=>p.power!=null).map(p=>({x:p.x,y:p.power}));
const bounds=[...laps,Infinity];
let out=[],lap=[],i=0;
for(const p of data){
if(p.x>=bounds[i+1]){
out.push(...smoothLap(lap,w));
lap=[]; i++;
}
if(p.power!=null) lap.push(p);
}
out.push(...smoothLap(lap,w));
return out;
}

function smoothLap(lap,w){
return lap.map((p,i)=>{
const s=Math.max(0,i-w);
const e=Math.min(lap.length,i+w+1);
return {x:p.x,y:avg(lap.slice(s,e).map(x=>x.power))};
});
}
</script>

</body>
</html>
