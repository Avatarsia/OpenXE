const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// DOM, timers and transport are controlled; the event and search logic is real.
function setup(saved = null) {
    const timers = new Map();
    const requests = [];
    const document = { documentElement: { clientWidth: 1400 } };
    const styles = {};
    function element() {
        return {
            length: 1, handlers: {}, visible: false, value: 'test', classes: new Set(),
            0: { style: { setProperty(key, value) { styles[key] = value; } },
                getBoundingClientRect() { return { left: 18 }; },
                contains(node) { return !!node.inside; } },
            on(name, selector, fn) { this.handlers[name] = fn || selector; return this; },
            bind(name, fn) { return this.on(name, fn); },
            off() { return this; }, empty() { return this; },
            trigger(name) { if (this.handlers[name + '.SuperSearch']) this.handlers[name + '.SuperSearch'].call(this, { preventDefault() {} }); return this; },
            hide() { this.visible = false; return this; },
            show() { this.visible = true; return this; },
            find() { return element(); },
            html() { return this; }, text() { return this; },
            addClass(name) { this.classes.add(name); return this; },
            removeClass(name) { this.classes.delete(name); return this; },
            hasClass(name) { return this.classes.has(name); },
            attr(name) { return typeof name === 'string' ? this.kind : this; },
            scrollTop() { return this; }, val() { return this.value; },
            closest() { return { length: this.inside ? 1 : 0 }; }
        };
    }
    const input = element(), doc = element(), overlay = element();
    function $(arg) {
        if (typeof arg === 'function') return;
        if (arg === document) return doc;
        if (arg === '#supersearch-input') return input;
        if (arg === context.window) return doc;
        return arg;
    }
    $.inArray = (value, values) => values.indexOf(value);
    $.ajax = options => {
        const request = { options, then(fn) { this.resolve = fn; return this; }, always() { return this; }, abort() {} };
        requests.push(request);
        return request;
    };
    const localStorage = { getItem() { return saved; }, setItem(key, value) { saved = value; } };
    const context = { $, jQuery: $, document, window: { localStorage,
        clearTimeout(id) { timers.delete(id); },
        setTimeout(fn) { const id = {}; timers.set(id, fn); return id; }
    } };
    const source = fs.readFileSync(path.join(__dirname,
        '../../classes/Widgets/SuperSearch/www/js/supersearch.js'), 'utf8');
    vm.runInNewContext(source.replace('init: me.init', 'init: me.init, diagnostic: me'), context);
    const me = context.SuperSearch.diagnostic;
    me.init();
    Object.assign(me.storage, { $overlay: overlay, $details: element(),
        $results: element(), $lastUpdate: element() });
    const event = keyCode => ({ keyCode, preventDefault() {} });
    return {
        me, input, overlay, requests, styles, document, localStorage,
        saved() { return JSON.parse(saved); },
        type() { input.handlers['keyup.SuperSearch'].call(input, event(65)); },
        flush() { const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn()); },
        escape() { (doc.handlers['keydown.SuperSearch'] || doc.handlers.keydown)(event(27)); },
        click(inside) { const target = element(); target.inside = inside;
            if (doc.handlers['mousedown.SuperSearch']) doc.handlers['mousedown.SuperSearch']({ target }); },
        reply(index = 0) { requests[index].resolve({ data: { count: 0, results: {} } }); }
    };
}

