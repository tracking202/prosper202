<?php
declare(strict_types=1);
class FilterEngine
{
    function __construct()
    {
        // make sure mysql uses the timezone choses by the user
    }

    // allowlist of selectable/identifiable columns on 202_filters
    private const FILTER_COLUMNS = ['filter_name', 'filter_condition', 'filter_value'];

    function getFilterNames($column_name, $filter_id, $echo = true)
    {
        // reject any column name outside the known set
        if (!in_array($column_name, self::FILTER_COLUMNS, true)) {
            return '';
        }
        $filter_id = (int) $filter_id;

        $selectDropdown = "<select name=\"$column_name.$filter_id\">";
        $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = '202_filters' AND COLUMN_NAME = '$column_name'";

        $result = _mysqli_query($sql);
        $row = $result->fetch_assoc();
        $enumList = explode(",", str_replace("'", "", substr((string) $row['COLUMN_TYPE'], 5, (strlen((string) $row['COLUMN_TYPE']) - 6))));
        $showSelected = '';
        $selected = self::getFilter($column_name, $filter_id);
        $selectDropdown .= "<option value=''>--</option>";
        if ($enumList) {
            foreach ($enumList as $value) {

                if (strcmp((string) $selected, $value) == 0) {
                    $showSelected = 'SELECTED';
                }

                $selectDropdown .= '<option value="' . $value . '" ' . $showSelected . '>' . $value . '</option>';
                $showSelected = ''; // reset value for next loop
                                      
            }
        }
        $selectDropdown .= "</select>";
        
        if ($echo)
            echo $selectDropdown;
        
        return $selectDropdown;
    }

    function getFilter($filter, $filter_id)
    {
        // reject any column name outside the known set
        if (!in_array($filter, self::FILTER_COLUMNS, true)) {
            return '';
        }
        $filter_id = (int) $filter_id;

        $sql = "SELECT $filter from 202_filters where id = $filter_id";

        $result = _mysqli_query($sql);

        if ($result)
            $row = $result->fetch_assoc();
        else
            $row = [];

        $value = '';

        if ($row)
            $value = $row[$filter];
        if(is_numeric($value)){
         $split = explode('.', (string) $value);
         if(($split[1] ?? '')=='00000')
             return number_format($value,0);
         else
             return number_format($value,2);

        }

        return $value;


    }

    function getFilterCheck($val, $filter_id)
    {
        if (self::getFilter('filter_name', $filter_id) == '' || self::getFilter('filter_condition', $filter_id) == '' || self::getFilter('filter_value', $filter_id) == '')
            return true;
        switch (self::getFilter('filter_name', $filter_id)) {
            case "S/U":
                $val=number_format($val*100,2, '.', '');
                $percenter = 1;
                
                break;
            case "LP CTR":
            
            case "ROI":
                $val=number_format($val,0, '.', '');
                $percenter = 1;
                
                break;
            default:
                $percenter = 1;
        }
        
        switch (self::getFilter('filter_condition', $filter_id)) {
            case "<":
                if ($val < self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
            case ">":
                if ($val > self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
            case "=":
               
                if ($val == (self::getFilter('filter_value', $filter_id))){
                   
                    return true;}
                else
                    return false;
            
            case ">=":
                if ($val >= self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
            case "<=":
                if ($val <= self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
            case "!=":
                if ($val != self::getFilter('filter_value', $filter_id)){
                    return true;}
                else
                    return false;
        }

    }

    function getFilterNameMapping($filter_id)
    {
        
        $filterNameMapping = [
            "Clicks" => "getClicks",
            "Click Throughs" => "getClickOut",
            "LP CTR" => "getCtr",
            "Leads" => "getLeads",
            "S/U" => "getSu",
            "Payout" => "getPayout",
            "EPC" => "getEpc",
            "CPC" => "getCpc",
            "eCPA" => "getEcpa",
            "Income" => "getIncome",
            "Cost" => "getCost",
            "Net" => "getNet",
            "ROI" => "getRoi"
        ];
        return $filterNameMapping[self::getFilter('filter_name', $filter_id)];
    }

    function setFilter($filter_name, $filter_condition, $filter_value, $filter_id)
    {
        global $db;

        // gate on the raw inputs, then bind the id as an integer
        if (($filter_name!='') && ($filter_condition!='') && ($filter_value!='') && ($filter_id!='')) {
            $filter_id = (int) $filter_id;
            // bind every value; column types (enum/decimal) govern coercion
            $sql = "INSERT INTO 202_filters values (?,?,?,?) ON DUPLICATE KEY UPDATE filter_name=?,filter_condition=?,filter_value=?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException('Unable to prepare filter insert query: ' . $db->error);
            }
            $stmt->bind_param(
                'issssss',
                $filter_id,
                $filter_name,
                $filter_condition,
                $filter_value,
                $filter_name,
                $filter_condition,
                $filter_value
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Unable to execute filter insert query');
            }
            $stmt->close();
        } else {
            $filter_id = (int) $filter_id;
            // preserve original semantics: blank name/condition, NULL value
            $sql = "INSERT INTO 202_filters values (?,'','',NULL) ON DUPLICATE KEY UPDATE filter_name='',filter_condition='',filter_value=NULL";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException('Unable to prepare filter reset query: ' . $db->error);
            }
            $stmt->bind_param('i', $filter_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new \RuntimeException('Unable to execute filter reset query');
            }
            $stmt->close();
        }
    }
}
