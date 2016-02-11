$(function() {
    var tablesorterOptions = {
            theme : 'bootstrap',
            cssChildRow : "tablesorter-childRow",
            widthFixed: true,
            headerTemplate : '{content} {icon}',
            widgets : [ "uitheme", "filter", "zebra" ],
            
            widgetOptions: {
              zebra : ["even", "odd"],
              pager_output: '{startRow} - {endRow} / {filteredRows} ({totalRows})',
              pager_removeRows: false,
              filter_childRows  : false,
              filter_childByColumn : false,
              filter_cssFilter  : 'form-control input-sm',
              filter_startsWith : false,
              filter_ignoreCase : true,
              filter_serversideFiltering : true
            }
    };

    var tablesorterPagerOptions = {
            container: $(".ts-pager"),
            processAjaxOnInit: true,
            ajaxUrl : '',

            customAjaxUrl: function(table, url) {
                $(table).trigger('changingUrl', url);
                return url;
            },

            ajaxObject: {
                dataType: 'html',
                beforeSend: function() {
                  $('table.tablesorter').css('opacity', '0.5');
                  $('span#inProgress').show();
                  $('span#inProgressFooter').show();
                },
            },

            ajaxProcessing: function(data){
              var html = data;
              //console.log(data);
              var body = $(html).filter('#rowContainer');
              var total = body.data('rows');
              var network = body.data('network');
              var rows = body.html();
              $('table.tablesorter').find('tbody').html(rows);
              $('span#inProgress').hide();
              $('span#inProgressFooter').hide();
              $('h4.modal-title').html(network+'<span id="inProgress" style="display:none"> Processing... <img src="/202-img/loader-small.gif"></span>');
              $('table.tablesorter').css('opacity', '1');
              $('[data-toggle="tooltip"]').tooltip();
              return [total];
            },

            output: '{startRow} to {endRow} ({totalRows})',
            updateArrows: true,
            page: 0,
            size: 25,
            savePages: false,
            pageReset: 0,
            fixedHeight: false,
            removeRows: false,
            countChildRows: false,
            cssNext        : '.next',  
            cssPrev        : '.prev',  
            cssFirst       : '.first',
            cssLast        : '.last', 
            cssGoto        : '.pagenum', 
            cssPageDisplay : '.pagedisplay',
            cssPageSize    : '.pagesize', 
            cssDisabled    : 'disabled',
            cssErrorRow    : 'tablesorter-errorRow'

    };
	
	$(".openDniSearchOffersModal").click(function(e) {
		e.preventDefault();
    var dni = $(this).data('dni-id');
		$('#dniSearchOffersModal').modal('show');
		tablesorterPagerOptions.ajaxUrl = '/tracking202/ajax/dni_get_offers.php?all_offers&dni='+dni+'&offset={page}&limit={size}&{sortList:column}&{filterList:filter}';
		var $table1 = $('table.tablesorter').tablesorter(tablesorterOptions).tablesorterPager(tablesorterPagerOptions);
    $table1.delegate('.toggle', 'click', function(e) {
        e.stopImmediatePropagation();
        var loading = $(this).parents().eq(1).find('td:first-child img');
        var offer_id = $(this).data('offer-id');
        var tr = $(this).closest('tr');
        var child = tr.next('.tablesorter-childRow');
        if (child.length > 0) {
          child.remove();
        } else {
          loading.show();
          $.get('/tracking202/ajax/dni_get_offers.php?get_offer&dni='+dni+'&offer_id='+offer_id+'', function(data) {
            //console.log(data);
            try {
              // If there is error in back-end, do nothing
              JSON.parse(data);
              return false;
            } catch(e) {
                tr.after(data);
                $('[data-toggle="tooltip"]').tooltip();
                loading.hide();
            }
          });
        }
        return false;
    });

    $table1.delegate('button.requestOffer', 'click', function(e) {
        e.stopImmediatePropagation();
        var btn = $(this).button('loading');
        var offer_id = $(this).data('offer-id');
        var type = $(this).data('type');
        $.get('/tracking202/ajax/dni_get_offers.php?request_offer_access&dni='+dni+'&offer_id='+offer_id+'&type='+type+'', function(data) {
          //console.log(data);
          try {
              // If there is error in back-end, do nothing
              JSON.parse(data);
              btn.button('reset');
              return false;
          } catch(e) {
              btn.parents().eq(1).html(data);
          }
        });
        return false;
    });

    $table1.delegate('form[name="offersQuestionsForm"]', 'submit', function(e) {
        e.stopImmediatePropagation();
        e.preventDefault();
        var formData = $(this).serializeArray();
        var btn = $(this).find('button');
        var offer_id = btn.data('offer-id');
        btn.button('loading');
        $.ajax({
            url: '/tracking202/ajax/dni_get_offers.php?submit_offer_questions&dni='+dni+'&offer_id='+offer_id+'',
            type: 'POST',
            data: formData
        }).done(function (response, textStatus, jqXHR){
          //console.log(response);
          btn.parents().eq(3).html(response);
        });
        return false;
    });

    $table1.delegate('button.setupOffer', 'click', function(e) {
        e.stopImmediatePropagation();
        var btn = $(this).button('loading');
        var offer_id = $(this).data('offer-id');
        $.get('/tracking202/ajax/dni_get_offers.php?setup_offer&dni='+dni+'&offer_id='+offer_id, function(data) {
          $('#aff_network_id').val(data['aff_network_id']);
          $('#aff_campaign_name').val(data.name);
          $('#aff_campaign_url').val(data.trk_url);
          $('#aff_campaign_payout').val(data.payout);
          $('#dniSearchOffersModal').modal('hide');
        });
        return false;
    });
	});

  $('#dniSearchOffersModal').on('hidden.bs.modal', function (e) {
    $('table.tablesorter').trigger("destroy", [false, false]);
    $('h4.modal-title').html('<span id="inProgress" style="display:none"> Processing... <img src="/202-img/loader-small.gif"></span>');
    $('table.tablesorter tbody').html('');
  });
});
