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
    </style>
</head>
<body>

<h1>Analiza treningu – TCX</h1>

<div class="controls">
    <input type="file" id="fileInput" accept=".tcx">

    <label>
        Wygładzanie mocy:
        <select id="powerSmooth">
            <option value="1">Brak</option>
            <option value="3" selected>3 s</option>
            <option value="10">10 s</option>
            <option value="30">30 s</option>
        </select>
    </label>

    <label>
        Tolerancja:
        <select id="toleranceSelect">
            <option value="none">brak</option>
            <option value="10">10 %</option>
            <option value="20">20 %</option>
            <option value="30" selected>30 %</option>
            <option value="40">40 %</option>
            <option value="50">50 %</option>
            <option value="60">60 %</option>
            <option value="70">70 %</option>
            <option value="80">80 %</option>
            <option value="90">90 %</option>
        </select>
    </label>

    <label>
        Wygładzanie tętna:
        <select id="hrSmooth">
            <option value="1">Brak</option>
            <option value="3">3 s</option>
            <option value="10" selected>10 s</option>
            <option value="30">30 s</option>
        </select>
    </label>

    <label>
        Ignoruj 0 W:
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

<script>
const ctx = document.getElementById('chart').getContext('2d');

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
                    drag: { enabled: true, backgroundColor: 'rgba(0,0,0,0.1)' },
                    mode: 'x'
                }
            },
            tooltip: {
                callbacks: {
                    title: () => '',
                    label: c => {
                        const p = c.raw;
                        const out = [];

                        if (show('showPower') && p.power != null)
                            out.push(`Moc: ${Math.round(p.power)} W`);
                        if (show('showHR') && p.hr != null)
                            out.push(`Tętno: ${Math.round(p.hr)} bpm`);
                        if (show('showCad') && p.cad != null)
                            out.push(`Kadencja: ${p.cad} rpm`);
                        if (show('showSpeed') && p.speed != null)
                            out.push(`Prędkość: ${p.speed.toFixed(1)} km/h`);

                        const lap = lapAverages.find(l => p.x >= l.start && p.x < l.end);
                        if (lap && show('showPower'))
                            out.push(`Śr. Lap: ${Math.round(lap.avg)} W`);

                        return out;
                    }
                }
            }
        },
        scales: {
            x: { type: 'linear', title: { display: true, text: 'Czas [s]' } },
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
document.getElementById('resetZoom').onclick = () => chart.resetZoom();
document.querySelectorAll('input, select').forEach(el => el.onchange = redraw);
document.getElementById('fileInput').onchange = loadTCX;

/* ---------- TCX ---------- */
function loadTCX(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = ev => {
        const xml = new DOMParser().parseFromString(ev.target.result, "text/xml");

        const laps = Array.from(xml.getElementsByTagName("Lap"))
            .map(l => new Date(l.getAttribute("StartTime")));

        const tps = xml.getElementsByTagName("Trackpoint");
        let start = null;
        rawData = [];

        for (let tp of tps) {
            const t = tp.getElementsByTagName("Time")[0];
            if (!t) continue;

            const time = new Date(t.textContent);
            if (!start) start = time;

            rawData.push({
                x: (time - start) / 1000,
                power: +tp.getElementsByTagName("ns3:Watts")[0]?.textContent || null,
                hr: +tp.getElementsByTagName("HeartRateBpm")[0]
                        ?.getElementsByTagName("Value")[0]?.textContent || null,
                cad: +tp.getElementsByTagName("Cadence")[0]?.textContent || null,
                speed: tp.getElementsByTagName("ns3:Speed")[0]
                        ? +tp.getElementsByTagName("ns3:Speed")[0].textContent * 3.6
                        : null
            });
        }

        lapMarkers = laps.map(l => (l - start) / 1000).filter(x => x >= 0);
        redraw();
    };
    reader.readAsText(file);
}

/* ---------- RYSOWANIE ---------- */
function redraw() {
    chart.data.datasets = [];
    lapAverages = [];
    if (!rawData.length) return;

    const pWindow = +powerSmooth.value;
    const hWindow = +hrSmooth.value;
    const tolVal = toleranceSelect.value;
    const tolerance = tolVal === 'none' ? Infinity : (+tolVal / 100);
    const zeroRun = +zeroSelect.value;

    const filtered = filterZeroRuns(rawData, zeroRun);
    const smoothedPower = smoothPowerPerLap(filtered, lapMarkers, pWindow, tolerance);
    const smoothedHR = smoothSimple(rawData, 'hr', hWindow);

    if (show('showPower')) {
        chart.data.datasets.push({
            data: smoothedPower,
            borderColor: COLORS.power,
            borderWidth: 2,
            pointRadius: 0,
            yAxisID: 'yPower'
        });
        drawLapAverages(smoothedPower);
    }

    if (show('showHR')) {
        chart.data.datasets.push({
            data: smoothedHR,
            borderColor: COLORS.hr,
            borderWidth: 1.5,
            pointRadius: 0,
            yAxisID: 'yHR'
        });
    }

    if (show('showCad')) addSimple('cad', COLORS.cad, 'yPower');
    if (show('showSpeed')) addSimple('speed', COLORS.speed, 'yPower');

    chart.update();
}

/* ---------- POMOCNICZE ---------- */
function addSimple(key, color, axis) {
    chart.data.datasets.push({
        data: rawData.filter(p => p[key] != null)
            .map(p => ({ x: p.x, y: p[key], ...p })),
        borderColor: color,
        borderWidth: 1.2,
        pointRadius: 0,
        yAxisID: axis
    });
}

function smoothSimple(data, key, windowSize) {
    if (windowSize <= 1) {
        return data.filter(p => p[key] != null)
            .map(p => ({ x: p.x, y: p[key], ...p }));
    }

    let out = [], buf = [];
    for (let p of data) {
        if (p[key] == null) continue;
        buf.push(p[key]);
        if (buf.length > windowSize) buf.shift();
        out.push({ x: p.x, y: buf.reduce((a,v)=>a+v,0)/buf.length, ...p });
    }
    return out;
}

function filterZeroRuns(data, minRun) {
    if (minRun === 0) return data;
    let out = [], run = [];
    for (let p of data) {
        if (p.power === 0) run.push(p);
        else {
            if (run.length && run.length < minRun) out.push(...run);
            run = [];
            out.push(p);
        }
    }
    if (run.length && run.length < minRun) out.push(...run);
    return out;
}

/* ---------- MOC ---------- */
function smoothPowerPerLap(data, laps, windowSize, tolerance) {
    if (windowSize <= 1) {
        return data.filter(p => p.power != null)
            .map(p => ({ x: p.x, y: p.power, ...p }));
    }

    const bounds = [...laps, Infinity];
    let out = [], lap = [], i = 0;

    for (let p of data) {
        if (p.x >= bounds[i + 1]) {
            out.push(...smoothLap(lap, windowSize, tolerance));
            lap = [];
            i++;
        }
        if (p.power != null) lap.push(p);
    }
    out.push(...smoothLap(lap, windowSize, tolerance));
    return out;
}

function smoothLap(lap, windowSize, tolerance) {
    let res = [];
    for (let i = 0; i < lap.length; i++) {
        const ref = lap[i].power;
        let candidates = [];

        for (let back = windowSize; back >= 0; back--) {
            const fwd = windowSize - back;
            const s = Math.max(0, i - back);
            const e = Math.min(lap.length, i + fwd + 1);
            const slice = lap.slice(s, e);
            if (!slice.length) continue;

            const avg = slice.reduce((a,p)=>a+p.power,0)/slice.length;
            if (tolerance === Infinity || ref == null || ref <= 0) {
                candidates.push(avg);
            } else {
                const diff = Math.abs(avg-ref)/Math.max(avg,ref);
                if (diff <= tolerance) candidates.push(avg);
            }
        }

        res.push({
            x: lap[i].x,
            y: candidates.length
                ? candidates.reduce((a,v)=>a+v,0)/candidates.length
                : ref,
            ...lap[i]
        });
    }
    return res;
}

/* ---------- LAP AVG ---------- */
function drawLapAverages(points) {
    const bounds = [...lapMarkers, Infinity];
    let buf = [], i = 0;
    for (let p of points) {
        if (p.x >= bounds[i + 1]) {
            renderLapAvg(buf, bounds[i], bounds[i + 1]);
            buf = [];
            i++;
        }
        buf.push(p);
    }
    renderLapAvg(buf, bounds[i], bounds[i + 1]);
}

function renderLapAvg(points, start, end) {
    if (!points.length) return;
    const avg = points.reduce((s,p)=>s+p.y,0)/points.length;
    const realEnd = end === Infinity ? points[points.length-1].x : end;

    lapAverages.push({ start, end: realEnd, avg });

    chart.data.datasets.push({
        data: [{x:start,y:avg},{x:realEnd,y:avg}],
        type: 'line',
        borderColor: COLORS.lapAvgLine,
        backgroundColor: COLORS.lapAvgFill,
        fill: 'origin',
        borderWidth: 2,
        pointRadius: 0,
        yAxisID: 'yPower'
    });
}
</script>

</body>
</html>
