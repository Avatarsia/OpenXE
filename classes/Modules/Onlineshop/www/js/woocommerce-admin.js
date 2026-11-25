document.addEventListener('DOMContentLoaded', function () {
    var rows = document.getElementById('wc-download-rows');
    var add = document.getElementById('wc-download-add');
    var template = document.getElementById('wc-download-template');
    if (!rows || !add || !template) return;
    function update() { add.disabled = rows.children.length >= 50; }
    add.addEventListener('click', function () {
        if (rows.children.length >= 50) return;
        rows.appendChild(template.content.cloneNode(true));
        rows.lastElementChild.querySelector('input').focus();
        update();
    });
    rows.addEventListener('click', function (event) {
        if (!event.target.classList.contains('wc-download-remove')) return;
        var row = event.target.closest('tr');
        row.remove();
        add.focus();
        update();
    });
    update();
});

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('wc-pull-form');
    if (!form) return;
    var submit = form.querySelector('button[type="submit"]');
    if (!submit) return;

    var checkboxes = form.querySelectorAll('input[data-pull-article]');
    var limit = Number(form.dataset.maxArticles);
    var limitNotice = document.getElementById('wc-pull-limit');
    function selectedFields() {
        var selection = new Map();
        checkboxes.forEach(function (checkbox) {
            if (!checkbox.checked) return;
            var id = checkbox.dataset.pullArticle;
            if (!selection.has(id)) selection.set(id, []);
            selection.get(id).push(checkbox.value);
        });
        return selection;
    }
    function updateSelection() {
        var selection = selectedFields();
        var atLimit = selection.size >= limit;
        checkboxes.forEach(function (checkbox) {
            checkbox.disabled = atLimit && !selection.has(checkbox.dataset.pullArticle);
        });
        limitNotice.hidden = !atLimit;
        submit.disabled = selection.size === 0 || selection.size > limit;
    }
    form.addEventListener('change', updateSelection);
    window.addEventListener('pageshow', updateSelection);
    form.addEventListener('submit', function (event) {
        var selection = selectedFields();
        if (selection.size === 0 || selection.size > limit) {
            event.preventDefault();
            updateSelection();
            return;
        }
        var payload = document.getElementById('wc-pull-selection');
        payload.textContent = '';
        var count = 0;
        selection.forEach(function (keys, id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'pull[' + id + ']';
            input.value = keys.join(',');
            payload.appendChild(input);
            count += keys.length;
        });
        form.elements.pull_count.value = String(count);
    });
    updateSelection();
});
