<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php'); 

AUTH::require_user();

if ($_SERVER['REQUEST_METHOD'] == 'POST') { 

    if ($_POST['add_more_variables'] == true) { ?>

        <div class="row var-field-group" style="margin-bottom: 10px;" data-var-id="">
            <div class="col-xs-4">
                <div class="form-group">
                    <label for="name" class="sr-only">Name</label>
                    <input type="text" class="form-control input-sm" name="name">
                </div>
            </div>
            <div class="col-xs-4">
                <div class="form-group">
                    <label for="parameter" class="sr-only">Parameter</label>
                    <input type="text" class="form-control input-sm" name="parameter">
                </div>
            </div>
            <div class="col-xs-4">
                <div class="form-group">
                    <label for="placeholder" class="sr-only">Placeholder</label>
                    <input type="text" class="form-control input-sm" name="placeholder">
                    <span class="infotext remove_variable"><i class="fa fa-times"></i></span>
                </div>
            </div>
        </div>

<?php } 

    if ($_POST['post_vars'] == true && isset($_POST['vars']) && isset($_POST['ppc_network_id'])) {
        $vars = array();

        $mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
        foreach ($_POST['vars'] as $var) {
            $var_empty = count($var) != count(array_filter($var));

            if ($var_empty) die("VALIDATION FAILD!");

            $mysql['id'] = $db->real_escape_string($var['id']);
            $mysql['name'] = $db->real_escape_string($var['name']);
            $mysql['parameter'] = $db->real_escape_string($var['parameter']);  
            $mysql['placeholder'] = $db->real_escape_string($var['placeholder']);

            if ($var['id'] != 'false') {
                $sql = "UPDATE 202_ppc_network_variables ";
            } else {
                $sql = "INSERT INTO 202_ppc_network_variables ";
            }

            $sql .= "SET 
                    ppc_network_id = '".$mysql['ppc_network_id']."', 
                    name = '".$mysql['name']."', 
                    parameter = '".$mysql['parameter']."',
                    placeholder = '".$mysql['placeholder']."'";

            if ($var['id'] != 'false') {
                $sql .= " WHERE ppc_variable_id = '".$mysql['id']."'";
            }

            $result = $db->query($sql);

            if ($var['id'] != 'false') {
                $vars[] = $var['id'];
            } else if ($var['id'] == 'false') {
                $vars[] = $db->insert_id;
            }
        }

        $var_ids = implode(', ', $vars);
        $sql = "UPDATE 202_ppc_network_variables SET deleted = '1' WHERE ppc_variable_id NOT IN (".$var_ids.") AND ppc_network_id = '".$mysql['ppc_network_id']."'";        
        $result = $db->query($sql);

        echo 'DONE!';
    }

    if ($_POST['get_vars'] == true && isset($_POST['ppc_network_id'])) {

        $mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
        $sql = "SELECT * FROM 202_ppc_network_variables WHERE ppc_network_id = '".$mysql['ppc_network_id']."' AND deleted = '0'";
        $result = $db->query($sql); 

        if ($result->num_rows > 0) {
           $count = 0; 
           while ($row = $result->fetch_assoc()) { ?>
                
                <div class="row var-field-group old-variable" style="margin-bottom: 10px;" data-var-id="<?php echo $row['ppc_variable_id'];?>">
                    <div class="col-xs-4">
                        <div class="form-group">
                            <label for="name" class="sr-only">Name</label>
                            <input type="text" class="form-control input-sm" name="name" value="<?php echo $row['name']; ?>">
                        </div>
                    </div>
                    <div class="col-xs-4">
                        <div class="form-group">
                            <label for="parameter" class="sr-only">Parameter</label>
                            <input type="text" class="form-control input-sm" name="parameter" value="<?php echo $row['parameter']; ?>">
                        </div>
                    </div>
                    <div class="col-xs-4">
                        <div class="form-group">
                            <label for="placeholder" class="sr-only">Placeholder</label>
                            <input type="text" class="form-control input-sm" name="placeholder" value="<?php echo $row['placeholder']; ?>">
                            <span class="infotext remove_variable"><i class="fa fa-times"></i></span>
                        </div>
                    </div>
                </div>
           
           <?php }

        } else { ?>
            <div class="row var-field-group" style="margin-bottom: 10px;" data-var-id="">
                <div class="col-xs-4">
                    <div class="form-group">
                        <label for="name" class="sr-only">Name</label>
                        <input type="text" class="form-control input-sm" name="name">
                    </div>
                </div>
                <div class="col-xs-4">
                    <div class="form-group">
                        <label for="parameter" class="sr-only">Parameter</label>
                        <input type="text" class="form-control input-sm" name="parameter">
                    </div>
                </div>
                <div class="col-xs-4">
                    <div class="form-group">
                        <label for="placeholder" class="sr-only">Placeholder</label>
                        <input type="text" class="form-control input-sm" name="placeholder">
                    </div>
                </div>
            </div>
        <?php } 
    }

    if ($_POST['delete_vars'] == true && isset($_POST['ppc_network_id'])) {
        $mysql['ppc_network_id'] = $db->real_escape_string($_POST['ppc_network_id']);
        $sql = "DELETE FROM 202_ppc_network_variables WHERE ppc_network_id = '".$mysql['ppc_network_id']."' AND deleted = '0'";
        $result = $db->query($sql); 
    }
}
?>