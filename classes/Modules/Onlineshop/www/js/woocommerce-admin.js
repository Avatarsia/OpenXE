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
