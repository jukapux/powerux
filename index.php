<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Analiza mocy – TCX (Forward smoothing per Lap)</title>

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
    </style>
</head>
<body>

<h1>Analiza mocy – TCX</h1>

<div class="controls">
    <input type="file" id="fileInput" accept=".tcx">

    <label style="margin-left:20px;">
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
</div>

<canvas id="powerChart"></canvas>

<script>
const fileInput = document.getElementById('fileInput');
const smoothingSelect = document.getElementById('smoothingSelect');
const ctx = document.getElementById('powerChart').getContext('2d');

let rawData = [];
let lapMarkers = [];

const POWER_COLOR = '#1f77b4';          // 🔵 linia mocy
const LAP_COLOR   = 'rgba(0,0,0,0.35)'; // ⚪ linie LAP

let chart = new Chart(ctx, {
    type: 'line',
    data: { datasets: [] },
    options: {
        responsive: true,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: {
                type: 'linear',
                title: { display: true, text: 'Czas [s]' }
            },
            y: {
                title: { display: true, text: 'Moc [W]' }
            }
        }
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length === 0) return;
    loadTCX(fileInput.files[0]);
});

smoothingSelect.addEventListener('change', applyLapSmoothing);

function loadTCX(file) {
    const reader = new FileReader();

    reader.onload = e => {
        const xml = new DOMParser().parseFromString(e.target.result, "text/xml");

        // ---------- LAPY ----------
        const lapNodes = Array.from(xml.getElementsByTagName("Lap"));
        const laps = lapNodes.map((lap, index) => ({
            index: index + 1,
            startTime: new Date(lap.getAttribute("StartTime"))
        }));

        // ---------- TRACKPOINTY ----------
        const trackpoints = xml.getElementsByTagName("Trackpoint");

        let activityStart = null;
        rawData = [];

        for (let tp of trackpoints) {
            const timeNode = tp.getElementsByTagName("Time")[0];
            const wattsNode = tp.getElementsByTagName("ns3:Watts")[0];

            if (!timeNode || !wattsNode) continue;

            const time = new Date(timeNode.textContent);
            const watts = parseInt(wattsNode.textContent);

            if (!activityStart) activityStart = time;

            rawData.push({
                x: (time - activityStart) / 1000,
                y: watts
            });
        }

        // ---------- MARKERY LAP ----------
        lapMarkers = laps
            .map(l => ({
                lap: l.index,
                x: (l.startTime - activityStart) / 1000
            }))
            .filter(l => l.x >= 0);

        applyLapSmoothing();
    };

    reader.readAsText(file);
}

function applyLapSmoothing() {
    if (rawData.length === 0) return;

    const windowSize = parseInt(smoothingSelect.value);
    let smoothedData = [];

    // ---------- GRANICE LAP ----------
    const lapBoundaries = [...lapMarkers.map(l => l.x), Infinity];
    let lapIndex = 0;
    let currentLapData = [];

    for (let point of rawData) {
        if (point.x >= lapBoundaries[lapIndex + 1]) {
            smoothedData.push(...smoothLapForward(currentLapData, windowSize));
            currentLapData = [];
            lapIndex++;
        }
        currentLapData.push(point);
    }
    smoothedData.push(...smoothLapForward(currentLapData, windowSize));

    chart.data.datasets = [];

    // ---------- LINIA MOCY ----------
    chart.data.datasets.push({
        data: smoothedData,
        borderColor: POWER_COLOR,
        borderWidth: 2,
        pointRadius: 0
    });

    // ---------- LINIE LAP ----------
    const maxY = Math.max(...smoothedData.map(p => p.y));

    lapMarkers.forEach(lap => {
        chart.data.datasets.push({
            data: [
                { x: lap.x, y: 0 },
                { x: lap.x, y: maxY }
            ],
            type: 'line',
            borderColor: LAP_COLOR,
            borderWidth: 1,
            borderDash: [5, 5],
            pointRadius: 0
        });
    });

    chart.update();
}

// =======================================================
// FORWARD-LOOKING ROLLING AVERAGE (bez rampy na starcie)
// =======================================================
function smoothLapForward(lapData, windowSize) {
    if (windowSize <= 1) {
        return lapData.map(p => ({ x: p.x, y: p.y }));
    }

    let result = [];

    for (let i = 0; i < lapData.length; i++) {
        const end = Math.min(lapData.length, i + windowSize);
        const slice = lapData.slice(i, end);
        const avg = slice.reduce((sum, p) => sum + p.y, 0) / slice.length;

        result.push({ x: lapData[i].x, y: avg });
    }

    return result;
}
</script>

</body>
</html>
