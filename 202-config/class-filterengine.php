<?php

class FilterEngine
{

    private $mysql = Array();

    private static $db;

    function __construct()
    {
        try {
            $database = DB::getInstance();
            self::$db = $database->getConnection();
        } catch (Exception $e) {
            self::$db = false;
        }
        $this->mysql['user_id'] = self::$db->real_escape_string($_SESSION['user_id']);
        // make sure mysql uses the timezone choses by the user
    }

    function getFilterNames($column_name, $filter_id, $echo = true)
    {
        $selectDropdown = "<select name=\"$column_name.$filter_id\">";
        $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = '202_filters' AND COLUMN_NAME = '$column_name'";
        
        $result = _mysqli_query($sql);
        $row = $result->fetch_assoc();
        $enumList = explode(",", str_replace("'", "", substr($row['COLUMN_TYPE'], 5, (strlen($row['COLUMN_TYPE']) - 6))));
        $showSelected = '';
        $selected = self::getFilter($column_name, $filter_id);
        $selectDropdown .= "<option value=''>--</option>";
        if ($enumList) {
            foreach ($enumList as $value) {
                
                if (strcmp($selected, $value) == 0) {
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
        $sql = "SELECT $filter from 202_filters where id = $filter_id";

        $result = _mysqli_query($sql);
        
        if ($result)
            $row = $result->fetch_assoc();
        
        $value = '';
        
        if ($row)
            $value = $row[$filter];
        if(is_numeric($value)){
         $split = explode('.', $value);
         if($split[1]=='00000')  
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
                break;
            case ">":
                if ($val > self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
                break;
            case "=":
               
                if ($val == (self::getFilter('filter_value', $filter_id))){
                   
                    return true;}
                else
                    return false;
                break;
            
            case ">=":
                if ($val >= self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
                break;
            case "<=":
                if ($val <= self::getFilter('filter_value', $filter_id))
                    return true;
                else
                    return false;
                break;
            case "!=":
                if ($val != self::getFilter('filter_value', $filter_id)){
                    echo  self::getFilter('filter_value', $filter_id);
                    return true;}
                else
                    return false;
                break;
        }

    }

    function getFilterNameMapping($filter_id)
    {
        
        $filterNameMapping = array(
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
        );
        return $filterNameMapping[self::getFilter('filter_name', $filter_id)];
    }

    function setFilter($filter_name, $filter_condition, $filter_value, $filter_id)
    {
        if (($filter_name!='') && ($filter_condition!='') && ($filter_value!='') && ($filter_id!='')) {
            $sql = "INSERT INTO 202_filters values ($filter_id,'$filter_name','$filter_condition',$filter_value) ON DUPLICATE KEY UPDATE filter_name='$filter_name',filter_condition='$filter_condition',filter_value=$filter_value";
            echo $sql."<br>";
            $result = _mysqli_query($sql);
        } else {
            $sql = "INSERT INTO 202_filters values ($filter_id,'','',NULL) ON DUPLICATE KEY UPDATE filter_name='',filter_condition='',filter_value=NULL";
            echo  $sql."<br>";
            $result = _mysqli_query($sql);
        }
    }
}