document.addEventListener('DOMContentLoaded', function () {
    if (!window.Chart) {
        return;
    }

    const donutCanvas = document.getElementById('appointmentDistributionChart');
    if (donutCanvas) {
        try {
            const payload = JSON.parse(donutCanvas.getAttribute('data-chart') || '{}');
            const labels = Array.isArray(payload.labels) ? payload.labels : [];
            const values = Array.isArray(payload.values) ? payload.values : [];
            const colors = Array.isArray(payload.colors) && payload.colors.length ? payload.colors : ['#173D30', '#C4B58D', '#A3AA91', '#B58A78', '#85877F'];
            if (labels.length && values.length) {
                new window.Chart(donutCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: colors.slice(0, labels.length),
                            borderColor: '#FFFFFF',
                            borderWidth: 3,
                            hoverOffset: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function (context) { return context.label + ': ' + context.parsed; } } },
                        },
                    },
                });
            }
        } catch (error) {
            console.error('Unable to render appointment distribution chart', error);
        }
    }

    const trendCanvas = document.getElementById('revenueTrendChart');
    if (trendCanvas) {
        try {
            const payload = JSON.parse(trendCanvas.getAttribute('data-chart') || '{}');
            const labels = Array.isArray(payload.labels) ? payload.labels : [];
            const values = Array.isArray(payload.values) ? payload.values : [];
            if (labels.length && values.length) {
                new window.Chart(trendCanvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Revenue',
                            data: values,
                            fill: false,
                            borderColor: '#173D30',
                            backgroundColor: 'rgba(23,61,48,0.15)',
                            borderWidth: 3,
                            pointRadius: 3,
                            pointBackgroundColor: '#C4B58D',
                            pointBorderColor: '#173D30',
                            pointHoverRadius: 4,
                            tension: 0.25,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return 'Revenue: ₱' + Number(context.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: '#85877F' },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#85877F',
                                    callback: function (value) {
                                        return '₱' + Number(value).toLocaleString('en-PH', { maximumFractionDigits: 0 });
                                    },
                                },
                                grid: { color: 'rgba(16, 43, 34, 0.06)' },
                            },
                        },
                    },
                });
            }
        } catch (error) {
            console.error('Unable to render revenue trend chart', error);
        }
    }
});
