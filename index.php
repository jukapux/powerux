<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Analiza treningu – TCX</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }
        .controls {
            margin-bottom: 12px;
        }
        label {
            margin-right: 15px;
        }
        button {
            margin-left: 15px;
            padding: 4px 10px;
        }
        canvas {
            max-width: 100%;
        }
    </style>
</head>
<body>

<h1>Analiza treningu – TCX</h1>

<div class="controls">
    <input type="file" id="fileInput" accept=".tcx">

    <label><input type="checkbox" id="showPower" checked> Moc</label>
    <label><input type="checkbox" id="showHR" checked> Tętno</label>
    <label><input type="checkbox" id="showCad" checked> Kadencja</label>
    <label><input type="checkbox" id="showSpeed" checked> Prędkość</label>

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

let dataAll = [];
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
                    label: ctx => {
                        const p = ctx.raw;
                        const labels = [];
                        if (document.getElementById('showPower').checked && p.power != null)
                            labels.push(`Moc: ${Math.round(p.power)} W`);
                        if (document.getElementById('showHR').checked && p.hr != null)
                            labels.push(`Tętno: ${p.hr} bpm`);
                        if (document.getElementById('showCad').checked && p.cad != null)
                            labels.push(`Kadencja: ${p.cad} rpm`);
                        if (document.getElementById('showSpeed').checked && p.speed != null)
                            labels.push(`Prędkość: ${p.speed.toFixed(1)} km/h`);

                        const lap = lapAverages.find(l => p.x >= l.start && p.x < l.end);
                        if (lap && document.getElementById('showPower').checked)
                            labels.push(`Śr. Lap: ${Math.round(lap.avg)} W`);

                        return labels;
                    }
                }
            }
        },
        scales: {
            x: { type: 'linear', title: { display: true, text: 'Czas [s]' } },
            y: { title: { display: true, text: 'Wartość' } }
        }
    }
});

document.getElementById('resetZoom').onclick = () => chart.resetZoom();
document.querySelectorAll('input[type=checkbox]').forEach(cb => cb.onchange = redraw);
document.getElementById('fileInput').onchange = loadTCX;

// ------------------------------------------------------------
// TCX
// ------------------------------------------------------------
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
        dataAll = [];

        for (let tp of tps) {
            const t = tp.getElementsByTagName("Time")[0];
            if (!t) continue;
            const time = new Date(t.textContent);
            if (!start) start = time;
            const x = (time - start) / 1000;

            const watts = tp.getElementsByTagName("ns3:Watts")[0];
            const hr = tp.getElementsByTagName("HeartRateBpm")[0]?.getElementsByTagName("Value")[0];
            const cad = tp.getElementsByTagName("Cadence")[0];
            const speed = tp.getElementsByTagName("ns3:Speed")[0];

            dataAll.push({
                x,
                power: watts ? +watts.textContent : null,
                hr: hr ? +hr.textContent : null,
                cad: cad ? +cad.textContent : null,
                speed: speed ? (+speed.textContent * 3.6) : null
            });
        }

        lapMarkers = laps.map(l => (l - start) / 1000).filter(x => x >= 0);
        redraw();
    };
    reader.readAsText(file);
}

// ------------------------------------------------------------
// RYSOWANIE
// ------------------------------------------------------------
function redraw() {
    chart.data.datasets = [];
    lapAverages = [];

    if (!dataAll.length) return;

    const show = id => document.getElementById(id).checked;

    if (show('showPower')) {
        chart.data.datasets.push({
            data: dataAll.map(p => ({ x: p.x, y: p.power, ...p })),
            borderColor: COLORS.power,
            borderWidth: 2,
            pointRadius: 0
        });
        addLapAverages();
    }

    if (show('showHR'))
        addSimpleDataset('hr', COLORS.hr);
    if (show('showCad'))
        addSimpleDataset('cad', COLORS.cad);
    if (show('showSpeed'))
        addSimpleDataset('speed', COLORS.speed);

    chart.update();
}

function addSimpleDataset(key, color) {
    chart.data.datasets.push({
        data: dataAll.filter(p => p[key] != null)
            .map(p => ({ x: p.x, y: p[key], ...p })),
        borderColor: color,
        borderWidth: 1.5,
        pointRadius: 0
    });
}

function addLapAverages() {
    const bounds = [...lapMarkers, Infinity];
    let buf = [], i = 0;

    for (let p of dataAll) {
        if (p.x >= bounds[i + 1]) {
            drawLapAvg(buf, bounds[i], bounds[i + 1]);
            buf = [];
            i++;
        }
        if (p.power != null) buf.push(p);
    }
    drawLapAvg(buf, bounds[i], bounds[i + 1]);
}

function drawLapAvg(points, start, end) {
    if (!points.length) return;
    const avg = points.reduce((s, p) => s + p.power, 0) / points.length;
    const realEnd = end === Infinity ? points[points.length - 1].x : end;

    lapAverages.push({ start, end: realEnd, avg });

    chart.data.datasets.push({
        data: [
            { x: start, y: avg },
            { x: realEnd, y: avg }
        ],
        type: 'line',
        borderColor: COLORS.lapAvgLine,
        backgroundColor: COLORS.lapAvgFill,
        fill: 'origin',
        borderWidth: 2,
        pointRadius: 0
    });
}
</script>

</body>
</html>