test('outside click closes without clearing the query', () => {
    const s = setup(); s.me.showOverlay(); s.click(false);
    assert.equal(s.overlay.visible, false); assert.equal(s.input.value, 'test');
});
test('widths are restored and clamped to viewport and pane minimums', () => {
    const s = setup('{"width":1800,"results":1700}'); s.me.showOverlay();
    assert.equal(s.styles['--supersearch-width'], '1366px');
    assert.equal(s.styles['--supersearch-results-width'], '946px');
    s.document.documentElement.clientWidth = 1100; s.me.applyLayout();
    assert.equal(s.styles['--supersearch-width'], '1066px');
    assert.equal(s.styles['--supersearch-results-width'], '646px');
});
test('layout changes persist and restore on next initialization', () => {
    const s = setup(); s.me.showOverlay(); s.me.resizeLayout('split', 100);
    s.me.resizeLayout('outer', 80);
    const restored = setup(JSON.stringify(s.saved())); restored.me.showOverlay();
    assert.equal(restored.styles['--supersearch-width'], '1366px');
    assert.equal(restored.styles['--supersearch-results-width'], '852px');
});
test('invalid saved widths fall back to defaults', () => {
    const s = setup('{"width":"bad","results":null}'); s.me.showOverlay();
    assert.equal(s.styles['--supersearch-width'], '1340px');
    assert.equal(s.styles['--supersearch-results-width'], '752px');
});
test('pointer drag and keyboard resize update widths and save on release', () => {
    const s = setup(); s.me.showOverlay(); s.me.registerResizeEvents(s.overlay);
    const handle = { attr() { return 'split'; }, setPointerCapture() {} };
    const event = x => ({ button: 0, preventDefault() {}, originalEvent: { clientX: x, pointerId: 1 } });
    s.overlay.handlers['pointerdown.SuperSearch'].call(handle, event(752));
    s.overlay.handlers['pointermove.SuperSearch'].call(handle, event(902));
    assert.equal(s.styles['--supersearch-results-width'], '902px');
    assert.equal(s.saved(), null);
    s.overlay.handlers['pointerup.SuperSearch pointercancel.SuperSearch lostpointercapture.SuperSearch']();
    assert.equal(s.saved().results, 902);
    s.overlay.handlers['keydown.SuperSearch'].call(handle, { keyCode: 37, preventDefault() {} });
    assert.equal(s.saved().results, 882);
});
test('pane and total minimums are enforced', () => {
    const s = setup(); s.me.showOverlay(); s.me.resizeLayout('split', -1000);
    assert.equal(s.saved().results, 280);
    s.me.resizeLayout('outer', -2000);
    assert.equal(s.saved().width, 700);
});
test('blocked storage does not prevent resizing', () => {
    const s = setup(); s.me.showOverlay();
    s.localStorage.setItem = () => { throw new Error('Storage blocked'); };
    s.me.resizeLayout('outer', 40);
    assert.equal(s.styles['--supersearch-width'], '1366px');
});
test('click inside search leaves overlay open', () => {
    const s = setup(); s.me.showOverlay(); s.click(true);
    assert.equal(s.overlay.visible, true);
});
test('Escape closes visible overlay', () => {
    const s = setup(); s.me.showOverlay(); s.escape();
    assert.equal(s.overlay.visible, false);
});
test('Escape cancels buffered search before first overlay opens', () => {
    const s = setup(); s.me.storage.$overlay = null; s.type(); s.escape(); s.flush();
    assert.equal(s.requests.length, 0);
});
test('late response cannot reopen after Escape', () => {
    const s = setup(); s.me.showOverlay(); s.type(); s.flush(); s.escape(); s.reply();
    assert.equal(s.overlay.visible, false);
});
test('late response cannot reopen after outside click', () => {
    const s = setup(); s.type(); s.flush(); s.click(false); s.reply();
    assert.equal(s.overlay.visible, false);
});
test('new input after closing can open results again', () => {
    const s = setup(); s.me.showOverlay(); s.escape(); s.type(); s.flush(); s.reply();
    assert.equal(s.overlay.visible, true);
});
test('late index error cannot reopen after closing', () => {
    const s = setup(); s.type(); s.flush(); s.escape();
    s.requests[0].options.error({ responseJSON: { error: 'Empty', data: 'index-empty' } }, 'error', '');
    assert.equal(s.overlay.visible, false);
});
test('older response is ignored while a newer search is pending', () => {
    const s = setup(); s.type(); s.flush(); s.type(); s.flush(); s.reply(0);
    assert.equal(s.overlay.visible, false);
    s.reply(1); assert.equal(s.overlay.visible, true);
});

test('closing with existing results does not reopen through focus', () => {
    const s = setup(); s.me.storage.hasResults = true; s.me.showOverlay(); s.escape();
    assert.equal(s.overlay.visible, false);
});

test('closing returns focus from overlay without reopening', () => {
    const s = setup(); s.me.storage.hasResults = true; s.me.showOverlay();
    s.document.activeElement = { inside: true }; s.escape();
    assert.equal(s.overlay.visible, false);
    assert.equal(s.me.storage.suppressFocus, false);
});
