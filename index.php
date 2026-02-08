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

        /* ===== WYSOKOŚĆ WYKRESU (POŁOWA) ===== */
        .chart-wrap {
            height: 300px;
        }

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

        /* ===================== ZEBRA KOLUMN ===================== */
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

<table id="lapTable">
    <thead></thead>
    <tbody></tbody>
</table>

<!-- ===== KONTENER WYKRESU ===== -->
<div class="chart-wrap">
    <canvas id="chart"></canvas>
</div>

<script>
const avg = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : 0;

let rawData = [];
let lapMarkers = [];
let lapSummaries = [];

const chart = new Chart(document.getElementById('chart'), {
    type: 'line',
    data: { datasets: [] },
    options: {
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
            legend: { display: false },
            zoom: { zoom: { drag: { enabled: true }, mode: 'x' } }
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

/* ===== UI ===== */
const show = id => document.getElementById(id).checked;
document.getElementById('resetZoom').onclick = () => chart.resetZoom();
document.querySelectorAll('input,select').forEach(e => e.onchange = redraw);
document.getElementById('fileInput').onchange = loadTCX;

/* ===== LOAD TCX ===== */
function loadTCX(e) {
    const reader = new FileReader();
    reader.onload = ev => {
        const xml = new DOMParser().parseFromString(ev.target.result, 'text/xml');

        rawData = [];
        lapMarkers = [];
        lapSummaries = [];

        const tps = xml.getElementsByTagName('Trackpoint');
        let start = null;

        for (let tp of tps) {
            const t = tp.getElementsByTagName('Time')[0];
            if (!t) continue;
            const time = new Date(t.textContent);
            if (!start) start = time;

            rawData.push({
                x: (time - start) / 1000,
                power: +tp.getElementsByTagName('ns3:Watts')[0]?.textContent ?? null,
                hr: +tp.getElementsByTagName('HeartRateBpm')[0]
                        ?.getElementsByTagName('Value')[0]?.textContent ?? null,
                cad: +tp.getElementsByTagName('Cadence')[0]?.textContent ?? null,
                speed: tp.getElementsByTagName('ns3:Speed')[0]
                    ? +tp.getElementsByTagName('ns3:Speed')[0].textContent * 3.6
                    : null
            });
        }

        const laps = Array.from(xml.getElementsByTagName('Lap'));
        for (let lap of laps) {
            const startTime = new Date(lap.getAttribute('StartTime'));
            const startX = (startTime - start) / 1000;
            lapMarkers.push(startX);

            const lx = lap.getElementsByTagName('ns3:LX')[0];
            lapSummaries.push({
                avgPower: lx?.getElementsByTagName('ns3:AvgWatts')[0]?.textContent
                    ? +lx.getElementsByTagName('ns3:AvgWatts')[0].textContent
                    : null,
                maxPower: lx?.getElementsByTagName('ns3:MaxWatts')[0]?.textContent
                    ? +lx.getElementsByTagName('ns3:MaxWatts')[0].textContent
                    : null,
                avgHR: +lap.getElementsByTagName('AverageHeartRateBpm')[0]
                        ?.getElementsByTagName('Value')[0]?.textContent ?? null,
                maxHR: +lap.getElementsByTagName('MaximumHeartRateBpm')[0]
                        ?.getElementsByTagName('Value')[0]?.textContent ?? null
            });
        }
        redraw();
    };
    reader.readAsText(e.target.files[0]);
}

/* ===== RESZTA LOGIKI: redraw, smoothing, table ===== */
/* (bez zmian – identyczna jak u Ciebie) */
</script>

</body>
</html>
