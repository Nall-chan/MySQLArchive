<?

require_once(__DIR__ . "/../libs/MySQLArchiv.php");

/**
 * NoTrigger Klasse für die die Überwachung von mehreren Variablen auf fehlende Änderung/Aktualisierung.
 * Erweitert NoTriggerBase.
 *
 * @package       NoTrigger
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2016 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       2.0
 * @example <b>Ohne</b>
 *
 * @property array $Vars
 * @property mysqli $DB
 */
class ArchiveControlMySQL extends ipsmodule
{

    use BufferHelper,
        Database,
        DebugHelper,
        VariableWatch;

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Database', 'IPS');
        $this->RegisterPropertyString('Variables', json_encode(array()));

        $this->Vars = array();
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message)
        {
            case VM_UPDATE:
                $this->LogValue($SenderID, $Data[0], $Data[1], $Data[3]);
                break;
            case VM_DELETE:
                $this->UnregisterVariableWatch($SenderID);
                $Vars = $this->Vars;
                unset($Vars[$SenderID]);
                $this->Vars = $Vars;
                break;
        }
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function ApplyChanges()
    {

        parent::ApplyChanges();

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
//        $foundZero = false;
//        foreach ($ConfigVars as $Index => &$Item)
//        {
//            if ($Item['VariableId'] == 0)
//            {
//                array_splice($ConfigVars, $Index, 1);
//                $foundZero = true;
//            }
//        }
//        if ($foundZero)
//        {
//            $Variables = json_encode($ConfigVars);
//            IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
//            IPS_ApplyChanges($this->InstanceID);
//            //IPS_Sleep(1000);
//            return;
//        }
        $Vars = $this->Vars;
        foreach (array_keys($Vars) as $VarId)
        {
            $this->UnregisterVariableWatch($VarId);
        }
        $this->Vars = array();
        $Vars = array();

        foreach ($ConfigVars as $Item)
        {
            $VarId = $Item['VariableId'];
            if ($VarId <= 0)
                continue;
            if (!IPS_VariableExists($VarId))
                continue;
            if (array_key_exists($VarId, $Vars))
                continue;
            $this->RegisterVariableWatch($VarId);
            $Vars[$VarId] = IPS_GetVariable($VarId)['VariableType'];
        }
        $this->Vars = $Vars;

        if ($this->ReadPropertyString('Host') == '')
        {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (!$this->Login())
        {
            echo "Error logon Database";
            $this->SetStatus(IS_EBASE + 2);
            return;
        }
        if (!$this->SelectDB())
        {
            if (!$this->CreateDB())
            {
                echo "Create database failed";
                $this->SetStatus(IS_EBASE + 2);
                $this->Logout();
                return;
            }
        }
        $Result = true;
        foreach ($Vars as $VarId => $VarTyp)
        {
            if (!$this->TableExists($VarId))
                $Result = $Result && $this->CreateTable($VarId, $VarTyp);
        }
        if (!$Result)
        {
            echo "Error on create tables";
            $this->SetStatus(IS_EBASE + 3);
            $this->Logout();
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->Logout();
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function GetConfigurationForm()
    {

        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
        $this->Login();
        $Database = $this->SelectDB();
        $Found = array();
        $TableVarIDs = $this->GetVariableTables();
        for ($Index = 0; $Index < count($ConfigVars); $Index++)
        {
            $Item = &$ConfigVars[$Index];
            $VarId = $Item['VariableId'];
            if ($Item['VariableId'] == 0)
            {
                $Item['Variable'] = 'Objekt #0 existiert nicht';
                $Item['rowColor'] = "#ff0000";
                continue;
            }

            if (!IPS_ObjectExists($VarId))
            {
                $Item['Variable'] = 'Objekt #' . $VarId . ' existiert nicht';
                $Item['rowColor'] = "#ff0000";
            }
            else
            {
                if (!IPS_VariableExists($VarId))
                {
                    $Item['rowColor'] = "#ff0000";
                    $Item['Variable'] = 'Objekt #' . $VarId . ' ist keine Variable';
                }
                else
                    $Item['Variable'] = IPS_GetLocation($VarId);
            }
            if ($Database)
            {
                $Result = $this->GetSummary($VarId);
                if (!$Result)
                {
                    $Item['Count'] = 'Unbekannt';
                    $Item['FirstTimestamp'] = 'Unbekannt';
                    $Item['LastTimestamp'] = 'Unbekannt';
                    $Item['Size'] = 'Unbekannt';
                }
                else
                {
                    $Item['Count'] = $Result['Count'];
                    $Item['FirstTimestamp'] = strftime('%c', $Result['FirstTimestamp']);
                    $Item['LastTimestamp'] = strftime('%c', $Result['LastTimestamp']);
                    $Item['Size'] = sprintf('%.2f MB', ((int) $Result['Size'] / 1024 / 1024), 2);
                    $Key = array_search(array('VariableID' => $VarId), $TableVarIDs);
                    if ($Key !== false)
                        unset($TableVarIDs[$Key]);
                }
            }
            else
            {
                $Item['Count'] = 'Unbekannt';
                $Item['FirstTimestamp'] = 'Unbekannt';
                $Item['LastTimestamp'] = 'Unbekannt';
                $Item['Size'] = 'Unbekannt';
            }
            if (in_array($VarId, $Found))
            {
                $Item['rowColor'] = "#ffff00";
                continue;
            }
            $Found[] = $VarId;
        }
        unset($Item);
        // Hier fehlen nicht mehr geloggte Variablen von denen aber noch Tabellen vorhanden sind
        //$ConfigVars = array_values($ConfigVars);
//        foreach ($TableVarIDs as $Var)
//        {
//            $Item = array('VariableId' => -1, 'Variable' => '');
//            if (IPS_VariableExists($Var['VariableID']))
//                $Item['Variable'] = IPS_GetLocation($Var['VariableID']);
//
//            $Result = $this->GetSummary($Var['VariableID']);
//            if (!$Result)
//            {
//                $Item['Count'] = 'Unbekannt';
//                $Item['FirstTimestamp'] = 'Unbekannt';
//                $Item['LastTimestamp'] = 'Unbekannt';
//                $Item['Size'] = 'Unbekannt';
//            }
//            else
//            {
//                $Item['Count'] = $Result['Count'];
//                $Item['FirstTimestamp'] = strftime('%c', $Result['FirstTimestamp']);
//                $Item['LastTimestamp'] = strftime('%c', $Result['LastTimestamp']);
//                $Item['Size'] = sprintf('%.2f MB', ((int) $Result['Size'] / 1024 / 1024), 2);
//                $Item['rowColor'] = "#ff0000";
//            }
//            $ConfigVars[] = $Item;
//        }
        //$this->SendDebug('FORM', $ConfigVars, 0);
        $form['elements'][4]['values'] = $ConfigVars;
        $this->Logout();
        return json_encode($form);
    }

################## PRIVATE     

    private function LogValue($Variable, $NewValue, $HasChanged, $Timestamp)
    {
        $Vars = $this->Vars;
        if (!array_key_exists($Variable, $Vars))
            return false;
        if (!$this->Login())
        {
            if ($this->DB)
                echo $this->DB->connect_error;
            return false;
        }
        if (!$this->SelectDB())
        {
            echo $this->DB->error;
            return false;
        }
        switch ($Vars[$Variable])
        {
            case vtBoolean:
                $result = $this->WriteValue($Variable, (int) $NewValue, $HasChanged, $Timestamp);
                break;
            case vtInteger:
                $result = $this->WriteValue($Variable, $NewValue, $HasChanged, $Timestamp);
                break;
            case vtFloat:
                $result = $this->WriteValue($Variable, sprintf('%F', $NewValue), $HasChanged, $Timestamp);
                break;
            case vtString:
                $result = $this->WriteValue($Variable, "'" . $this->DB->real_escape_string($NewValue) . "'", $HasChanged, $Timestamp);
                break;
        }
        if (!$result)
            $this->SendDebug('Error on write', $Variable, 0);
        return $this->Logout();
    }

    private function LoginAndSelectDB()
    {
        if (!$this->Login())
        {
            if ($this->DB)
                trigger_error($this->DB->connect_error, E_USER_NOTICE);
            else
                trigger_error('No host for database', E_USER_NOTICE);
            return false;
        }
        if (!$this->SelectDB())
        {
            trigger_error($this->DB->error, E_USER_NOTICE);
            return false;
        }
        return true;
    }

    ################## PUBLIC

    public function ChangeVariableID(int $OldVariableID, int $NewVariableID)
    {
        if (!IPS_VariableExists($NewVariableID))
        {
            echo 'NewVariableID is no Variable.';
            return false;
        }

        if (!$this->LoginAndSelectDB())
            return false;

        $Vars = $this->Vars;

        if (array_key_exists($NewVariableID, $Vars))
        {
            trigger_error('NewVariableID is allready logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        if (!$this->TableExists($OldVariableID))
        {
            trigger_error('OldVariableID was not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        if (IPS_GetVariable($NewVariableID)['VariableType'] != $this->GetLoggedDataTyp($OldVariableID))
        {
            trigger_error('Old and new Datatyp not match.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }


        if (!$this->RenameTable($OldVariableID, $NewVariableID))
        {
            trigger_error('Error on rename table', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
        foreach ($ConfigVars as &$Item)
        {
            if ($Item['VariableId'] == $OldVariableID)
            {
                $Item['VariableId']=  $NewVariableID;
            }
        }
        $Variables = json_encode($ConfigVars);
        IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    public function DeleteVariableData(int $VariableID, int $Startzeit, int $Endzeit)
    {
        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('No data or VariableID found.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $Result = $this->DeleteData($VariableID, $Startzeit, $Endzeit);
        if ($Result === false)
        {
            trigger_error('Error on delete data.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $this->Logout();
        return $Result;
    }

    public function GetLoggedValues(int $VariableID, int $Startzeit, int $Endzeit, int $Limit)
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) or ( $Limit == 0))
            $Limit = IPS_GetOption('ArchiveRecordLimit');

        if ($Endzeit == 0)
            $Endzeit = time();

        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('VariableID was not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $Result = $this->GetLoggedData($VariableID, $Startzeit, $Endzeit, $Limit);
        if ($Result === false)
        {
            trigger_error('Error on fetch data.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        switch ($this->GetLoggedDataTyp($VariableID))
        {
            case vtBoolean:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (bool) $Item['Value'];
                }
                break;
            case vtInteger:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (int) $Item['Value'];
                }

                break;
            case vtFloat:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (float) $Item['Value'];
                }
                break;
            case vtString:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }

        $this->Logout();
        return $Result;
    }

    public function GetLoggingStatus(int $VariableID)
    {
        $Vars = $this->Vars;
        return array_key_exists($VariableID, $Vars);
    }

    public function SetLoggingStatus(int $VariableID, bool $Aktiv)
    {
        $Vars = $this->Vars;
        if ($Aktiv) //aktivieren
        {
            if (array_key_exists($VariableID, $Vars))
            {
                trigger_error('VariableID is allready logged.', E_USER_NOTICE);
                return false;
            }
            if (!IPS_VariableExists($VariableID))
            {
                trigger_error('VariableID is no Variable.', E_USER_NOTICE);
                return false;
            }
            $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
            $ConfigVars[] = array('VariableId' => $VariableID);
            $Variables = json_encode($ConfigVars);
            IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
            //IPS_ApplyChanges($this->InstanceID);
            return true;
        }
        else //deaktivieren
        {
            if (!array_key_exists($VariableID, $Vars))
            {
                trigger_error('VariableID was not logged.', E_USER_NOTICE);
                return false;
            }
            $ConfigVars = json_decode($this->ReadPropertyString('Variables'), true);
            foreach ($ConfigVars as $Index => &$Item)
            {
                if ($Item['VariableId'] == $VariableID)
                {
                    array_splice($ConfigVars, $Index, 1);
                    $ConfigVars = array_values($ConfigVars);
                    $Variables = json_encode($ConfigVars);
                    IPS_SetProperty($this->InstanceID, 'Variables', $Variables);
                    //IPS_ApplyChanges($this->InstanceID);
                    return true;
                }
            }
            return false;
        }
    }

    public function GetAggregationType(int $VariableID)
    {
        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('VariableID is not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return 0; //Standard, Zähler wird nicht unterstützt
    }

    public function GetGraphStatus(int $VariableID)
    {
        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('VariableID is not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    public function SetGraphStatus(int $VariableID, bool $Aktiv)
    {
        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('VariableID is not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    public function GetAggregatedValues(int $VariableID, int $Aggregationsstufe, int $Startzeit, int $Endzeit, int $Limit)
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) or ( $Limit == 0))
            $Limit = IPS_GetOption('ArchiveRecordLimit');

        if ($Endzeit == 0)
            $Endzeit = time();

        if (($Aggregationsstufe < 0) or ( $Aggregationsstufe > 6))
        {
            trigger_error('Invalid Aggregationsstage', E_USER_NOTICE);
            return false;
        }

        if (!$this->LoginAndSelectDB())
            return false;

        if (!$this->TableExists($VariableID))
        {
            trigger_error('VariableID was not logged.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $Result = $this->GetAggregatedData($VariableID, $Aggregationsstufe, $Startzeit, $Endzeit, $Limit);
        if ($Result === false)
        {
            trigger_error('Error on fetch data.', E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        switch ($this->GetLoggedDataTyp($VariableID))
        {
            case vtBoolean:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (bool) $Item['Min'];
                    $Item['Avg'] = (bool) $Item['Avg'];
                    $Item['Max'] = (bool) $Item['Max'];
                }
                break;
            case vtInteger:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (int) $Item['Min'];
                    $Item['Avg'] = (int) $Item['Avg'];
                    $Item['Max'] = (int) $Item['Max'];
                }

                break;
            case vtFloat:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (float) $Item['Min'];
                    $Item['Avg'] = (float) $Item['Avg'];
                    $Item['Max'] = (float) $Item['Max'];
                }
                break;
            case vtString:
                foreach ($Result as &$Item)
                {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }

        $this->Logout();
        return $Result;
    }

    public function GetAggregationVariables(bool $DatenbankAbfrage)
    {

        if (!$this->LoginAndSelectDB())
            return false;

        $Data = $this->GetVariableTables();
        $Vars = $this->Vars;
        foreach ($Data as &$Item)
        {
            $Result = $this->GetSummary($Item['VariableID']);
            $Item['RecordCount'] = (int) $Result['Count'];
            $Item['FirstTime'] = (int) $Result['FirstTimestamp'];
            $Item['LastTime'] = (int) $Result['LastTimestamp'];
            $Item['RecordSize'] = (int) $Result['Size'];
            $Item['AggregationType'] = 0;
            $Item['AggregationVisible'] = true;
            $Item['AggregationActive'] = array_key_exists($Item['VariableID'], $Vars);
        }
        return $Data;
        /*
         * FirstTime	integer	Datum/Zeit vom Beginn des Aggregationszeitraums als Unix Zeitstempel
          LastTime	integer	Datum/Zeit vom letzten Eintrag des Aggregationszeitraums als Unix Zeitstempel
          RecordCount	integer	Anzahl der Datensätze
          RecordSize	integer	Größe aller Datensätze in Bytes
          VariableID	integer	ID der Variable
          AggregationType	integer	Aggregationstyp als Integer. Siehe auch AC_GetAggregationType
          AggregationVisible	boolean	Gibt an ob die Variable in der Visualisierung angezeigt wird. Siehe auch AC_GetGraphStatus
          AggregationActive	boolean	Gibt an ob das Logging für diese Variable Aktiv ist. Siehe auch AC_GetLoggingStatus
         */
    }

}

/** @} */
    