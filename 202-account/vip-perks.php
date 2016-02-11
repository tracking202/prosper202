<?php


include_once(substr(dirname( __FILE__ ), 0,-12) . '/202-config/connect.php');

AUTH::require_user();
$user_data = get_user_data_feedback($_SESSION['user_id']);
template_top('VIP Perks Profile',NULL,NULL,NULL);
$survey_data = getSurveyData($user_data['install_hash']);
?>
<style type="text/css">

</style> 
<div class="row account">
	<div class="col-xs-12">
		<div class="col-xs-4">
			<h6>VIP Perks Profile</h6>
		</div>
	</div>
	<div class="col-xs-4">
		<div class="panel panel-default account_left">
			<div class="panel-body">
			    Wouldn't you love to have new campaign opportunities, private campaigns, business relationships, discounts and special offers and more handed to you? Now you can with the Prosper202 VIP Perk program. <br></br>

				Fill out your profile information to customize your Prosper202 VIP Perks experience. The information will be used to uniquely match you up with coupons, discounts, and enhanced payouts or exclusive offers from top Affiliate Networks, Ad Networks, Affiliate Tools, Hosting providers and more.
			</div>
		</div>
	</div>

		<div class="col-xs-8" id="vip_perks" style="margin-top: -55px;">
        <span id="perks-success" class="small success" style="display:none; position:absolute; right: 15px; top: 20px;"><span class="fui-check-inverted"></span> Thank you! Your submission was successful.</span>
        <span id="perks-error" class="small error" style="display:none; position:absolute; right: 15px; top: 20px;"><span class="fui-alert"></span> Whoops! Looks like you forget to answer some questions.</span>
		  <?php foreach ($survey_data['question_groups'] as $survey_question_group) { ?>
            <h6><?php echo $survey_question_group['title'];?></h6>
                  
            <div class="row form_seperator">
                <div class="col-xs-12"></div>
            </div>

            <form class="form-horizontal" role="form" id="survey-form">

            <?php foreach ($survey_data['questions'] as $survey_question) { 
                if ($survey_question_group['id'] == $survey_question['group_id']) { 

                    $highlighted = false;

                    if ($survey_question['answer']) {
                        if($survey_question['answer'] == 'Yes') {
                            $answer = 'Yes';
                        } else {
                            $answer = 'No';
                        }
                    } else {
                        $answer = false;
                        if ($survey_question['highlighted']) {
                            $highlighted = true;
                        }
                    }
            ?>
                    <div class="form-group">
                    <label for="<?php echo $survey_question['id'];?>" class="col-sm-9 control-label"><?php echo $survey_question['name'];?> <?php if($highlighted) echo '<span class="label label-important">New!</span>'; ?></label>
                    <div class="col-sm-3">
                      <label class="radio radio-inline">
                        <input type="radio" name="<?php echo $survey_question['id'];?>" value="Yes" data-toggle="radio" required <?php if($answer == 'Yes') echo "checked"; ?>>
                        Yes
                      </label>
                      <label class="radio radio-inline">
                        <input type="radio" name="<?php echo $survey_question['id'];?>" value="No" data-toggle="radio" <?php if($answer == 'No') echo "checked"; ?>>
                        No
                      </label>
                    </div>
                </div>
               <?php } ?>
                
            <?php } ?>

        <?php } ?>
        </form>
    		<div class="col-xs-12" style="margin-top:15px;">
                <div class="row">
                    <img class="loading" style="left: -25px; top: 12px; display:none" id="perks-loading" src="<?php echo get_absolute_url();?>202-img/loader-small.gif">
        			<button class="btn btn-md btn-p202 btn-block" id="survey-form-submit">Submit answers</button>
                </div>					
    		</div>
        </form>
		</div>
</div>

<?php template_bottom();