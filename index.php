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

#lapTable thead th {
    text-align: center;
}


.lap-segment {
    position: absolute;
    top: 0;
    bottom: 0;
    background: #d6d6d6;
    cursor: pointer;
    box-sizing: border-box;
}


.lap-segment.selected {
    background: #9e9e9e;
}


.chart-wrap {
    position: relative;
    height: 300px;
}

.lap-segment:hover {
    background: #c9c9c9;
}

#lapRow {
    position: absolute;
    top: 0;
    left: 0;
    display: flex;
    align-items: center;
}


#lapBar {
    position: absolute;
    height: 8px;
}



#lapLabel {
    font-size: 11px;
    color: #555;
    white-space: nowrap;
    user-select: none;
    margin-right: 6px;
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

<label>Przesunięcie HR:
<select id="hrShift">
    <option value="15">15 s</option>
    <option value="30" selected>30 s</option>
    <option value="45">45 s</option>
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

<table id="lapTable">
<thead></thead>
<tbody></tbody>
</table>

<div class="chart-wrap">
    <canvas id="chart"></canvas>
    <div id="lapRow">
        <div id="lapLabel">Laps</div>
        <div id="lapBar"></div>
    </div>
</div>




<script>
/* ===================== UTIL ===================== */
const avg = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : 0;

