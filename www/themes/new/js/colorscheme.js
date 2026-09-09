/*
 * OpenXE color scheme switcher.
 *
 * Reads the preferred scheme ("auto" | "light" | "dark") from
 * <html data-color-scheme="..."> and toggles the class "openXeDarkMode"
 * on <html>. All dark theme CSS is scoped to that class. "auto" follows
 * the OS preference via matchMedia and reacts to changes live.
 *
 * Inside same-origin iframes without their own data attribute the scheme is
 * inherited from the parent document. Same-origin child iframes (editors,
 * ticket text, wiki minidetails) are kept in sync on every change.
 *
 * Must be loaded synchronously in <head> before the stylesheets to avoid a
 * flash of the wrong scheme.
 */
(function (window, document) {
    'use strict';

    var CLASS_NAME = 'openXeDarkMode';
    var ATTR = 'data-color-scheme';
    var EVENT = 'openxe:colorscheme';
    var root = document.documentElement;
    var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function readPreference() {
        var value = root.getAttribute(ATTR);
        if (!value) {
            try {
                if (window.parent && window.parent !== window && window.parent.document) {
                    value = window.parent.document.documentElement.getAttribute(ATTR);
                }
            } catch (e) {
                value = null;
            }
        }
        return (value === 'light' || value === 'dark') ? value : 'auto';
    }

    function resolve(preference) {
        if (preference === 'dark') {
            return true;
        }
        if (preference === 'light') {
            return false;
        }
        return !!(query && query.matches);
    }

    function applyToDocument(doc, dark) {
        if (!doc || !doc.documentElement) {
            return;
        }
        var el = doc.documentElement;
        if (dark) {
            el.classList.add(CLASS_NAME);
        } else {
            el.classList.remove(CLASS_NAME);
        }
    }

    function syncIframes(dark) {
        var frames = document.getElementsByTagName('iframe');
        for (var i = 0; i < frames.length; i++) {
            try {
                var doc = frames[i].contentDocument;
                if (doc && !doc.documentElement.getAttribute(ATTR)) {
                    applyToDocument(doc, dark);
                }
            } catch (e) {
                // cross-origin frame, ignore
            }
        }
    }

    function apply() {
        var preference = readPreference();
        var dark = resolve(preference);
        applyToDocument(document, dark);
        syncIframes(dark);
        try {
            var event;
            if (typeof window.CustomEvent === 'function') {
                event = new CustomEvent(EVENT, {detail: {dark: dark, preference: preference}});
            } else {
                event = document.createEvent('CustomEvent');
                event.initCustomEvent(EVENT, false, false, {dark: dark, preference: preference});
            }
            document.dispatchEvent(event);
        } catch (e) {
            // ignore
        }
        return dark;
    }

    function set(preference, persist) {
        if (preference !== 'light' && preference !== 'dark') {
            preference = 'auto';
        }
        root.setAttribute(ATTR, preference);
        var dark = apply();
        if (persist !== false && window.jQuery) {
            window.jQuery.ajax({
                url: 'index.php?module=ajax&action=colorscheme&cmd=set&value=' + preference,
                method: 'post',
                dataType: 'json'
            });
        }
        return dark;
    }

    function cycle() {
        var order = ['auto', 'light', 'dark'];
        var current = readPreference();
        return set(order[(order.indexOf(current) + 1) % order.length]);
    }

    function isDark() {
        return root.classList.contains(CLASS_NAME);
    }

    function hookEditors() {
        var dark = isDark();
        if (window.CKEDITOR && window.CKEDITOR.on) {
            window.CKEDITOR.on('instanceReady', function (ev) {
                try {
                    applyToDocument(ev.editor.document.$, isDark());
                } catch (e) {
                    // ignore
                }
            });
        }
        if (window.tinymce && window.tinymce.on) {
            window.tinymce.on('AddEditor', function (ev) {
                ev.editor.on('init', function () {
                    try {
                        applyToDocument(ev.editor.getDoc(), isDark());
                    } catch (e) {
                        // ignore
                    }
                });
            });
        }
        syncIframes(dark);
    }

    function updateToggle() {
        var toggle = document.getElementById('colorscheme-toggle');
        if (!toggle) {
            return;
        }
        var preference = readPreference();
        toggle.setAttribute('data-preference', preference);
        var labels = {auto: 'Automatisch (System)', light: 'Hell', dark: 'Dunkel'};
        toggle.setAttribute('title', 'Farbschema: ' + labels[preference]);
    }

    apply();

    if (query) {
        var onChange = function () {
            if (readPreference() === 'auto') {
                apply();
            }
        };
        if (query.addEventListener) {
            query.addEventListener('change', onChange);
        } else if (query.addListener) {
            query.addListener(onChange);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        hookEditors();
        updateToggle();
        var toggle = document.getElementById('colorscheme-toggle');
        if (toggle) {
            toggle.addEventListener('click', function (ev) {
                ev.preventDefault();
                cycle();
                updateToggle();
            });
        }
        document.addEventListener(EVENT, updateToggle);
    });

    window.openXeColorScheme = {
        set: set,
        cycle: cycle,
        isDark: isDark,
        preference: readPreference,
        apply: apply
    };
})(window, document);
