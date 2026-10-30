const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const window = {};
vm.runInNewContext(fs.readFileSync('www/js/prozessstarter-runtime.js', 'utf8'), {window, Intl, Date});
const runtime = window.ProzessstarterRuntime;
let passed = 0;
function test(name, fn) { fn(); passed++; console.log('PASS ' + name); }
test('small durations have readable automatic scale', () => {
    const s = runtime.scale([{duration: 12}]);
    assert.equal(s.unit, 'Sekunden');
    assert.equal(s.max, 15);
    assert.equal(s.step, 5);
});
test('minute and hour units', () => {
    assert.equal(runtime.scale([{duration: 180}]).divisor, 60);
    assert.equal(runtime.scale([{duration: 7200}]).divisor, 3600);
});
test('zero, missing and empty durations have a usable axis', () => {
    for (const runs of [[], [{duration: 0}], [{duration: null}]]) {
        const s = runtime.scale(runs);
        assert.ok(s.max > 0 && Number.isFinite(s.max));
        assert.ok(s.step > 0);
    }
});
test('outliers are never clipped', () => {
    for (const value of [1, 59, 60, 3599, 3600, 1e8]) {
        const s = runtime.scale([{duration: value}]);
        assert.ok(s.max * s.divisor > value);
    }
});
test('window selection uses exact timestamp without bucketing', () => {
    const runs = [{x: 10, duration: 100}, {x: 11, duration: 0}, {x: 12, duration: null}];
    const selected = runtime.windowRuns(runs, 11, 12);
    assert.equal(selected.length, 2);
    assert.equal(selected[0], runs[1]);
    assert.equal(runtime.scale(selected).unit, 'Sekunden');
});
test('configuration uses real X coordinates and single bars', () => {
    const runs = [{x: 1000, duration: 8, jobId: 1, job: 'Shop', state: 'completed'},
        {x: 61000, duration: 180, jobId: 1, job: 'Shop', state: 'error'},
        {x: 70000, duration: null, jobId: 1, job: 'Shop', state: 'unknown'}];
    const config = runtime.config(runs, 0, 100000, 'Europe/Berlin');
    assert.equal(config.type, 'bar');
    assert.equal(config.data.datasets[0].data.length, 2);
    assert.equal(config.data.datasets[0].data[1].x, 61000);
    assert.notEqual(config.data.datasets[0].backgroundColor[0], config.data.datasets[0].backgroundColor[1]);
    assert.equal(config.options.scales.xAxes[0].type, 'linear');
    assert.equal(config.options.scales.xAxes[0].ticks.min, 0);
    assert.equal(config.options.scales.xAxes[0].ticks.max, 100000);
    assert.equal(config.data.datasets[0].data[0].run.duration, 8);
    assert.ok(!JSON.stringify(config).includes('polyline'));
});
test('duration label preserves zero and unknown', () => {
    assert.equal(runtime.duration(0), '0 s');
    assert.equal(runtime.duration(null), 'unbekannt');
    assert.equal(runtime.duration(192), '3 min 12 s');
});
test('Chart.js 2.7 bar controller receives finite exact timestamp positions', () => {
    const shipped = {exports: {}};
    vm.runInNewContext(fs.readFileSync('www/js/chart.min.js', 'utf8'), {module: shipped, exports: shipped.exports, window: {}});
    const Bar = shipped.exports.controllers.bar;
    const controller = Object.create(Bar.prototype);
    controller.getIndexScale = () => ({getPixelForValue: value => value === null ? NaN : value / 1000});
    controller.getDataset = () => ({data: [{x: 123000}]});
    controller.getMeta = () => ({});
    controller.getStackIndex = () => 0;
    const chart = {data: {datasets: [{}]}, getDatasetMeta: () => ({controller})};
    const configuration = runtime.config([], 0, 200000, 'Europe/Berlin');
    if (configuration.plugins[0].beforeDatasetsUpdate) { configuration.plugins[0].beforeDatasetsUpdate(chart); }
    const pixel = controller.calculateBarIndexPixels(0, 0, {scale: {options: {barThickness: 2}}, pixels: [NaN], stackCount: 1});
    assert.equal(pixel.center, 123);
    assert.equal(pixel.size, 2);
});
test('theme listener is registered at ready after the shared ChartHelper', () => {
    const ready = [], listeners = [];
    const browser = {document: {querySelectorAll: () => [], addEventListener: (event, fn) => listeners.push(event)},
        Chart: function () {}, jQuery: fn => ready.push(fn)};
    vm.runInNewContext(fs.readFileSync('www/js/prozessstarter-runtime.js', 'utf8'), {window: browser, Intl, Date});
    assert.equal(listeners.length, 0);
    ready[0]();
    assert.equal(listeners[0], 'openxe:colorscheme');
});
console.log(passed + ' passed, 0 failed');
