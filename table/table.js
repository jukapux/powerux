// table/table.js

export function buildLapTable({
    lapMarkers,
    lapSummaries,
    rawData,
    lapTableBody
}) {
    lapTableBody.innerHTML = '';
    const bounds = [...lapMarkers, Infinity];

    for (let i = 0; i < lapSummaries.length; i++) {
        const start = bounds[i];
        const end = bounds[i + 1];

        const hrLap = rawData
            .filter(p => p.x >= start && p.x < end && p.hr != null)
            .map(p => p.hr);

        const endHR = hrLap.length ? hrLap.at(-1) : '-';
        const s = lapSummaries[i];

        lapTableBody.innerHTML += `
            <tr>
                <td>${i + 1}</td>
                <td>${s.avgPower ?? '-'}</td>
                <td>${s.maxPower ?? '-'}</td>
                <td>${s.avgHR ?? '-'}</td>
                <td>${s.maxHR ?? '-'}</td>
                <td>${endHR}</td>
                <td>${s.avgPower && s.avgHR ? (s.avgHR / s.avgPower).toFixed(3) : '-'}</td>
            </tr>`;
    }
}
