/*
 * jshint undef:false, unused:false
 */
// jshint undef:false, unused:false

var taxeratios;

$(function() {
    $.get("/local/shop/ajax/loadtaxes.php", function(data, textStatus) {
        obj = JSON.parse(data);
        taxeratios = $.map(obj, function(el) { return el; });
    });
});

/**
 *
 */
function updatetiprice(item) {
    taxsel = document.getElementById('id_taxcode');
    taxid = taxsel.options[taxsel.selectedIndex].value;

    priceid = 'price' + item;
    pricefield = document.getElementById('id_' + priceid);
    tidivid = 'id_' + priceid + 'ti';
    tidiv = document.getElementById(tidivid);

    input = [];
    input.ht = ht = parseFloat(pricefield.value);
    input.tr = tr = parseFloat(taxeratios[taxid].ratio);

    output = evaluate(taxeratios[taxid].formula, input);
    tidiv.innerHTML = ttc.toFixed(2) + ' (' + taxeratios[taxid].formula + ')';
}

function checkprices(item) {
    fromfield = document.getElementById('id_range' + item);
    nextitem = parseInt(item) + 1;
    tofield = document.getElementById('id_from' + nextitem);
    tofield.value = parseInt(fromfield.value) + 1;
}
