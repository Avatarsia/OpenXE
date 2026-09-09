var ChartHelper = (function ($, ChartJs) {

    var me = {

        elem: {
            $chartWrapper: null
        },

        storage: {
            charts: [],
            lightColors: null
        },

        // Farben fuer das dunkle Farbschema (prefers-color-scheme: dark)
        darkColors: {
            fontColor: '#D5D9E2',
            gridColor: 'rgba(255, 255, 255, 0.12)',
            defaultColor: 'rgba(255, 255, 255, 0.1)'
        },

        init: function () {
            me.elem.$chartWrapper = $('.chart-wrapper');

            me.applyColorScheme();
            me.watchColorScheme();

            // Keine Charts gefunden > Ende
            if (me.elem.$chartWrapper.length === 0) {
                return;
            }

            me.initCharts();
        },

        /**
         * Meldet das Betriebssystem ein dunkles Farbschema?
         *
         * @return {Boolean}
         */
        isDarkMode: function () {
            if (window.openXeColorScheme) {
                return window.openXeColorScheme.isDark();
            }
            return document.documentElement.classList.contains('openXeDarkMode');
        },

        /**
         * Chart.js-Defaults auf das aktuelle Farbschema setzen
         *
         * @return {void}
         */
        applyColorScheme: function () {
            if (typeof ChartJs === 'undefined' || !ChartJs.defaults) {
                return;
            }

            // Die hellen Ausgangswerte einmalig sichern
            if (me.storage.lightColors === null) {
                me.storage.lightColors = {
                    fontColor: ChartJs.defaults.global.defaultFontColor,
                    gridColor: ChartJs.defaults.scale.gridLines.color,
                    defaultColor: ChartJs.defaults.global.defaultColor,
                    zeroLineColor: ChartJs.defaults.scale.gridLines.zeroLineColor
                };
            }

            var colors = me.isDarkMode() ? me.darkColors : me.storage.lightColors;

            ChartJs.defaults.global.defaultFontColor = colors.fontColor;
            ChartJs.defaults.global.defaultColor = colors.defaultColor;
            ChartJs.defaults.scale.gridLines.color = colors.gridColor;
            ChartJs.defaults.scale.gridLines.zeroLineColor = typeof colors.zeroLineColor === 'undefined'
                ? colors.gridColor
                : colors.zeroLineColor;

            if (ChartJs.defaults.global.legend && ChartJs.defaults.global.legend.labels) {
                ChartJs.defaults.global.legend.labels.fontColor = colors.fontColor;
            }
        },

        /**
         * Auf Wechsel des Farbschemas reagieren und bestehende Diagramme neu zeichnen
         *
         * @return {void}
         */
        watchColorScheme: function () {
            // Ausgeloest von themes/new/js/colorscheme.js bei jedem Wechsel (manuell oder System)
            document.addEventListener('openxe:colorscheme', function () {
                me.applyColorScheme();
                me.updateCharts();
            });
        },

        /**
         * Alle bekannten Diagramme neu zeichnen
         *
         * @return {void}
         */
        updateCharts: function () {
            var chartId;

            for (chartId in me.storage.charts) {
                if (!Object.prototype.hasOwnProperty.call(me.storage.charts, chartId)) {
                    continue;
                }

                var chart = me.storage.charts[chartId];
                if (chart && typeof chart.update === 'function') {
                    chart.update();
                }
            }
        },

        /**
         * Alle Diagramme initialisieren
         */
        initCharts: function () {
            var interval = 250;
            me.elem.$chartWrapper.each(function () {
                var $wrapper = $(this);

                // Alle 250 ms ein Chart initialisieren
                window.setTimeout(function () {
                    me.initChart($wrapper);
                }, interval);

                interval += 250;
            });
        },

        /**
         * Einzelnes Diagramm initialisieren
         *
         * @param {jQuery} $wrapper
         *
         * @return {Chart}
         */
        initChart: function ($wrapper) {
            var chartCanvas = $wrapper.find('canvas');
            var chartJson = $wrapper.find('script');
            var chartData = JSON.parse(chartJson.html());

            var graphId = chartCanvas.data('graph-id');
            var graph = new ChartJs(chartCanvas, chartData);

            if (typeof graphId === 'undefined') {
                graphId = me.generateRandomId();
                chartCanvas.data('graph-id', graphId);
            }
            me.storage.charts[graphId] = graph;

            return graph;
        },

        /**
         * Chart-Instanz abrufen
         *
         * @param {String} chartId
         *
         * @return {Chart}
         */
        getChart: function (chartId) {
            return me.storage.charts[chartId];
        },

        /**
         * Alle Chart-Instanzen abrufen
         *
         * @return {Array|Chart[]}
         */
        getCharts: function () {
            return me.storage.charts;
        },

        /**
         * Zufällige ID generieren
         *
         * @return {string}
         */
        generateRandomId: function () {
            return 'chart-' + Math.floor(Math.random() * Math.floor(9999999999));
        }
    };

    return {
        init: me.init,
        initChart: me.initChart,
        getChart: me.getChart,
        getCharts: me.getCharts
    };

})(jQuery, Chart);

$(document).ready(ChartHelper.init);
