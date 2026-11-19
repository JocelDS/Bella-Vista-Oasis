document.addEventListener('DOMContentLoaded', function () {
    const printButton = document.getElementById('printReport');
    if (printButton) {
        printButton.addEventListener('click', function () { window.print(); });
    }

    const customToggle = document.getElementById('customToggle');
    const customRange = document.getElementById('customRange');
    if (customToggle && customRange) {
        customToggle.addEventListener('click', function () {
            const willShow = customRange.hasAttribute('hidden');
            if (willShow) {
                customRange.removeAttribute('hidden');
            } else {
                customRange.setAttribute('hidden', '');
            }
            customToggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        });
    }

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

    const weekdayCanvas = document.getElementById('weekdayChart');
    if (weekdayCanvas) {
        try {
            const payload = JSON.parse(weekdayCanvas.getAttribute('data-chart') || '{}');
            const labels = Array.isArray(payload.labels) ? payload.labels : [];
            const values = Array.isArray(payload.values) ? payload.values : [];
            if (labels.length && values.length) {
                new window.Chart(weekdayCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{ label: 'Bookings', data: values, backgroundColor: '#173D30', borderRadius: 6, maxBarThickness: 36 }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: '#85877F' } },
                            y: { beginAtZero: true, ticks: { color: '#85877F', precision: 0 }, grid: { color: 'rgba(16, 43, 34, 0.06)' } },
                        },
                    },
                });
            }
        } catch (error) {
            console.error('Unable to render weekday chart', error);
        }
    }

    const pacingCanvas = document.getElementById('pacingChart');
    if (pacingCanvas) {
        try {
            const payload = JSON.parse(pacingCanvas.getAttribute('data-chart') || '{}');
            const labels = Array.isArray(payload.labels) ? payload.labels : [];
            const values = Array.isArray(payload.values) ? payload.values : [];
            const peak = typeof payload.peak === 'number' ? payload.peak : -1;
            if (labels.length && values.length) {
                const gradient = pacingCanvas.getContext('2d').createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, 'rgba(152, 107, 194, 0.35)');
                gradient.addColorStop(1, 'rgba(225, 139, 185, 0.03)');
                new window.Chart(pacingCanvas, {
                    type: 'line',
                    data: {
                        labels: labels.map(function (label, index) {
                            if (index === peak && Array.isArray(label)) {
                                return [label[0] + ' (Peak)', label[1]];
                            }
                            return label;
                        }),
                        datasets: [{
                            label: 'Revenue',
                            data: values,
                            fill: true,
                            backgroundColor: gradient,
                            borderColor: '#986BC2',
                            borderWidth: 3,
                            tension: 0.4,
                            pointRadius: values.map(function (value, index) { return index === peak ? 7 : 4; }),
                            pointBackgroundColor: values.map(function (value, index) { return index === peak ? '#E18BB9' : '#FFFFFF'; }),
                            pointBorderColor: '#986BC2',
                            pointBorderWidth: 2,
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
                                        return 'Revenue: ₱' + Number(context.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                                    },
                                },
                            },
                        },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: '#85877F' } },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#85877F',
                                    callback: function (value) { return '₱' + Number(value).toLocaleString('en-PH', { maximumFractionDigits: 0 }); },
                                },
                                grid: { color: 'rgba(152, 107, 194, 0.10)' },
                            },
                        },
                    },
                });
            }
        } catch (error) {
            console.error('Unable to render revenue pacing chart', error);
        }
    }
});