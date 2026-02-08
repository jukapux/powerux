// table/table.js

export function buildLapTable({
    lapMarkers,
    lapSummaries,
    rawData,
    lapTableBody
}) {
    lapTableBody.innerHTML = '';
    const bounds = [...lapMarkers, Infinity];
    const lapCount = lapSummaries.length;

    // ===================== ZBIERANIE DANYCH LAPÓW =====================
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

    // ===================== PIERWSZY WIERSZ = LAPY =====================
    let headerRow = `<tr><th>Lap</th>`;
    for (const lap of laps) {
        headerRow += `<td>${lap.label}</td>`;
    }
    headerRow += `</tr>`;
    lapTableBody.innerHTML += headerRow;

    // ===================== WIERSZE METRYK =====================
    const rows = [
        { label: 'Śr. moc', key: 'avgPower' },
        { label: 'Max moc', key: 'maxPower' },
        { label: 'Śr. HR', key: 'avgHR' },
        { label: 'Max HR', key: 'maxHR' },
        { label: 'HR koniec', key: 'endHR' },
        { label: 'HR / W', key: 'hrw' }
    ];

    for (const row of rows) {
        let html = `<tr><th>${row.label}</th>`;
        for (const lap of laps) {
            html += `<td>${lap[row.key]}</td>`;
        }
        html += `</tr>`;
        lapTableBody.innerHTML += html;
    }
}