function formatTime(sec){
    sec = Math.round(sec);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return h > 0
        ? `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function getTimeStep(totalSec){
    if (totalSec < 3600) return 300;   // 5 min
    if (totalSec < 7200) return 600;   // 10 min
    return 1800;                       // 30 min
}

/* ===================== STATE ===================== */
let rawData = [];
let lapMarkers = [];
let lapSummaries = [];
let selectedLaps = null;

/* ===================== PLUGINS (BEZ ZMIAN) ===================== */
const lapLabelsPlugin = {
    id:'lapLabels',
    afterDraw(chart){
        if(!lapMarkers.length||!rawData.length) return;
        const {ctx,chartArea,scales}=chart;
        const x=scales.x;
        ctx.save();
        ctx.fillStyle='rgba(120,120,120,0.35)';
        ctx.font='bold 14px Arial';
        ctx.textAlign='center';
        ctx.textBaseline='bottom';
        const b=[...lapMarkers,rawData.at(-1).x];
        for(let i=0;i<lapMarkers.length;i++){
            const mid=(b[i]+b[i+1])/2;
            if(mid<x.min||mid>x.max) continue;
            ctx.fillText(`Lap ${i+1}`,x.getPixelForValue(mid),chartArea.bottom-4);
        }
        ctx.restore();
    }
};

const lapHighlightPlugin = {
    id:'lapHighlight',
    beforeDraw(chart){
        if(!selectedLaps?.length) return;
        const {ctx,chartArea,scales}=chart;
        const x=scales.x;
        ctx.save();
        ctx.fillStyle='rgba(120,120,120,0.20)';
        const b=[...lapMarkers,rawData.at(-1).x];
        for(const i of selectedLaps){
            const s=b[i],e=b[i+1];
            if(e<x.min||s>x.max) continue;
            ctx.fillRect(
                x.getPixelForValue(Math.max(s,x.min)),
                chartArea.top,
                x.getPixelForValue(Math.min(e,x.max))-x.getPixelForValue(Math.max(s,x.min)),
                chartArea.bottom-chartArea.top
            );
        }
        ctx.restore();
    }
};

/* ===================== CHART ===================== */
const chart=new Chart(document.getElementById('chart'),{
type:'line',
data:{datasets:[]},
options:{
maintainAspectRatio:false,
interaction:{mode:'nearest',intersect:false},

plugins:{
    legend:{display:false},
    zoom: {
    zoom: {
        drag: { enabled: true },
        mode: 'x',
        onZoomComplete: () => buildLapBar()
    },
    pan: {
        enabled: true,
        mode: 'x',
        onPanComplete: () => buildLapBar()
    }
},
    tooltip:{
        enabled:true,
        mode:'nearest',
        intersect:false,
        callbacks:{
            title(items){
                if (!items.length) return '';
                const t = items[0].parsed.x;
                return `Czas: ${formatTime(t)}`;
            },
            label(){
                return null; // WYŁĄCZAMY domyślne etykiety
            },
            afterBody(items){
                if (!items.length) return [];
                const t = items[0].parsed.x;

                // znajdź punkt z rawData najbliższy temu czasowi
                const p = rawData.reduce((a,b)=>
                    Math.abs(b.x - t) < Math.abs(a.x - t) ? b : a
                );

                const lines = [];

                if (show('showPower') && p.power != null)
                    lines.push(`Moc: ${Math.round(p.power)} W`);

                if (show('showHR') && p.hr != null)
                    lines.push(`Tętno: ${Math.round(p.hr)} bpm`);

                if (show('showSpeed') && p.speed != null)
                    lines.push(`Prędkość: ${Math.round(p.speed)} km/h`);

                if (show('showCad') && p.cad != null)
                    lines.push(`Kadencja: ${Math.round(p.cad)} rpm`);

                return lines;
            }
        }
    }
},


scales:{
x:{type:'linear',title:{display:true,text:'Czas'},ticks:{callback:v=>formatTime(v)}},
yPower:{position:'left',title:{display:true,text:'Moc / inne'}},
yHR:{position:'right',title:{display:true,text:'Tętno [bpm]'},grid:{drawOnChartArea:false}}
}
},
plugins:[lapHighlightPlugin,lapLabelsPlugin]
});




/* ===================== UI ===================== */
const show=id=>document.getElementById(id).checked;
document.getElementById('resetZoom').onclick=()=>chart.resetZoom();
document.querySelectorAll('input,select').forEach(e=>e.onchange=redraw);
document.getElementById('fileInput').onchange=loadTCX;


let lastClickedLap = null;

function handleLapClick(evt, lapIndex) {
    if (!evt.ctrlKey && !evt.shiftKey) {
        selectedLaps = [lapIndex];
    }
    else if (evt.ctrlKey) {
        selectedLaps ??= [];
        if (selectedLaps.includes(lapIndex))
            selectedLaps = selectedLaps.filter(i => i !== lapIndex);
        else
            selectedLaps.push(lapIndex);
    }
    else if (evt.shiftKey && lastClickedLap !== null) {
        const from = Math.min(lastClickedLap, lapIndex);
        const to   = Math.max(lastClickedLap, lapIndex);
        selectedLaps = [];
        for (let i = from; i <= to; i++) selectedLaps.push(i);
    }

    lastClickedLap = lapIndex;
    redraw();
}



/* ===================== LOAD TCX + RESZTA KODU ===================== */
function loadTCX(e){
const reader=new FileReader();
reader.onload=ev=>{
const xml=new DOMParser().parseFromString(ev.target.result,'text/xml');

rawData = [];
lapMarkers = [];
lapSummaries = [];
selectedLaps = null;
lastClickedLap = null;
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
const lx=lap.getElementsByTagName('ns3:LX')[0];
lapSummaries.push({
avgPower: lx?.getElementsByTagName('ns3:AvgWatts')[0]?.textContent ? +lx.getElementsByTagName('ns3:AvgWatts')[0].textContent : null,
maxPower: lx?.getElementsByTagName('ns3:MaxWatts')[0]?.textContent ? +lx.getElementsByTagName('ns3:MaxWatts')[0].textContent : null,
avgHR: +lap.getElementsByTagName('AverageHeartRateBpm')[0]?.getElementsByTagName('Value')[0]?.textContent ?? null,
maxHR: +lap.getElementsByTagName('MaximumHeartRateBpm')[0]?.getElementsByTagName('Value')[0]?.textContent ?? null
});
}

redraw();
};
reader.readAsText(e.target.files[0]);
}


function buildLapBar() {
    const bar = document.getElementById('lapBar');
    bar.innerHTML = '';

    if (!lapMarkers.length || !rawData.length) return;
    if (!chart?.scales?.x || !chart.chartArea) return;

    const xScale = chart.scales.x;
    const chartArea = chart.chartArea;

    const leftPx = chartArea.left;
    const dataEndX = rawData.at(-1).x;
    const rightPx = Math.min(
        xScale.getPixelForValue(dataEndX),
        chartArea.right
    );

    // === pozycja i szerokość paska dokładnie pod osią X ===
    bar.style.position = 'absolute';
    bar.style.left = `${leftPx}px`;
    bar.style.width = `${Math.max(0, rightPx - leftPx)}px`;
    bar.style.height = '8px';

    // === tło paska ===
    const bg = document.createElement('div');
    bg.style.position = 'absolute';
    bg.style.inset = '0';
    bg.style.background = '#e0e0e0';
    bg.style.borderRadius = '3px';
    bg.style.pointerEvents = 'none';
    bar.appendChild(bg);

    // === segmenty lapów ===
    const bounds = [...lapMarkers, dataEndX];

    bounds.slice(0, -1).forEach((start, i) => {
        const end = bounds[i + 1];

        const s = Math.max(start, xScale.min);
        const e = Math.min(end,   xScale.max);
        if (e <= s) return;

        const x1 = xScale.getPixelForValue(s) - leftPx;
        const x2 = xScale.getPixelForValue(e) - leftPx;

        const seg = document.createElement('div');
        seg.className = 'lap-segment';
        seg.style.left = `${x1}px`;
        seg.style.width = `${Math.max(1, x2 - x1)}px`;
        if (i < bounds.length - 2) {
            seg.style.borderRight = '2px solid #fff';
        }
        seg.onclick = evt => handleLapClick(evt, i);

        if (selectedLaps?.includes(i)) {
            seg.classList.add('selected');
        }

        bar.appendChild(seg);
    });
}










/* ===================== REDRAW ===================== */
function redraw() {
    chart.data.datasets = [];
    if (!rawData.length) return;

    // ===================== OŚ CZASU – OKRĄGŁE TICKI =====================
    const totalTime = rawData.at(-1).x;   // sekundy od startu
    chart.options.scales.x.ticks.stepSize = getTimeStep(totalTime);

    // ===================== PARAMETRY =====================
    const tolerance = toleranceSelect.value === 'none'
        ? Infinity
        : +toleranceSelect.value / 100;

    const filtered = filterZeroRuns([...rawData], +zeroSelect.value);
    const smoothedPower = smoothPowerPerLap(
        filtered,
        lapMarkers,
        +powerSmooth.value,
        tolerance
    );

    // ===================== MOC =====================
    if (show('showPower')) {
        chart.data.datasets.push({
            data: smoothedPower,
            borderColor: '#1f77b4',
            borderWidth: 2,
            pointRadius: 0,
            yAxisID: 'yPower'
        });

        drawLapAverages(smoothedPower);
    }

    // ===================== TĘTNO =====================
    if (show('showHR')) {
        chart.data.datasets.push({
            data: smoothSimple(rawData, 'hr', +hrSmooth.value),
            borderColor: '#d62728',
            borderWidth: 1.5,
            pointRadius: 0,
            yAxisID: 'yHR'
        });
    }

    // ===================== PRĘDKOŚĆ =====================
    if (show('showSpeed')) {
        chart.data.datasets.push({
            data: rawData
                .filter(p => p.speed != null)
                .map(p => ({ x: p.x, y: p.speed })),
            borderColor: '#2ca02c',
            borderWidth: 1.2,
            pointRadius: 0,
            yAxisID: 'yPower'
        });
    }

    // ===================== KADENCJA =====================
    if (show('showCad')) {
        chart.data.datasets.push({
            data: rawData
                .filter(p => p.cad != null)
                .map(p => ({ x: p.x, y: p.cad })),
            borderColor: '#ff7f0e',
            borderWidth: 1.2,
            pointRadius: 0,
            yAxisID: 'yPower'
        });
    }

    // ===================== TABELA =====================
    buildLapTable();

    // ===================== UPDATE =====================
    chart.update();
    buildLapBar();
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
    const table = document.getElementById('lapTable');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');

    thead.innerHTML = '';
    tbody.innerHTML = '';
    if (!lapSummaries.length) return;

    const visible = selectedLaps === null
        ? lapSummaries.map((_, i) => i)
        : [...selectedLaps].sort((a, b) => a - b);

    const bounds = [...lapMarkers, Infinity];
    const laps = [];

    // ===== POBRANIE PRZESUNIĘCIA HR =====
    const hrShift = +document.getElementById('hrShift').value; // sekundy

    for (const i of visible) {
        const start = bounds[i];
        const end = bounds[i + 1];

        // ===== STANDARD HR =====
        const hrLap = rawData
            .filter(p => p.x >= start && p.x < end && p.hr != null)
            .map(p => p.hr);

        const avgHR = hrLap.length
            ? Math.round(avg(hrLap))
            : '-';

        // ===== PRZESUNIĘTE HR =====
        const shiftStart = start + hrShift;
        const shiftEnd   = end   + hrShift;

        const hrShifted = rawData
            .filter(p => p.x >= shiftStart && p.x < shiftEnd && p.hr != null)
            .map(p => p.hr);

        const shiftedAvgHR = hrShifted.length
            ? Math.round(avg(hrShifted))
            : '-';

        const s = lapSummaries[i];

        laps.push({
            label: `Lap ${i + 1}`,
            avgPower: s.avgPower ?? '-',
            maxPower: s.maxPower ?? '-',
            avgHR,
            shiftedAvgHR,
            maxHR: s.maxHR ?? '-',
            hrw:
                s.avgPower && avgHR !== '-'
                    ? (avgHR / s.avgPower).toFixed(3)
                    : '-'
        });
    }

    // ===== THEAD =====
    let head = `<tr><th></th>`;
    for (const lap of laps) head += `<th>${lap.label}</th>`;
    head += `</tr>`;
    thead.innerHTML = head;

    // ===== WIERSZE =====
    const rows = [
        { label: 'Śr. moc [W]', key: 'avgPower' },
        { label: 'Śr. HR [bpm]', key: 'avgHR' },
        { label: `Przesunięte Śr. HR (+${hrShift}s)`, key: 'shiftedAvgHR' },
        { label: 'HR / W', key: 'hrw' },
        { label: 'Max HR [bpm]', key: 'maxHR' },
        { label: 'Max moc [W]', key: 'maxPower' }
    ];


    for (const row of rows) {
        let html = `<tr><th>${row.label}</th>`;
        for (const lap of laps) {
            html += `<td>${lap[row.key]}</td>`;
        }
        html += `</tr>`;
        tbody.innerHTML += html;
    }
}

window.addEventListener('resize', () => {
    chart.resize();
    buildLapBar();
});

</script>

</body>
</html>
