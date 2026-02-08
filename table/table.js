// table/table.js

export function buildLapTable({
    lapMarkers,
    lapSummaries,
    rawData,
    lapTable
}) {
    const thead = lapTable.querySelector('thead');
    const tbody = lapTable.querySelector('tbody');

    thead.innerHTML = '';
    tbody.innerHTML = '';

    // ⛑️ brak lapów w TCX
    if (!lapSummaries || !lapSummaries.length) return;

    const bounds = [...lapMarkers, Infinity];
    const lapCount = lapSummaries.length;

    // ===================== PRZYGOTOWANIE DANYCH LAPÓW =====================
    const laps = [];

    for (let i = 0; i < lapCount; i++) {
        const start = bounds[i];
        const end = bounds[i + 1];

        const hrLap = rawData
            .filter(p => p.x >= start && p.x < end && p.hr != null)
            .map(p => p.hr);

        const endHR = hrLap.length ? hrLap.at(-1) : '-';
        const s = lapSummaries[i];

        laps.push({
            label: `Lap ${i + 1}`,
            avgPower: s.avgPower ?? '-',
            maxPower: s.maxPower ?? '-',
            avgHR: s.avgHR ?? '-',
            maxHR: s.maxHR ?? '-',
            endHR,
            hrw:
                s.avgPower && s.avgHR
                    ? (s.avgHR / s.avgPower).toFixed(3)
                    : '-'
        });
    }

    // ===================== NAGŁÓWEK =====================
    let headHtml = `<tr><th></th>`;
    for (const lap of laps) {
        headHtml += `<th>${lap.label}</th>`;
    }
    headHtml += `</tr>`;
    thead.innerHTML = headHtml;

    // ===================== WIERSZE (METRYKI) =====================
    const rows = [
        { label: 'Śr. moc [W]', key: 'avgPower' },
        { label: 'Max moc [W]', key: 'maxPower' },
        { label: 'Śr. HR [bpm]', key: 'avgHR' },
        { label: 'Max HR [bpm]', key: 'maxHR' },
        { label: 'HR koniec [bpm]', key: 'endHR' },
        { label: 'HR / W', key: 'hrw' }
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
