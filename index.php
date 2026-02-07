<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Analiza mocy – TCX</title>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }
        .controls {
            margin-bottom: 15px;
        }
        label {
            margin-left: 20px;
        }
        button {
            margin-left: 20px;
            padding: 4px 10px;
        }
        canvas {
            max-width: 100%;
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
            <option value="3" selected>3 s</option>
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
        Ignoruj 0 W:
        <select id="zeroSelect">
            <option value="0">nie ignoruj</option>
            <option value="1">≥1</option>
            <option value="2">≥2</option>
            <option value="3" selected>≥3</option>
        </select>
    </label>

    <button id="resetZoom">Zeruj przybliżenie</button>
</div>

<canvas id="powerChart"></canvas>

<script>
const fileInput = document.getElementById('fileInput');
const smoothingSelect = document.getElementById('smoothingSelect');
const toleranceSelect = document.getElementById('toleranceSelect');
const zeroSelect = document.getElementById('zeroSelect');
const resetZoomBtn = document.getElementById('resetZoom');
const ctx = document.getElementById('powerChart').getContext('2d');

const POWER_COLOR = '#1f77b4';
const LAP_COLOR   = 'rgba(0,0,0,0.35)';
const LAP_AVG_LINE = 'rgba(120,120,120,0.9)';
const LAP_AVG_FILL = 'rgba(120,120,120,0.15)';

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
                    label: ctx => {
                        const x = ctx.parsed.x;
                        const lapAvg = lapAverages.find(l => x >= l.start && x < l.end);
                        return [
                            `Moc: ${Math.round(ctx.parsed.y)} W`,
                            lapAvg ? `Śr. Lap: ${Math.round(lapAvg.avg)} W` : ''
                        ];
                    }
                }
            }
        },
        scales: {
            x: { type: 'linear', title: { display: true, text: 'Czas [s]' } },
            y: { title: { display: true, text: 'Moc [W]' } }
        }
    }
});

[fileInput, smoothingSelect, toleranceSelect, zeroSelect]
    .forEach(el => el.addEventListener('change', redraw));

resetZoomBtn.addEventListener('click', () => chart.resetZoom());

// ------------------------------------------------------------
// TCX
// ------------------------------------------------------------
fileInput.addEventListener('change', () => {
    if (!fileInput.files.length) return;

    const reader = new FileReader();
    reader.onload = e => {
        const xml = new DOMParser().parseFromString(e.target.result, "text/xml");

        const laps = Array.from(xml.getElementsByTagName("Lap")).map(l => ({
            x: new Date(l.getAttribute("StartTime"))
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
            .map(l => ({ x: (l.x - startTime) / 1000 }))
            .filter(l => l.x >= 0);

        redraw();
    };
    reader.readAsText(fileInput.files[0]);
});

// ------------------------------------------------------------
// RYSOWANIE
// ------------------------------------------------------------
function redraw() {
    if (!rawData.length) return;

    const windowSize = +smoothingSelect.value;
    const tolVal = toleranceSelect.value;
    const tolerance = tolVal === 'none' ? Infinity : (+tolVal / 100);
    const zeroRun = +zeroSelect.value;

    const filtered = filterZeroRuns(rawData, zeroRun);
    const smoothed = smoothPerLapSmart(filtered, lapMarkers, windowSize, tolerance);

    chart.data.datasets = [];
    lapAverages = [];

    // --- linia mocy ---
    chart.data.datasets.push({
        data: smoothed,
        borderColor: POWER_COLOR,
        borderWidth: 2,
        pointRadius: 0
    });

    // --- średnia per Lap (linia + wypełnienie) ---
    const bounds = [...lapMarkers.map(l => l.x), Infinity];
    let lapIndex = 0;
    let buffer = [];

    for (let p of smoothed) {
        if (p.x >= bounds[lapIndex + 1]) {
            addLapAverage(buffer, bounds[lapIndex], bounds[lapIndex + 1]);
            buffer = [];
            lapIndex++;
        }
        buffer.push(p);
    }
    addLapAverage(buffer, bounds[lapIndex], bounds[lapIndex + 1]);

    // --- linie Lap ---
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

function addLapAverage(points, startX, endX) {
    if (!points.length) return;

    const avg = points.reduce((s, p) => s + p.y, 0) / points.length;
    const realEnd = endX === Infinity ? points[points.length - 1].x : endX;

    lapAverages.push({ start: startX, end: realEnd, avg });

    chart.data.datasets.push({
        data: [
            { x: startX, y: avg },
            { x: realEnd, y: avg }
        ],
        type: 'line',
        borderColor: LAP_AVG_LINE,
        backgroundColor: LAP_AVG_FILL,
        borderWidth: 2,
        fill: 'origin',
        pointRadius: 0
    });
}

// ------------------------------------------------------------
// FILTR 0 W
// ------------------------------------------------------------
function filterZeroRuns(data, minRun) {
    if (minRun === 0) return data;

    let result = [];
    let run = [];

    for (let p of data) {
        if (p.y === 0) run.push(p);
        else {
            if (run.length && run.length < minRun) result.push(...run);
            run = [];
            result.push(p);
        }
    }
    if (run.length && run.length < minRun) result.push(...run);
    return result;
}

// ------------------------------------------------------------
// INTELIGENTNE WYGŁADZANIE
// ------------------------------------------------------------
function smoothPerLapSmart(data, laps, windowSize, tolerance) {
    if (windowSize <= 1) return data;

    const bounds = [...laps.map(l => l.x), Infinity];
    let out = [];
    let lap = [];
    let i = 0;

    for (let p of data) {
        if (p.x >= bounds[i + 1]) {
            out.push(...smoothLapSmart(lap, windowSize, tolerance));
            lap = [];
            i++;
        }
        lap.push(p);
    }
    out.push(...smoothLapSmart(lap, windowSize, tolerance));
    return out;
}

function smoothLapSmart(lap, windowSize, tolerance) {
    let res = [];

    for (let i = 0; i < lap.length; i++) {
        const ref = median([
            lap[i - 1]?.y,
            lap[i]?.y,
            lap[i + 1]?.y
        ].filter(v => v !== undefined));

        let candidates = [];

        for (let back = windowSize; back >= 0; back--) {
            const fwd = windowSize - back;
            const s = Math.max(0, i - back);
            const e = Math.min(lap.length, i + fwd + 1);
            const slice = lap.slice(s, e);
            if (!slice.length) continue;

            const avg = slice.reduce((a, p) => a + p.y, 0) / slice.length;
            const diff = Math.abs(avg - ref) / Math.max(avg, ref);
            if (diff <= tolerance) candidates.push(avg);
        }

        res.push({
            x: lap[i].x,
            y: candidates.length
                ? candidates.reduce((a, v) => a + v, 0) / candidates.length
                : lap[i].y
        });
    }
    return res;
}

function median(a) {
    a.sort((x, y) => x - y);
    const m = Math.floor(a.length / 2);
    return a.length % 2 ? a[m] : (a[m - 1] + a[m]) / 2;
}
</script>

</body>
</html>
