function exec_Request(event, _boolWp) {
    let elem = event.target;
    let actURL = window.location.href;
    let actSEARCH = decodeURIComponent(window.location.search);
    actSEARCH=actSEARCH.substring(actSEARCH.indexOf("&"));

    let dTapi = FLdT.api();
    let dTinfo = dTapi.page.info();
    let dt_pag_ = [];
    dt_pag_.push((dTinfo['page']+1).toString());
    dt_pag_.push(dTinfo['pages'].toString());
    dt_pag_.push(dTinfo['length'] == -1 ? 'Alle' : dTinfo['length'].toString());
    let dt_pag = dt_pag_.toString().replaceAll(',','-');

    let fXREF = "_";                                            // first XREF in table-row
    let XREFs = [];                                             // array of XREFs für update
    let dt = document.querySelector("#DataTables_Table_0");
    let dtb = dt.querySelector("tbody");
    let dtb_Fs = dtb.querySelectorAll("a");
    for (let i = 0; i < dtb_Fs.length; i++) {
        let _Fe = dtb_Fs[i].href;
        let _Fed = decodeURIComponent(_Fe);
        let _Fes = _Fed.split("/");
        if (_Fes[6] == "family") {
            let XREF = _Fes[7];
            if (XREF != fXREF) {
                if ( XREFs.indexOf(XREF) < 0 ) {
                    fXREF = XREF;
                    XREFs.push(XREF);
                }
            }
        }
    }
//    console.log("XREFs", XREFs.toString());
    let _xrefs = JSON.stringify(XREFs);
    let _url = decodeURIComponent(elem.dataset.url);
    if (_url.includes("&amp;")) {
        _url = _url.replace("&amp;","&");
    }
    _url = _url + "&action=clipFamilies" + "&boolWp=" + encodeURIComponent(_boolWp) + "&actSEARCH=" + encodeURIComponent(actSEARCH) + "&actPage=" + encodeURIComponent(dt_pag);
    $.ajax({
        url: _url,
        dataType: "json",
        data: "xrefs=" + XREFs.join(";"),
        success: function (ret) {
            var _ret = ret;
            updateCCEcount(_ret);
            return true;
        },
        complete: function () {
//
        },
        timeout: function () {
            return false;
        }
    });
}

function updateCCEcount(XREFcnt) {
    let cnt = CCEmenBadge.textContent;
    cnt = cnt.substring(2).trim();
    let vcnt = XREFcnt;
    CCEmenBadge.textContent = " "  + vcnt.toString() + " ";
}

function get_paginate() {
    let elem_pag = document.querySelector("#DataTables_Table_0_paginate");
    let pag_ul = elem_pag.firstChild;
    let act_page = '';
    for (let pag_li of pag_ul.children) {
        if (pag_li.classList.contains('active')) {
            act_page = pag_li.firstChild.textContent;
            break;
        }
    };
    return act_page;
}

function get_dataTable() {
    // let dtID = document.querySelector('#DataTables_Table_0_wrapper').parentElement.id;
    // let dtSelector = '#' + dtID + ' > .wt-table-family';
    // let dT = $(dtSelector).DataTable();
    let dT = FLdT.api();
    return dT;
}
