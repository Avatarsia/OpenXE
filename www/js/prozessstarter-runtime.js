(function (window) {
    'use strict';
    if (window.ProzessstarterRuntime) {
        window.ProzessstarterRuntime.init();
        return;
    }

    var colors = {completed: '#A2C55A', error: '#E05A63', partial: '#F69E06', unknown: '#94979E'};
    var labels = {completed: 'Abgeschlossen', error: 'Fehler', partial: 'Teilfehler', unknown: 'Abschluss unbekannt'};

    function duration(seconds) {
        if (seconds === null) { return 'unbekannt'; }
        if (seconds < 60) { return seconds + ' s'; }
        if (seconds < 3600) { return Math.floor(seconds / 60) + ' min ' + seconds % 60 + ' s'; }
        return Math.floor(seconds / 3600) + ' h ' + Math.floor(seconds % 3600 / 60) + ' min ' + seconds % 60 + ' s';
    }

    function windowRuns(runs, from, to) {
        return runs.filter(function (run) { return run.x >= from && run.x <= to; });
    }

    function scale(runs) {
        var longest = runs.reduce(function (max, run) {
            return run.duration !== null && Number.isFinite(run.duration) ? Math.max(max, run.duration) : max;
        }, 0);
        var divisor = longest >= 3600 ? 3600 : longest >= 60 ? 60 : 1;
        var target = Math.max(longest / divisor * 1.1, 1);
        var rough = target / 4;
        var power = Math.pow(10, Math.floor(Math.log10(rough)));
        var fraction = rough / power;
        var step = (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * power;
        return {divisor: divisor, unit: divisor === 3600 ? 'Stunden' : divisor === 60 ? 'Minuten' : 'Sekunden',
            step: step, max: Math.ceil(target / step) * step};
    }

    function time(value, timezone, dateOnly) {
        return new Intl.DateTimeFormat('de-DE', {timeZone: timezone, day: '2-digit', month: '2-digit',
            hour: dateOnly ? undefined : '2-digit', minute: dateOnly ? undefined : '2-digit',
            second: dateOnly ? undefined : '2-digit'}).format(new Date(value));
    }

    function config(runs, from, to, timezone) {
        var axis = scale(runs);
        var groups = {};
        runs.forEach(function (run) {
            if (run.duration === null) { return; }
            var key = 'K' + run.jobId;
            if (!groups[key]) { groups[key] = {label: run.job, data: [], backgroundColor: [], borderWidth: 0}; }
            groups[key].data.push({x: run.x, y: run.duration / axis.divisor, run: run});
            groups[key].backgroundColor.push(colors[run.state]);
        });
        var callbacks = {
            title: function (items, data) {
                var item = items[0];
                var run = data.datasets[item.datasetIndex].data[item.index].run;
                return run.job + ' | ' + run.start;
            },
            label: function (item, data) {
                var run = data.datasets[item.datasetIndex].data[item.index].run;
                return duration(run.duration) + ' | ' + labels[run.state];
            },
            afterLabel: function (item, data) {
                return 'Ende: ' + data.datasets[item.datasetIndex].data[item.index].run.end;
            }
        };
        return {
            type: 'bar',
            data: {datasets: Object.keys(groups).map(function (key) { return groups[key]; })},
            // Override the shared monetary tooltip formatter only for this chart.
            plugins: [{
                beforeInit: function (chart) { chart.options.tooltips.callbacks = callbacks; },
                beforeDatasetsUpdate: function (chart) {
                    chart.data.datasets.forEach(function (dataset, index) {
                        // Chart.js 2.7's bar ruler passes null to a linear index scale.
                        // Position only these bars directly at their recorded timestamps.
                        chart.getDatasetMeta(index).controller.calculateBarIndexPixels = function (datasetIndex, dataIndex) {
                            var point = this.getDataset().data[dataIndex];
                            var center = this.getIndexScale().getPixelForValue(point.x);
                            return {base: center - 1, head: center + 1, center: center, size: 2};
                        };
                    });
                }
            }],
            options: {
                responsive: true, maintainAspectRatio: false, animation: {duration: 0},
                legend: {display: false},
                tooltips: {mode: 'nearest', intersect: false, callbacks: callbacks},
                scales: {
                    xAxes: [{type: 'linear', offset: false, barThickness: 2,
                        gridLines: {offsetGridLines: false},
                        ticks: {min: from, max: to, maxTicksLimit: 9, callback: function (value) {
                            return time(value, timezone, to - from > 86400000);
                        }}}],
                    yAxes: [{ticks: {min: 0, max: axis.max, stepSize: axis.step,
                        callback: function (value) { return Number(value.toFixed(4)).toLocaleString('de-DE'); }},
                        scaleLabel: {display: true, labelString: axis.unit}}]
                }
            }
        };
    }

    function init() {
        if (!window.document || !window.Chart) { return; }
        window.document.querySelectorAll('.cronjob-runtime').forEach(function (element) {
            if (element.runtimeChart) { return; }
            var payload = JSON.parse(element.querySelector('script[type="application/json"]').textContent);
            var canvas = element.querySelector('canvas');
            var chart = null;
            var visible = [];
            var selected = -1;
            var summary = element.querySelector('.runtime-summary');
            var details = element.querySelector('.runtime-details');
            var previous = element.querySelector('.runtime-previous');
            var next = element.querySelector('.runtime-next');
            var rangeLabel = element.querySelector('.runtime-range');

            function select(index) {
                selected = Math.max(0, Math.min(index, visible.length - 1));
                var run = visible[selected];
                previous.disabled = !run || selected === 0;
                next.disabled = !run || selected === visible.length - 1;
                details.textContent = run ? run.job + ' | Start: ' + run.start + ' | Ende: ' + (run.end || 'unbekannt')
                    + ' | Dauer: ' + duration(run.duration) + ' | ' + labels[run.state]
                    + (run.message ? ' | Letzte Meldung: ' + run.message : '') : 'Keine Ausführungen im gewählten Zeitraum.';
            }

            function render(days) {
                var from = days ? Math.max(payload.from, payload.to - days * 86400000) : payload.from;
                visible = windowRuns(payload.runs, from, payload.to);
                var counts = {completed: 0, error: 0, partial: 0, unknown: 0};
                visible.forEach(function (run) { counts[run.state]++; });
                var successful = visible.filter(function (run) { return run.state === 'completed'; })
                    .map(function (run) { return run.duration; }).sort(function (a, b) { return a - b; });
                var middle = Math.floor(successful.length / 2);
                var median = successful.length ? successful.length % 2 ? successful[middle]
                    : (successful[middle - 1] + successful[middle]) / 2 : null;
                summary.textContent = visible.length + ' Läufe | ' + counts.completed + ' abgeschlossen | '
                    + counts.error + ' Fehler | ' + counts.partial + ' Teilfehler | ' + counts.unknown
                    + ' Abschluss unbekannt | Median abgeschlossener Läufe: ' + duration(median);
                rangeLabel.textContent = time(from, payload.timezone, false) + ' – ' + time(payload.to, payload.timezone, false);
                var options = config(visible, from, payload.to, payload.timezone);
                options.options.onClick = function (event) {
                    var items = chart.getElementAtEvent(event);
                    if (items.length) {
                        var item = items[0];
                        var run = chart.data.datasets[item._datasetIndex].data[item._index].run;
                        select(visible.indexOf(run));
                    }
                };
                if (chart) { chart.destroy(); }
                chart = new window.Chart(canvas.getContext('2d'), options);
                element.runtimeChart = chart;
                element.querySelectorAll('[data-runtime-days]').forEach(function (button) {
                    button.setAttribute('aria-pressed', Number(button.dataset.runtimeDays) === days ? 'true' : 'false');
                });
                select(visible.length - 1);
            }
            previous.onclick = function () { select(selected - 1); };
            next.onclick = function () { select(selected + 1); };
            element.querySelectorAll('[data-runtime-days]').forEach(function (button) {
                button.onclick = function () { render(Number(button.dataset.runtimeDays)); };
            });
            element.runtimeUpdateTheme = function () { if (chart) { chart.update(); } };
            render(payload.mini ? 7 : 0);
        });
    }

    window.ProzessstarterRuntime = {scale: scale, windowRuns: windowRuns, config: config, duration: duration, init: init};
    if (window.document) {
        var ready = function () {
            init();
            // The shared ChartHelper registers first at DOM ready and updates defaults.
            window.document.addEventListener('openxe:colorscheme', function () {
                window.document.querySelectorAll('.cronjob-runtime').forEach(function (element) {
                    if (element.runtimeUpdateTheme) { element.runtimeUpdateTheme(); }
                });
            });
        };
        if (window.jQuery) { window.jQuery(ready); }
        else if (window.document.readyState === 'loading') { window.document.addEventListener('DOMContentLoaded', ready); }
        else { ready(); }
    }
})(window);
