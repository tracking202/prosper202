<?php 
header('Content-type: application/javascript');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT');
header("Pragma: no-cache");
include_once(substr(dirname( __FILE__ ), 0,-7) . '/202-config/functions.php');
?>

$(function() {
    tablesorterOptions = {
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

    tablesorterPagerOptions = {
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
                success: function () {
                  if (tablesorterOptions.triggerToggle == true) {
                    var obj_offer_id = tablesorterOptions.toggleId;
                    var obj = $('a[data-offer-id='+obj_offer_id+']');
                    toggleOffer (obj, obj_offer_id, dni);
                  }
                }
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
              $('h4.modal-title').html(network+'<span id="inProgress" style="display:none"> Processing... <img src="<?php echo get_absolute_url();?>202-img/loader-small.gif"></span>');
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
    dni = $(this).data('dni-id');
		$('#dniSearchOffersModal').modal('show');
		tablesorterPagerOptions.ajaxUrl = '<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?all_offers&dni='+dni+'&offset={page}&limit={size}&{sortList:column}&{filterList:filter}';
		var $table1 = $('table.tablesorter').tablesorter(tablesorterOptions).tablesorterPager(tablesorterPagerOptions);
	});

  $('#dniSearchOffersModal').on('hidden.bs.modal', function (e) {
    $('table.tablesorter').trigger("destroy", [false, false]);
    $('h4.modal-title').html('<span id="inProgress" style="display:none"> Processing... <img src="<?php echo get_absolute_url();?>202-img/loader-small.gif"></span>');
    $('table.tablesorter tbody').html('');
  });
});

$(document).on('click', '.toggle', function(e) {
  e.stopImmediatePropagation();
  var loading = $(this).parents().eq(1).find('td:first-child img');
  var offer_id = $(this).data('offer-id');
  var tr = $(this).closest('tr');
  var child = tr.next('.tablesorter-childRow');
  if (child.length > 0) {
    child.remove();
  } else {
    loading.show();
    $.get('<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?get_offer&dni='+dni+'&offer_id='+offer_id+'', function(data) {
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

$(document).on('click', 'button.requestOffer', function(e) {
  e.stopImmediatePropagation();
  var btn = $(this).button('loading');
  var offer_id = $(this).data('offer-id');
  var type = $(this).data('type');
  $.get('<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?request_offer_access&dni='+dni+'&offer_id='+offer_id+'&type='+type+'', function(data) {
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

$(document).on('submit', 'form[name="offersQuestionsForm"]', function(e) {
  e.stopImmediatePropagation();
  e.preventDefault();
  var formData = $(this).serializeArray();
  var btn = $(this).find('button');
  var offer_id = btn.data('offer-id');
  btn.button('loading');
  $.ajax({
    url: '<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?submit_offer_questions&dni='+dni+'&offer_id='+offer_id+'',
    type: 'POST',
    data: formData
  }).done(function (response, textStatus, jqXHR){
    //console.log(response);
    btn.parents().eq(3).html(response);
  });
  return false;
});

$(document).on('click', 'button.setupOffer', function(e) {
  e.stopImmediatePropagation();
  var btn = $(this).button('loading');
  var offer_id = $(this).data('offer-id');
  <?php if (isset($_GET['ddlci'])) {
        $ddlci = $_GET['ddlci'];
      } else {
        $ddlci = null;
  } ?>
  $.get('<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?setup_offer&ddlci=<?php echo $ddlci;?>&dni='+dni+'&offer_id='+offer_id, function(data) {
    $("input[name='dni_id']").val(dni);
    $("input[name='dni_offer_id']").val(offer_id);
    $('#aff_network_id').val(data['aff_network_id']).trigger('change');
    $('#aff_campaign_name').val(data.name);
    $('#aff_campaign_url').val(data.trk_url);
    $('#aff_campaign_payout').val(data.payout);
    $('#dniSearchOffersModal').modal('hide');
  });
  return false;
});

function toggleOffer (obj, offer_id, dni) {
    var loading = obj.parents().eq(1).find('td:first-child img');
    var tr = obj.closest('tr');
    var child = tr.next('.tablesorter-childRow');
      if (child.length > 0) {
        child.remove();
      } else {
        loading.show();
        $.get('<?php echo get_absolute_url();?>tracking202/ajax/dni_get_offers.php?get_offer&dni='+dni+'&offer_id='+offer_id+'', function(data) {
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
}
