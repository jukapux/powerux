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
        .stats {
            margin-top: 15px;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 6px;
            font-size: 14px;
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
    <label><input type="checkbox" id="showCad"> Kadencja</label>
    <label><input type="checkbox" id="showSpeed"> Prędkość</label>

    <button id="resetZoom">Zeruj przybliżenie</button>
</div>

<canvas id="chart"></canvas>

<div id="stats" class="stats">Zaznacz fragment wykresu, aby zobaczyć statystyki.</div>

<script>
/* ===================== FORMAT CZASU ===================== */
function formatTime(sec) {
    sec = Math.floor(sec);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return h > 0
        ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

/* ===================== KONFIG ===================== */
const ctx = document.getElementById('chart').getContext('2d');
const statsBox = document.getElementById('stats');

const COLORS = {
    power: '#1f77b4',
    hr: '#d62728',
    cad: '#ff7f0e',
    speed: '#2ca02c',
    lapAvgLine: 'rgba(120,120,120,0.9)',
    lapAvgFill: 'rgba(120,120,120,0.15)'
};

let rawData = [];
let lapMarkers = [];
let lapAverages = [];

/* ===================== WYKRES ===================== */
const chart = new Chart(ctx, {
    type: 'line',
    data: { datasets: [] },
    options: {
        responsive: true,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
            legend: { display: false },
            zoom: {
                zoom: {
                    drag: { enabled: true },
                    mode: 'x',
                    onZoomComplete: updateStats
                }
            },
            tooltip: {
                callbacks: {
                    title: c => formatTime(c[0].parsed.x)
                }
            }
        },
        scales: {
            x: {
                type: 'linear',
                title: { display: true, text: 'Czas' },
                ticks: { callback: v => formatTime(v) }
            },
            yPower: { position: 'left', title: { display: true, text: 'Moc / inne' } },
            yHR: {
                position: 'right',
                title: { display: true, text: 'Tętno [bpm]' },
                grid: { drawOnChartArea: false }
            }
        }
    }
});

const show = id => document.getElementById(id).checked;
document.getElementById('resetZoom').onclick = () => {
    chart.resetZoom();
    updateStats();
};
document.querySelectorAll('input, select').forEach(el => el.onchange = redraw);
document.getElementById('fileInput').onchange = loadTCX;

/* ===================== TCX ===================== */
function loadTCX(e) {
    const reader = new FileReader();
    reader.onload = ev => {
        const xml = new DOMParser().parseFromString(ev.target.result, "text/xml");
        const tps = xml.getElementsByTagName("Trackpoint");

        rawData = [];
        let start = null;

        for (let tp of tps) {
            const t = tp.getElementsByTagName("Time")[0];
            if (!t) continue;
            const time = new Date(t.textContent);
            if (!start) start = time;

            rawData.push({
                x: (time - start) / 1000,
                power: +tp.getElementsByTagName("ns3:Watts")[0]?.textContent ?? null,
                hr: +tp.getElementsByTagName("HeartRateBpm")[0]?.getElementsByTagName("Value")[0]?.textContent ?? null
            });
        }
        redraw();
    };
    reader.readAsText(e.target.files[0]);
}

/* ===================== RYSOWANIE ===================== */
function redraw() {
    chart.data.datasets = [];
    lapAverages = [];

    const pWindow = +powerSmooth.value;
    const hWindow = +hrSmooth.value;
    const tolerance = toleranceSelect.value === 'none' ? Infinity : +toleranceSelect.value / 100;
    const zeroRun = +zeroSelect.value;

    const filtered = filterZeroRuns([...rawData], zeroRun);
    const smoothedPower = smoothPower(filtered, pWindow, tolerance);
    const smoothedHR = smoothSimple(rawData, 'hr', hWindow);

    if (show('showPower')) {
        chart.data.datasets.push({
            data: smoothedPower,
            borderColor: COLORS.power,
            pointRadius: 0,
            yAxisID: 'yPower'
        });
    }

    if (show('showHR')) {
        chart.data.datasets.push({
            data: smoothedHR,
            borderColor: COLORS.hr,
            pointRadius: 0,
            yAxisID: 'yHR'
        });
    }

    chart.update();
    updateStats();
}

/* ===================== STATYSTYKI ===================== */
function updateStats() {
    if (!rawData.length) return;

    const minX = chart.scales.x.min ?? 0;
    const maxX = chart.scales.x.max ?? rawData.at(-1).x;

    const slice = rawData.filter(p => p.x >= minX && p.x <= maxX);

    if (!slice.length) {
        statsBox.innerHTML = 'Brak danych w zakresie.';
        return;
    }

    let html = `<b>Czas:</b> ${formatTime(maxX - minX)}<br>`;

    if (show('showPower')) {
        const p = slice.map(d => d.power).filter(v => v != null);
        if (p.length)
            html += `<b>Moc:</b> avg ${avg(p)} W, min ${Math.min(...p)}, max ${Math.max(...p)}<br>`;
    }

    if (show('showHR')) {
        const h = slice.map(d => d.hr).filter(v => v != null);
        if (h.length)
            html += `<b>Tętno:</b> avg ${avg(h)} bpm, min ${Math.min(...h)}, max ${Math.max(...h)}<br>`;
    }

    statsBox.innerHTML = html;
}

const avg = arr => Math.round(arr.reduce((a,b)=>a+b,0)/arr.length);

/* ===================== FILTRY ===================== */
function smoothSimple(data, key, w) {
    if (w <= 1) return data.filter(p=>p[key]!=null).map(p=>({x:p.x,y:p[key]}));
    let out=[],buf=[];
    for (let p of data) {
        if (p[key]==null) continue;
        buf.push(p[key]);
        if (buf.length>w) buf.shift();
        out.push({x:p.x,y:avg(buf)});
    }
    return out;
}

function filterZeroRuns(data, minRun) {
    if (minRun===0) return data;
    let out=[],run=[];
    for (let p of data) {
        if (p.power===0) run.push(p);
        else {
            if (run.length && run.length<minRun) out.push(...run);
            run=[];
            out.push(p);
        }
    }
    if (run.length && run.length<minRun) out.push(...run);
    return out;
}

function smoothPower(data, w, tol) {
    if (w<=1) return data.filter(p=>p.power!=null).map(p=>({x:p.x,y:p.power}));
    return data.map((p,i)=>{
        if (p.power==null) return null;
        let vals=[];
        for (let j=Math.max(0,i-w);j<=Math.min(data.length-1,i+w);j++){
            const v=data[j].power;
            if (v!=null){
                const d=Math.abs(v-p.power)/Math.max(v,p.power);
                if (tol===Infinity||d<=tol) vals.push(v);
            }
        }
        return {x:p.x,y:vals.length?avg(vals):p.power};
    }).filter(Boolean);
}
</script>

</body>
</html>
