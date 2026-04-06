// RepairIntegration module JavaScript
$(document).ready(function() {
    // Status dropdown override for repair tickets
    var repairDropdown = $('#repair-status-override');
    if (repairDropdown.length) {
        $('#status').replaceWith(repairDropdown.children());
    }
});
