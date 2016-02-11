<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 
AUTH::require_user();?>

<div class="pixel">
<div class="form-group" style="margin-bottom: 0px;">
	<label for="pixel_type_id[]" class="col-xs-4 control-label" style="text-align: left;">Pixel Type:</label> 
	<div class="col-xs-5">
		<select class="form-control input-sm" name="pixel_type_id[]" id="pixel_type_id[]">
			<option value="">---</option>
				<?php
					$ppc_network_sql = "SELECT * FROM `202_pixel_types`";
					$ppc_network_result = _mysqli_query($ppc_network_sql); //($ppc_network_sql);
					while ($ppc_network_row = $ppc_network_result->fetch_array(MYSQLI_ASSOC)) {

						$html['pixel_type'] = htmlentities($ppc_network_row['pixel_type'], ENT_QUOTES, 'UTF-8');
						$html['pixel_type_id'] = htmlentities($ppc_network_row['pixel_type_id'], ENT_QUOTES, 'UTF-8');

						if ($selected['pixel_type_id'] == $ppc_network_row['pixel_type_id']) {
							printf('<option selected="selected" value="%s">%s</option>', $html['pixel_type_id'],$html['pixel_type']);
						} else {
							printf('<option value="%s">%s</option>', $html['pixel_type_id'],$html['pixel_type']);
						}
				} ?>
		</select>
		<span class="fui-cross" id="remove_pixel" style="position:absolute; font-size:12px; cursor:pointer; margin:0px; top: 11px; left: -5px;"></span>
	</div>
</div>

<div class="form-group">
<label for="pixel_code" class="col-xs-4 control-label" style="text-align: left;">Pixel Code:</label> 
	<div class="col-xs-5">
		<textarea class="form-control" name="pixel_code[]" id="pixel_code[]" rows="3"></textarea>				    
		<input type="hidden" name="pixel_id[]" value="">
	</div>
</div>
</div>