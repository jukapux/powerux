<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Analiza mocy – TCX (Inteligentne wygładzanie)</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }
        .controls {
            margin-bottom: 15px;
        }
        canvas {
            max-width: 100%;
        }
        label {
            margin-left: 20px;
        }
    </style>
</head>
<body>

<h1>Analiza mocy – TCX</h1>

<div class="controls">
    <input type="file" id="fileInput" accept=".tcx">

    <label>
        Wygładzanie:
        <select id="smoothingSelect">
            <option value="1">Brak</option>
            <option value="3">3 s</option>
            <option value="10">10 s</option>
            <option value="30">30 s</option>
            <option value="60">60 s</option>
            <option value="90">90 s</option>
            <option value="120">120 s</option>
        </select>
    </label>

    <label>
        Tolerancja:
        <select id="toleranceSelect">
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
</div>

<canvas id="powerChart"></canvas>

<script>
const fileInput = document.getElementById('fileInput');
const smoothingSelect = document.getElementById('smoothingSelect');
const toleranceSelect = document.getElementById('toleranceSelect');
const ctx = document.getElementById('powerChart').getContext('2d');

const POWER_COLOR = '#1f77b4';
const LAP_COLOR   = 'rgba(0,0,0,0.35)';

let rawData = [];
let lapMarkers = [];

let chart = new Chart(ctx, {
    type: 'line',
    data: { datasets: [] },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false } },
        scales: {
            x: { type: 'linear', title: { display: true, text: 'Czas [s]' } },
            y: { title: { display: true, text: 'Moc [W]' } }
        }
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) loadTCX(fileInput.files[0]);
});
smoothingSelect.addEventListener('change', redraw);
toleranceSelect.addEventListener('change', redraw);

// ------------------------------------------------------------
// TCX
// ------------------------------------------------------------
function loadTCX(file) {
    const reader = new FileReader();
    reader.onload = e => {
        const xml = new DOMParser().parseFromString(e.target.result, "text/xml");

        const laps = Array.from(xml.getElementsByTagName("Lap")).map((lap, i) => ({
            index: i + 1,
            startTime: new Date(lap.getAttribute("StartTime"))
        }));

        const trackpoints = xml.getElementsByTagName("Trackpoint");

        rawData = [];
        let startTime = null;

        for (let tp of trackpoints) {
            const t = tp.getElementsByTagName("Time")[0];
            const w = tp.getElementsByTagName("ns3:Watts")[0];
            if (!t || !w) continue;

            const time = new Date(t.textContent);
            if (!startTime) startTime = time;

            rawData.push({
                x: (time - startTime) / 1000,
                y: parseInt(w.textContent)
            });
        }

        lapMarkers = laps
            .map(l => ({ x: (l.startTime - startTime) / 1000 }))
            .filter(l => l.x >= 0);

        redraw();
    };
    reader.readAsText(file);
}

// ------------------------------------------------------------
// RYSOWANIE
// ------------------------------------------------------------
function redraw() {
    if (!rawData.length) return;

    const windowSize = parseInt(smoothingSelect.value);
    const tolerance = parseInt(toleranceSelect.value) / 100;

    const smoothed = smoothPerLapSmart(rawData, lapMarkers, windowSize, tolerance);

    chart.data.datasets = [{
        data: smoothed,
        borderColor: POWER_COLOR,
        borderWidth: 2,
        pointRadius: 0
    }];

    const maxY = Math.max(...smoothed.map(p => p.y));

    lapMarkers.forEach(l => {
        chart.data.datasets.push({
            data: [{ x: l.x, y: 0 }, { x: l.x, y: maxY }],
            type: 'line',
            borderColor: LAP_COLOR,
            borderDash: [5, 5],
            borderWidth: 1,
            pointRadius: 0
        });
    });

    chart.update();
}

// ------------------------------------------------------------
// INTELIGENTNE WYGŁADZANIE
// ------------------------------------------------------------
function smoothPerLapSmart(data, laps, windowSize, tolerance) {
    if (windowSize <= 1) return data;

    const boundaries = [...laps.map(l => l.x), Infinity];
    let result = [];
    let lapIndex = 0;
    let buffer = [];

    for (let p of data) {
        if (p.x >= boundaries[lapIndex + 1]) {
            result.push(...smoothLapSmart(buffer, windowSize, tolerance));
            buffer = [];
            lapIndex++;
        }
        buffer.push(p);
    }
    result.push(...smoothLapSmart(buffer, windowSize, tolerance));
    return result;
}

function smoothLapSmart(lap, windowSize, tolerance) {
    let out = [];

    for (let i = 0; i < lap.length; i++) {
        const ref = median([
            lap[i - 1]?.y,
            lap[i]?.y,
            lap[i + 1]?.y
        ].filter(v => v !== undefined));

        const candidates = [];

        for (let back = windowSize; back >= 0; back--) {
            const fwd = windowSize - back;
            const start = Math.max(0, i - back);
            const end = Math.min(lap.length, i + fwd + 1);
            const slice = lap.slice(start, end);

            if (!slice.length) continue;

            const avg = slice.reduce((s, p) => s + p.y, 0) / slice.length;
            const diff = Math.abs(avg - ref) / Math.max(avg, ref);

            if (diff <= tolerance) candidates.push(avg);
        }

        const y = candidates.length
            ? candidates.reduce((s, v) => s + v, 0) / candidates.length
            : lap[i].y;

        out.push({ x: lap[i].x, y });
    }
    return out;
}

function median(arr) {
    arr.sort((a, b) => a - b);
    const m = Math.floor(arr.length / 2);
    return arr.length % 2 ? arr[m] : (arr[m - 1] + arr[m]) / 2;
}
</script>

</body>
</html>
