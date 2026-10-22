<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MySQLArchiv.php';  // diverse Klassen
eval('namespace MySqlArchive {?>' . file_get_contents(__DIR__ . '/../libs/helper/SemaphoreHelper.php') . '}');

/**
 * ArchiveControlMySQL Klasse für die das loggen von Variablen in einer MySQL Datenbank.
 * Erweitert ipsmodule.
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2026 Michael Tröger
 * @license       Custom Non-Commercial Share-Alike License (see LICENSE)
 *
 * @version       3.50
 *
 * @example <b>Ohne</b>
 *
 * @property array $Vars
 * @property array $Buffer
 * @property array $ConfigFormBuffer
 * @property \mysqli $DB
 * @property int $LoadSummaryData
 * @method bool TraitLock(string $ident)
 * @method void unlock(string $ident)
 * @method bool SendDebug(string $Message, mixed $Data, int $Format)
 */
class ArchiveControlMySQL extends IPSModuleStrict
{
    use \MySqlArchive\Semaphore,
        \MySqlArchive\BufferHelper,
        \MySqlArchive\DebugHelper,
        \MySqlArchive\Database,
        \MySqlArchive\VariableWatch {
            \MySqlArchive\Semaphore::lock as TraitLock;
        }
    private $Runtime;

    /**
     * __construct
     *
     * @param  mixed $InstanceID
     * @return void
     */
    public function __construct($InstanceID)
    {
        $this->Runtime = microtime(true);
        $driver = new mysqli_driver();
        $driver->report_mode = MYSQLI_REPORT_OFF;
        parent::__construct($InstanceID);
    }

    /**
     * Create
     *
     * @return void
     */
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Database', 'IPS');
        $this->RegisterAttributeString('Variables', json_encode([]));
        $this->RegisterTimer('LogData', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "LogData", "");');
        $this->Vars = [];
        $this->Buffer = [];
        $this->ConfigFormBuffer = [];
        $this->LoadSummaryData = 0;
    }

    /**
     * Migrate
     *
     * @param  string $JSONData
     * @return string
     */
    public function Migrate(string $JSONData): string
    {
        $j = json_decode($JSONData);
        if (isset($j->configuration->Variables)) {
            $ConfigVars = json_decode($j->configuration->Variables, true);
            foreach ($ConfigVars as &$Var) {
                $Var['Active'] ??= true;
                $Var['IgnoreZeros'] ??= false;
            }
            $j->attributes->Variables = json_encode(array_values($ConfigVars));
            unset($j->configuration->Variables);
        }
        return json_encode($j);
    }

    /**
     * MessageSink
     *
     * @param  int $TimeStamp
     * @param  int $SenderID
     * @param  int $Message
     * @param  array $Data
     * @return void
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        /** @var array $_IPS */
        //Time critical start
        switch ($Message) {
            case VM_UPDATE:
                $this->lock('Buffer');
                $Buffer = $this->Buffer;
                $Buffer[] = [$SenderID, $Data[0], $Data[1], $Data[3]];
                $this->Buffer = $Buffer;
                $this->unlock('Buffer');
                $this->SendDebug('FetchData [' . $_IPS['THREAD'] . ']', 'Done', 0);
                if (count($Buffer) == 1) {
                    $this->SendDebug('Timer [' . $_IPS['THREAD'] . ']', 'Start', 0);
                    $this->SetTimerInterval('LogData', 5);
                }
                break;
            case VM_DELETE:
                $this->UnregisterVariableWatch($SenderID);
                $Vars = $this->Vars;
                unset($Vars[$SenderID]);
                $this->Vars = $Vars;
                break;
        }
        $this->SendDebug('MessageTime [' . $_IPS['THREAD'] . ']', sprintf('%.3f', ((microtime(true) - $this->Runtime) * 1000)) . ' ms', 0);
        //Time critical end
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $ConfigVars = $this->ReadAttributeArray('Variables');
        $Vars = $this->Vars;
        foreach (array_keys($Vars) as $VarId) {
            $this->UnregisterVariableWatch($VarId);
        }
        $this->Vars = [];
        $Vars = [];
        foreach ($ConfigVars as $Item) {
            $VarId = $Item['VariableId'];
            if ($VarId <= 0) {
                continue;
            }
            if (!IPS_VariableExists($VarId)) {
                continue;
            }
            if (array_key_exists($VarId, $Vars)) {
                continue;
            }
            if (!$Item['Active']) {
                continue;
            }
            $this->RegisterVariableWatch($VarId);
            $Vars[$VarId] = $Item['IgnoreZeros'] ?? false;
        }
        $this->Vars = $Vars;
        if ($this->ReadPropertyString('Host') == '') {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!$this->Login()) {
            echo $this->Translate('Cannot connect to database.');
            $this->SetStatus(IS_EBASE + 2);
            return;
        }
        if (!$this->SelectDB()) {
            if (!$this->CreateDB()) {
                echo $this->Translate('Create database failed.');
                $this->SetStatus(IS_EBASE + 2);
                $this->Logout();
                return;
            }
        }
        /*
        $Result = true;
        foreach ($Vars as $VarId => $VarTyp) {
            if (!$this->TableExists($VarId)) {
                $Result = $Result && $this->CreateTable($VarId, $VarTyp);
            }
        }
        if (!$Result) {
            echo $this->Translate('Error on create tables.');
            $this->SetStatus(IS_EBASE + 3);
            $this->Logout();
            return;
        }*/
        $this->SetStatus(IS_ACTIVE);
        $this->Logout();
    }

    /**
     * RequestAction
     *
     * @param  string $Ident
     * @param  mixed $Value
     * @return void
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'UILoadSummaryData':
                $this->UILoadSummaryData();
                break;
            case 'LogData':
                $this->LogData();
                break;
            case 'Reload':
                $this->ReloadForm();
                break;
            case 'ShowConnectionWarning':
                // Hinweis nur einmal je geöffnetem Formular anzeigen
                $this->UpdateFormField('ConnectionPanel', 'onClick', '');
                $this->UpdateFormField('ConnectionWarning', 'visible', true);
                break;
        }
    }

    /**
     * GetConfigurationForm
     *
     * @return string
     */
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $ConfigVars = $this->ReadAttributeArray('Variables');
        $ConfigVarsIds = array_column($ConfigVars, 'VariableId');

        $TableVarsIds = [];

        $UseDB = false;
        $UseDB = $this->Login();
        if ($UseDB) {
            $UseDB = $this->SelectDB();
        }
        if ($UseDB) {
            $TableVars = $this->GetVariableTables();
            $TableVarsIds = array_column($TableVars, 'VariableID');
        }
        if (!$UseDB) {
            //** @todo  PopUp Meldung fehlt noch, das DB nicht erreichbar ist. */
        }
        $Values = [];
        foreach ($ConfigVars as &$Var) {
            $Var['Active'] ??= true;
            $Var['IgnoreZeros'] ??= false;
            $AddItem = [
                'VariableId'    => $Var['VariableId'],
                'Active'        => $Var['Active'],
                'IgnoreZeros'   => $Var['IgnoreZeros'],
                'FirstTimestamp'=> $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'LastTimestamp' => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'Count'         => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'Size'          => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
            ];
            if (!IPS_VariableExists($Var['VariableId'])) {
                $AddItem['rowColor'] = '#ff0000';
            }
            $Values[] = $AddItem;
        }
        $InactiveVarsIds = array_diff($TableVarsIds, $ConfigVarsIds);
        foreach ($InactiveVarsIds as $VarId) {
            $AddItem = [
                'VariableId'    => $VarId,
                'Active'        => false,
                'IgnoreZeros'   => false,
                'FirstTimestamp'=> $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'LastTimestamp' => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'Count'         => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
                'Size'          => $UseDB ? $this->Translate('loading') : $this->Translate('error, not connected'),
            ];

            if (!IPS_VariableExists($VarId)) {
                $AddItem['rowColor'] = '#ff0000';
            }
            $ConfigVars[] = [
                'VariableId'    => $VarId,
                'Active'        => false,
                'IgnoreZeros'   => false,
            ];
            $Values[] = $AddItem;
        }
        if (count($InactiveVarsIds)) {
            $ConfigVars = array_values($ConfigVars);
            $this->WriteAttributeArray('Variables', $ConfigVars);
        }
        $form['actions'][0]['form'] = $this->ListFormCode();
        $form['actions'][0]['values'] = $Values;
        $form['actions'][1]['onClick'] = '$id=IPS_CreateScript(0);'
                . 'IPS_SetParent($id, ' . $this->InstanceID . ');'
                . 'IPS_SetName($id,"Highcharts.ips.php");'
                . 'IPS_SetScriptContent($id, file_get_contents("' . __DIR__ . '/../docs/Highcharts.ips.php"));'
                . 'echo "' . $this->Translate('Done') . '";';
        $this->Logout();
        $this->SendDebug('FORM', json_encode($form), 0);
        $this->ConfigFormBuffer = $Values;
        IPS_RunScriptText('IPS_RequestAction(' . $this->InstanceID . ', "UILoadSummaryData", "");');
        return json_encode($form);
    }

    /**
     * AddLoggedValues
     *
     * @param  int $VariableID
     * @param  array $Values
     * @return bool
     */
    public function AddLoggedValues(int $VariableID, array $Values): bool
    {
        if (!$this->Login()) {
            if ($this->DB) {
                echo $this->DB->connect_error;
            }
            return false;
        }
        if (!$this->SelectDB()) {
            echo $this->DB->error;
            return false;
        }
        foreach ($Values as $Item) {
            if (count(array_diff_key($Item, ['TimeStamp' => 0, 'Value' => 0]))) {
                $this->SendDebug('Error', 'Invalid Values array', 0);
                trigger_error($this->Translate('Invalid Values array'), E_USER_NOTICE);
                return false;
            }
            $this->SendDebug('AddLoggedValues', 'Timestamp: ' . $Item['TimeStamp'] . ' Value: ' . $Item['Value'], 0);
            if (!$this->LogValue($VariableID, $Item['Value'], true, (int) $Item['TimeStamp'])) {
                $this->SendDebug('Error', 'on import Data', 0);
                trigger_error($this->Translate('Error on import Values'), E_USER_NOTICE);
                return false;
            }
        }
        $this->UpdateConfigFormRow($VariableID);
        $this->Logout();
        return true;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_ChangeVariableID
     * Zum überführen von geloggten Daten auf eine neue Variable.
     *
     * @param int $OldVariableID Alte VariablenID
     * @param int $NewVariableID Neue VariablenID
     *
     * @return bool True bei Erfolg, sonst false.
     */
    public function ChangeVariableID(int $OldVariableID, int $NewVariableID): bool
    {
        if (!IPS_VariableExists($NewVariableID)) {
            trigger_error($this->Translate('NewVariableID is no variable.'), E_USER_NOTICE);
            return false;
        }
        if (!$this->LoginAndSelectDB()) {
            return false;
        }
        $Vars = $this->Vars;
        if (array_key_exists($NewVariableID, $Vars)) {
            trigger_error($this->Translate('NewVariableID is already logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        if (!$this->TableExists($OldVariableID)) {
            trigger_error($this->Translate('OldVariableID was not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        if (IPS_GetVariable($NewVariableID)['VariableType'] != $this->GetLoggedDataTyp($OldVariableID)) {
            trigger_error($this->Translate('Old and new Datatyp not match.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        if (!$this->RenameTable($OldVariableID, $NewVariableID)) {
            trigger_error($this->Translate('Error on rename table.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $ConfigVars = $this->ReadAttributeArray('Variables');
        foreach ($ConfigVars as &$Item) {
            if ($Item['VariableId'] == $OldVariableID) {
                $Item['VariableId'] = $NewVariableID;
            }
        }
        $this->WriteAttributeArray('Variables', $ConfigVars);
        $this->UnregisterVariableWatch($OldVariableID);
        $this->RegisterVariableWatch($NewVariableID);
        $Vars[$NewVariableID] = $Vars[$OldVariableID];
        unset($Vars[$OldVariableID]);
        $this->Vars = array_values($Vars);
        $this->UpdateConfigFormRow($OldVariableID, $NewVariableID);
        $this->Logout();
        return true;
    }

    /**
     * ClearVariableData
     *
     * @param  int $VariableID
     * @return bool
     */
    public function ClearVariableData(int $VariableID): bool
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }
        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('No data or VariableID found.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $Result = $this->DeleteTable($VariableID);
        if ($Result === false) {
            trigger_error($this->Translate('Error on clear data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $this->Logout();
        return true;
    }

    /**
     * DeleteVariable
     *
     * @param  int $VariableID
     * @return bool
     */
    public function DeleteVariable(int $VariableID): bool
    {
        $Vars = $this->Vars;
        $ConfigVars = $this->ReadAttributeArray('Variables');
        foreach ($ConfigVars as $Index => $Item) {
            if ($Item['VariableId'] == $VariableID) {
                $this->UnregisterVariableWatch($VariableID);
                unset($Vars[$VariableID]);
                $this->Vars = $Vars;
                unset($ConfigVars[$Index]);
                $ConfigVars = array_values($ConfigVars);
                $this->WriteAttributeArray('Variables', $ConfigVars);
                return $this->ClearVariableData($VariableID);
            }
        }
        return false;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_DeleteVariableData
     * Zum löschen einer Zeitspanne von Werten.
     *
     * @param int $VariableID VariablenID der zu löschenden Daten.
     * @param int $Startzeit  Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit    Endzeitpunkt als UnixTimestamp
     *
     * @return false|int gibt die Anzahl der gelöschten Datensätze zurück
     */
    public function DeleteVariableData(int $VariableID, int $Startzeit, int $Endzeit): false|int
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('No data or VariableID found.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }

        $Result = $this->DeleteData($VariableID, $Startzeit, $Endzeit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on delete data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $this->UpdateConfigFormRow($VariableID);
        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetAggregatedValues
     * Liefert aggregierte Daten einer geloggte Variable.
     *
     * @param int $VariableID        VariablenID der zu liefernden Daten.
     * @param int $Aggregationsstufe
     * @param int $Startzeit         Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit           Endzeitpunkt als UnixTimestamp
     * @param int $Limit             Anzahl der max. Datensätze. Bei 0 wird das HardLimit genutzt.
     *
     * @return array Datensätze
     */
    public function GetAggregatedValues(int $VariableID, int $Aggregationsstufe, int $Startzeit, int $Endzeit, int $Limit): false|array
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) || ($Limit == 0)) {
            $Limit = IPS_GetOption('ArchiveRecordLimit');
        }
        if ($Endzeit == 0) {
            $Endzeit = time();
        }
        if (($Aggregationsstufe < 0) || ($Aggregationsstufe > 6)) {
            trigger_error($this->Translate('Invalid Aggregationstage'), E_USER_NOTICE);
            return false;
        }
        if (!$this->LoginAndSelectDB()) {
            return false;
        }
        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $Result = $this->GetAggregatedData($VariableID, $Aggregationsstufe, $Startzeit, $Endzeit, $Limit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on fetch data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        switch ($this->GetLoggedDataTyp($VariableID)) {
            case VARIABLETYPE_BOOLEAN:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (bool) $Item['Min'];
                    $Item['Avg'] = (bool) $Item['Avg'];
                    $Item['Max'] = (bool) $Item['Max'];
                }
                break;
            case VARIABLETYPE_INTEGER:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (int) $Item['Min'];
                    $Item['Avg'] = (int) $Item['Avg'];
                    $Item['Max'] = (int) $Item['Max'];
                }

                break;
            case VARIABLETYPE_FLOAT:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Min'] = (float) $Item['Min'];
                    $Item['Avg'] = (float) $Item['Avg'];
                    $Item['Max'] = (float) $Item['Max'];
                }
                break;
            case VARIABLETYPE_STRING:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }

        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetAggregationType
     * Liefert immer 0, da Typ Zähler nicht unterstützt wird.
     *
     * @param int $VariableID VariablenID der zu liefernden Daten.
     *
     * @return int
     */
    public function GetAggregationType(int $VariableID): int
    {
        if (!$this->LoginAndSelectDB()) {
            return 0;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return 0;
        }
        return 0; //Standard, Zähler wird nicht unterstützt
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetAggregationVariables
     * Liefert eine Übersicht über alle geloggte Daten.
     *
     * @param bool $DatenbankAbfrage ohne Funktion.
     *
     * @return array Datensätze
     */
    public function GetAggregationVariables(bool $DatenbankAbfrage): false|array
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        $Data = $this->GetVariableTables();
        $Vars = $this->Vars;
        foreach ($Data as &$Item) {
            $Result = $this->GetSummary($Item['VariableID']) ?: [];
            $Item['RecordCount'] = (int) ($Result['Count'] ?? 0);
            $Item['FirstTime'] = (int) ($Result['FirstTimestamp'] ?? 0);
            $Item['LastTime'] = (int) ($Result['LastTimestamp'] ?? 0);
            $Item['RecordSize'] = (int) ($Result['Size'] ?? 0);
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

    /**
     * GetCounterIgnoreZeros
     *
     * @param  int $VariableID
     * @return void
     */
    public function GetCounterIgnoreZeros(int $VariableID): ?bool
    {
        $ConfigVars = $this->ReadAttributeArray('Variables');
        foreach ($ConfigVars as &$Item) {
            if ($Item['VariableId'] == $VariableID) {
                return $Item['IgnoreZeros'];
            }
        }
        trigger_error($this->Translate('VariableID was not logged.'), E_USER_NOTICE);
        return null;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetGraphStatus
     * Liefert immer true, da diese Funktion nicht unterstützt wird.
     *
     * @param int $VariableID
     *
     * @return bool immer True, außer VariableID wird nicht geloggt.
     */
    public function GetGraphStatus(int $VariableID): bool
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetLoggedValues
     * Liefert geloggte Daten einer Variable.
     *
     * @param int $VariableID VariablenID der zu liefernden Daten.
     * @param int $Startzeit  Startzeitpunkt als UnixTimestamp
     * @param int $Endzeit    Endzeitpunkt als UnixTimestamp
     * @param int $Limit      Anzahl der max. Datensätze. Bei 0 wird das HardLimit genutzt.
     *
     * @return array Datensätze
     */
    public function GetLoggedValues(int $VariableID, int $Startzeit, int $Endzeit, int $Limit): false|array
    {
        if (($Limit > IPS_GetOption('ArchiveRecordLimit')) || ($Limit == 0)) {
            $Limit = IPS_GetOption('ArchiveRecordLimit');
        }
        if ($Endzeit == 0) {
            $Endzeit = time();
        }
        if (!$this->LoginAndSelectDB()) {
            return false;
        }
        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID was not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        $Result = $this->GetLoggedData($VariableID, $Startzeit, $Endzeit, $Limit);
        if ($Result === false) {
            trigger_error($this->Translate('Error on fetch data.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        switch ($this->GetLoggedDataTyp($VariableID)) {
            case VARIABLETYPE_BOOLEAN:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (bool) $Item['Value'];
                }
                break;
            case VARIABLETYPE_INTEGER:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (int) $Item['Value'];
                }

                break;
            case VARIABLETYPE_FLOAT:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                    $Item['Value'] = (float) $Item['Value'];
                }
                break;
            case VARIABLETYPE_STRING:
                foreach ($Result as &$Item) {
                    $Item['TimeStamp'] = (int) $Item['TimeStamp'];
                }
                break;
        }
        $this->Logout();
        return $Result;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_GetLoggingStatus
     * Liefert ob eine Variable aktuell geloggt wird.
     *
     * @param int $VariableID Die zu prüfende VariablenID
     *
     * @return bool True wenn logging aktiv ist.
     */
    public function GetLoggingStatus(int $VariableID): bool
    {
        $Vars = $this->Vars;
        return array_key_exists($VariableID, $Vars);
    }

    /**
     * ReAggregateVariable
     *
     * @param  int $VariableId
     * @return bool
     */
    public function ReAggregateVariable(int $VariableId): bool
    {
        return true;
    }

    /**
     * SetAggregationType
     *
     * @param  int $VariableID
     * @param  int $Aggregationstyp
     * @return bool
     */
    public function SetAggregationType(int $VariableID, int $Aggregationstyp): bool
    {
        return true;
    }

    /**
     * SetCompaction
     *
     * @param  int $VariableID
     * @param  int $MonatsVersatz
     * @param  int $Verdichtungstyp
     * @return bool
     *
     * @todo Implementieren
     */
    public function SetCompaction(int $VariableID, int $MonatsVersatz, int $Verdichtungstyp): bool
    {
        return true;
    }

    /**
     * SetCounterIgnoreZeros
     *
     * @param  int $VariableID
     * @param  bool $IgnoreZeros
     * @return bool
     */
    public function SetCounterIgnoreZeros(int $VariableID, bool $IgnoreZeros): bool
    {
        $ConfigVars = $this->ReadAttributeArray('Variables');
        foreach ($ConfigVars as $Index => &$Item) {
            if ($Item['VariableId'] == $VariableID) {
                $Item['IgnoreZeros'] = $IgnoreZeros;
                $ConfigVars = array_values($ConfigVars);
                $this->WriteAttributeArray('Variables', $ConfigVars);
                $Vars = $this->Vars;
                $Vars[$VariableID] = $IgnoreZeros;
                $this->Vars = $Vars;
                if ($this->LoginAndSelectDB()) {
                    $this->UpdateConfigFormRow($VariableID);
                    $this->Logout();
                }
                return true;
            }
        }
        return false;
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_SetGraphStatus
     * Liefert immer true, da diese Funktion nicht unterstützt wird.
     *
     * @param int  $VariableID VariablenID
     * @param bool $Aktiv      ohne Funktion
     *
     * @return bool immer True, außer VariableID wird nicht geloggt.
     */
    public function SetGraphStatus(int $VariableID, bool $Aktiv): bool
    {
        if (!$this->LoginAndSelectDB()) {
            return false;
        }

        if (!$this->TableExists($VariableID)) {
            trigger_error($this->Translate('VariableID is not logged.'), E_USER_NOTICE);
            $this->Logout();
            return false;
        }
        return true; //wird nur emuliert
    }

    /**
     * IPS-Instanz-Funktion ACMYSQL_SetLoggingStatus
     * De-/Aktiviert das logging einer Variable.
     * Wird erst nach IPS_Applychanges($MySQLArchivID) aktiv.
     *
     * @param int  $VariableID Die zu loggende VariablenID
     * @param bool $Aktiv      True zum logging aktivieren, false zum deaktivieren.
     *
     * @return bool True bei Erfolg, sonst false.
     */
    public function SetLoggingStatus(int $VariableID, bool $Aktiv): bool
    {
        $Vars = $this->Vars;
        if ($Aktiv) { //aktivieren
            if (array_key_exists($VariableID, $Vars)) {
                trigger_error($this->Translate('VariableID is already logged.'), E_USER_NOTICE);
                return false;
            }
            if (!IPS_VariableExists($VariableID)) {
                trigger_error($this->Translate('VariableID is no Variable.'), E_USER_NOTICE);
                return false;
            }
            $ConfigVars = $this->ReadAttributeArray('Variables');
            $New = true;
            foreach ($ConfigVars as $Index => &$Item) {
                if ($Item['VariableId'] == $VariableID) {
                    $Item['Active'] = true;
                    $New = false;
                }
            }
            if ($New) {
                if (!$this->LoginAndSelectDB()) {
                    trigger_error($this->Translate('Error on connect.'), E_USER_NOTICE);
                    return false;
                }
                if (!$this->CreateTable($VariableID, IPS_GetVariable($VariableID)['VariableType'])) {
                    trigger_error($this->Translate('Error on create table.'), E_USER_NOTICE);
                    return false;
                }
                $this->Logout();
                $ConfigVars[] = ['VariableId' => $VariableID, 'Active'=>true, 'IgnoreZeros' => false];
                $Vars[$VariableID] = false;
                $this->Vars = $Vars;
            }
            $ConfigVars = array_values($ConfigVars);
            $this->WriteAttributeArray('Variables', $ConfigVars);
            $this->RegisterVariableWatch($VariableID);
            if ($New) {
                $this->ReloadForm();
            } else {
                if ($this->LoginAndSelectDB()) {
                    $this->UpdateConfigFormRow($VariableID);
                    $this->Logout();
                }
            }
            return true;
        } else { //deaktivieren
            $ConfigVars = $this->ReadAttributeArray('Variables');
            foreach ($ConfigVars as $Index => &$Item) {
                if ($Item['VariableId'] == $VariableID) {
                    $Item['Active'] = false;
                    $ConfigVars = array_values($ConfigVars);
                    $this->WriteAttributeArray('Variables', $ConfigVars);
                    $this->UnregisterVariableWatch($VariableID);
                    unset($Vars[$VariableID]);
                    $this->Vars = $Vars;
                    if ($this->LoginAndSelectDB()) {
                        $this->UpdateConfigFormRow($VariableID);
                        $this->Logout();
                    }
                    return true;
                }
            }
            trigger_error($this->Translate('VariableID was not logged.'), E_USER_NOTICE);
            return false;
        }
    }

    private function UILoadSummaryData(): void
    {
        /** @var array $_IPS */
        $this->LoadSummaryData = $_IPS['THREAD'];
        $Rows = $this->ConfigFormBuffer;
        if (!$this->LoginAndSelectDB()) {
            foreach ($Rows as &$Row) {
                $Row['FirstTimestamp'] = $this->Translate('error, not connected');
                $Row['LastTimestamp'] = $this->Translate('error, not connected');
                $Row['Count'] = $this->Translate('error, not connected');
                $Row['Size'] = $this->Translate('error, not connected');
            }
            $this->UpdateFormField('Variables', 'values', json_encode($Rows));
            $this->LoadSummaryData = 0;
            return;
        }
        foreach ($Rows as $Row => $ColumnData) {
            if ($this->LoadSummaryData != $_IPS['THREAD']) {
                return;
            }
            $Result = $this->GetSummary($ColumnData['VariableId']);
            $this->SendDebug('GetSummary', $Result, 0);
            if (!$Result) {
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Count', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.FirstTimestamp', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.LastTimestamp', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Size', $this->Translate('error on read data'));
            } else {
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Count', $Result['Count']);
                $this->UpdateFormField('Variables', 'values.' . $Row . '.FirstTimestamp', $Result['FirstTimestamp'] == 0 ? $this->Translate('no data') : strftime('%c', $Result['FirstTimestamp']));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.LastTimestamp', $Result['LastTimestamp'] == 0 ? $this->Translate('no data') : strftime('%c', $Result['LastTimestamp']));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Size', sprintf('%.2f MB', ($Result['Size'] / 1024 / 1024), 2));
            }
        }
        $this->LoadSummaryData = 0;
        $this->Logout();
    }

    /**
     * UpdateConfigFormRow
     *
     * @param  int $VariableID
     * @param  int $NewVariableId
     * @return void
     */
    private function UpdateConfigFormRow(int $VariableID, int $NewVariableId = 0): void
    {

        $Rows = $this->ConfigFormBuffer;
        $Row = array_search($VariableID, array_column($Rows, 'VariableId'));
        if ($Row !== false) {
            if ($NewVariableId) {
                $this->UpdateFormField('Variables', 'values.' . $Row . '.VariableId', $NewVariableId);
                $this->UpdateFormField('VariableId', 'value', $NewVariableId);
                $VariableID = $NewVariableId;
            }
            $Active = false;
            $IgnoreZeros = false;
            $ConfigVars = $this->ReadAttributeArray('Variables');
            foreach ($ConfigVars as &$Item) {
                if ($Item['VariableId'] == $VariableID) {
                    $Active = $Item['Active'];
                    $IgnoreZeros = $Item['IgnoreZeros'];
                }
            }
            $this->UpdateFormField('Variables', 'values.' . $Row . '.Active', $Active);
            $this->UpdateFormField('Variables', 'values.' . $Row . '.IgnoreZeros', $IgnoreZeros);
            $this->UpdateFormField('Variables', 'values.' . $Row . '.Count', $this->Translate('loading'));
            $this->UpdateFormField('Variables', 'values.' . $Row . '.FirstTimestamp', $this->Translate('loading'));
            $this->UpdateFormField('Variables', 'values.' . $Row . '.LastTimestamp', $this->Translate('loading'));
            $this->UpdateFormField('Variables', 'values.' . $Row . '.Size', $this->Translate('loading'));
            $Result = $this->GetSummary($VariableID);
            $this->SendDebug('GetSummary', $Result, 0);
            if (!$Result) {
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Count', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.FirstTimestamp', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.LastTimestamp', $this->Translate('error on read data'));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Size', $this->Translate('error on read data'));
            } else {
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Count', $Result['Count']);
                $this->UpdateFormField('Variables', 'values.' . $Row . '.FirstTimestamp', $Result['FirstTimestamp'] == 0 ? $this->Translate('no data') : strftime('%c', $Result['FirstTimestamp']));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.LastTimestamp', $Result['LastTimestamp'] == 0 ? $this->Translate('no data') : strftime('%c', $Result['LastTimestamp']));
                $this->UpdateFormField('Variables', 'values.' . $Row . '.Size', sprintf('%.2f MB', ($Result['Size'] / 1024 / 1024), 2));
            }
        }
    }

    /**
     * Versucht eine Semaphore zu setzen und wiederholt dies bei Misserfolg bis zu 100 mal.
     *
     * @param string $ident Ein String der den Lock bezeichnet.
     *
     * @return bool TRUE bei Erfolg, FALSE bei Misserfolg.
     */
    private function lock(string $ident): bool
    {
        /** @var array $_IPS */
        $Runtime = microtime(true);
        $Result = $this->TraitLock($ident);
        $this->SendDebug('WaitLock [' . $_IPS['THREAD'] . ']', sprintf('%.3f', ((microtime(true) - $Runtime) * 1000))
                . ' ms', 0);
        return $Result;
    }

    /**
     * LogData
     *
     * @return void
     */
    private function LogData(): void
    {
        /** @var array $_IPS */
        $this->SendDebug('Timer [' . $_IPS['THREAD'] . ']', 'Stop', 0);
        $this->SetTimerInterval('LogData', 0);
        //Time critical start
        $this->lock('Buffer');
        $Buffer = $this->Buffer;
        $this->Buffer = [];
        $this->unlock('Buffer');
        //Time critical end
        $this->SendDebug('LogData [' . $_IPS['THREAD'] . ']', count($Buffer) . ' entries', 0);
        if (!$this->Login()) {
            if ($this->DB) {
                echo $this->DB->connect_error;
            }
            return;
        }
        if (!$this->SelectDB()) {
            echo $this->DB->error;
            return;
        }
        $Vars = $this->Vars;
        foreach ($Buffer as $Data) {
            if (!array_key_exists($Data[0], $Vars)) {
                continue;
            }
            if ($Vars[$Data[0]]) {
                //Ignore Zeros aktiv?
                if ($Data[1] <= 0) {
                    continue;
                }
            }
            $Runtime = microtime(true);
            $this->LogValue($Data[0], $Data[1], $Data[2], $Data[3]);
            $this->SendDebug('LogData [' . $_IPS['THREAD'] . ']', sprintf('%.3f', ((microtime(true) - $Runtime) * 1000))
                    . ' ms ('
                    . sprintf('%.3f', ((microtime(true) - $this->Runtime) * 1000)) . ' ms)', 0);
        }
        $this->Logout();
        return;
    }

    /**
     * Werte loggen.
     *
     * @param int   $Variable   VariablenID
     * @param mixed $NewValue   Neuer Wert der Variable
     * @param bool  $HasChanged true wenn neuer Wert vom alten abweicht
     * @param int   $Timestamp  Zeitstempel des neuen Wert
     * @return bool
     */
    private function LogValue(int $Variable, mixed $NewValue, bool $HasChanged, int $Timestamp): bool
    {
        /** @var array $_IPS */
        $Vars = $this->Vars;
        if (!array_key_exists($Variable, $Vars)) {
            return false;
        }
        if (!IPS_VariableExists($Variable)) {
            return false;
        }
        // $Vars enthält nur die Option 'IgnoreZeros', der Datentyp kommt von der Variable selbst.
        $VarTyp = IPS_GetVariable($Variable)['VariableType'];
        $result = false;
        switch ($VarTyp) {
            case VARIABLETYPE_BOOLEAN:
                $result = $this->WriteValue($Variable, (int) $NewValue, $HasChanged, $Timestamp);
                break;
            case VARIABLETYPE_INTEGER:
                $result = $this->WriteValue($Variable, $NewValue, $HasChanged, $Timestamp);
                break;
            case VARIABLETYPE_FLOAT:
                $result = $this->WriteValue($Variable, sprintf('%F', $NewValue), $HasChanged, $Timestamp);
                break;
            case VARIABLETYPE_STRING:
                $result = $this->WriteValue($Variable, "'" . $this->DB->real_escape_string($NewValue) . "'", $HasChanged, $Timestamp);
                break;
        }
        if (!$result && ($this->DB->errno === \MySqlArchive\TableNotFoundError)) {
            // Tabelle fehlt (z.B. nach Wechsel der Datenbank): anlegen und Wert erneut schreiben.
            $this->SendDebug('CreateTable', $Variable, 0);
            if ($this->CreateTable($Variable, $VarTyp)) {
                return $this->LogValue($Variable, $NewValue, $HasChanged, $Timestamp);
            }
        }
        if (!$result) {
            $this->SendDebug('Error on write [' . $_IPS['THREAD'] . ']', $Variable, 0);
        }
        return $result;
    }

    /**
     * Anmelden am MySQL-Server uns auswählen der Datenbank.
     * Für alle public Methoden, welche Fehler ausgeben sollen.
     *
     * @return bool True bei Erfolg, sonst false.
     */
    private function LoginAndSelectDB(): bool
    {
        if (!$this->Login()) {
            if ($this->DB) {
                trigger_error($this->DB->connect_error, E_USER_NOTICE);
            } else {
                trigger_error($this->Translate('No host for database'), E_USER_NOTICE);
            }
            return false;
        }
        if (!$this->SelectDB()) {
            trigger_error($this->DB->error, E_USER_NOTICE);
            return false;
        }
        return true;
    }

    /**
     * ReadAttributeArray
     *
     * @param  string $Attribute
     * @return array
     */
    private function ReadAttributeArray(string $Attribute): array
    {
        return json_decode($this->ReadAttributeString($Attribute), true);
    }

    /**
     * WriteAttributeArray
     *
     * @param  string $Attribute
     * @param  array $Value
     * @return void
     */
    private function WriteAttributeArray(string $Attribute, array $Value): void
    {
        $this->WriteAttributeString($Attribute, json_encode($Value));
    }

    /**
     * ListFormCode
     *
     * @return string
     */
    private function ListFormCode(): string
    {

        return "return
[
    [
        'type'  => 'RowLayout',
        'items' => [
            [
                'caption' => 'Active',
                'name'    => 'Active',
                'type'    => 'CheckBox',
                'width'   => '250px',
                'visible' => \$Variables['VariableId'] == 1 ? false : true,
            ],
            [
                'caption' => 'Ignore Zeros',
                'name'    => 'IgnoreZeros',
                'type'    => 'CheckBox',
                'width'   => '250px',
            ],
        ],

    ],
    [
        'caption'  => 'Variable',
        'enabled'  => \$Variables['VariableId'] == 1 ? true : false,
        'name'     => 'VariableId',
        'type'     => 'SelectVariable',
        'width'    => '500px'
    ],
    [
        'type'  => 'RowLayout',
        'items' => [
            [
                'caption' => 'First value',
                'enabled' => false,
                'name'    => 'FirstTimestamp',
                'type'    => 'ValidationTextBox',
                'width'   => '250px',
            ],
            [
                'caption' => 'Data records',
                'enabled' => false,
                'name'    => 'Count',
                'type'    => 'ValidationTextBox',
                'width'   => '250px',
            ],
        ],
    ],
    [
        'type'  => 'RowLayout',
        'items' => [
            [
                'caption' => 'Last value',
                'enabled' => false,
                'name'    => 'LastTimestamp',
                'type'    => 'ValidationTextBox',
                'width'   => '250px',
            ],
            [
                'caption' => 'Size',
                'enabled' => false,
                'name'    => 'Size',
                'type'    => 'ValidationTextBox',
                'width'   => '250px',
            ],
        ],
    ],
    [
        'type'  => 'RowLayout',
        'items' => [
            [
                'image'   => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAC4AAAAuCAIAAADY27xgAAAABnRSTlMA/wD/AP83WBt9AAABGUlEQVRYw2P8//8/w+AATAPtgFGnjDpl1CnDziksyJzmGasoN7E2I4w8jYzw0vbhs1dqXlkUukNeSuzWtmnk6R1EETSInIKSVmY3ZVNonIKUGNl6GUdr5sHtFJS0QnlmZmBggGfmQ2euptRNhbBj/RwIljcIpzx89urhs1cUukMeKdk+INHAQRRBg8gpiAiSlxL7eWHNADplEIXKIHIKSmYmKcEjZxaCGh89e41VDbIh5DcSbm2bBjeI3SCEvJBANmQQRdAgcgpKWonzcyTPFKwaHz57dfDMVQhbXkrM3kSbgCn/aQYWbtzHph8MQU3TVxJUP4giaNQpo04Zgk6hYYsfuVyxN9GWJ9QvGe18jDpl1CmjThnGTgEAW7XWLP4z/9MAAAAASUVORK5CYII=',
                'link'    => false,
                'type'    => 'Image',
                'width'   => '46px',
            ],
            [
                'caption' => 'Import Data',
                'type'    => 'PopupButton',
                'width'   => '200px',
                'enabled' => \$Variables['VariableId'] == 1 ? false : true,
                'popup'   => [
                    'caption'      => 'Select Variable to Transfer Data To',
                    'closeCaption' => 'Cancel',
                    'items'        => [
                        [
                            'caption'    => 'Select CSV File',
                            'extensions' => '.csv',
                            'name'       => 'ImportFile',
                            'type'       => 'SelectFile',
                            'width'      => '200px',
                        ],
                    ],
                    'buttons' => [
                        [
                            'caption' => 'Import',
                            'confirm' => 'Are you sure?',
                            'onClick' => [
                                '\$handle  = fopen(\'data://text/plain;base64,\'.\$ImportFile,\'r\');',
                                '\$Data=[];',
                                'while ((\$RowData = fgetcsv(\$handle, 1000, \',\')) !== FALSE) {',
                                '   if (count(\$RowData) != 2){',
                                '       continue;',
                                '   }',
                                '   \$Data[] = [\'TimeStamp\'=>\$RowData[0], \'Value\'=>\$RowData[1]];',
                                '}',
                                'if (ACMYSQL_AddLoggedValues(\$id, \$VariableId, \$Data) === false){',
                                '  echo(\'" . $this->Translate('Error on import data.') . "\');',
                                '} else {',
                                '  echo(\'MESSAGE:" . $this->Translate('Data imported successfully.') . "\');',
                                '}',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'image'   => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAC4AAAAuCAIAAADY27xgAAAABnRSTlMA/wD/AP83WBt9AAABdElEQVRYw2P8//8/w+AATAPtgFGnjDplSDuFhaCKh89eHTxzFVPc3kRbXkqMfk45dOaqa0o9Vil5KbHdcxqo6BpG5CJu0ab9qOHxumXGKjya5aXEajJC0QTj/BwpdcrDZ6/UvLIo9Jm8lNitbdPI0zuIku0gcgpKsp3dlE2hcQoUpGLGwVMzo2fmh89etcxYTZ5Z8EAl3pCajFB4cYDplNdoWZpIgFzAEG8IclkwiJLtIHIK4TqIDCAvJUpkQYccrTRyCjlZehBF0CByCk0iCE/TAg52z2m0M9EepKEyiJxCkwhiICsT0cQpdibaZDSgBlEEDSKn0CqtYK2Z8fdXaFWupNZNxRSf3ZQd54fTKYMoggaRU2jVSKjJCMMUx98IZ8E0hTzr7ZEqFHkpsVpsTsEP0CNIXkqshnRTGBgYYv0cyPMDTqcwMDDE+TmQ6prZTdlo1SwZAGc/CNdYBhpQkBKj3BEEnEJ/MIgy86hTRp0yBJ0CAOfkbKEhhTtrAAAAAElFTkSuQmCC',
                'link'    => false,
                'type'    => 'Image',
                'width'   => '46px',
            ],
            [
                'caption' => 'Delete rage of data',
                'type'    => 'PopupButton',
                'width'   => '200px',
                'enabled' => \$Variables['VariableId'] == 1 ? false : true,
                'popup'   => [
                    'caption'      => 'Select Date Range to Delete Data',
                    'closeCaption' => 'Cancel',
                    'items'        => [
                        [
                            'caption' => 'From',
                            'name'    => 'DeleteFrom',
                            'type'    => 'SelectDateTime',
                        ],
                        [
                            'caption' => 'To',
                            'name'    => 'DeleteTo',
                            'type'    => 'SelectDateTime',
                        ],
                    ],
                    'buttons' => [
                        [
                            'caption' => 'Delete',
                            'confirm' => 'Are you sure?',
                            'onClick' => [
                                '\$F = json_decode(\$DeleteFrom,true);',
                                '\$DeleteFrom = mktime(\$F[\'hour\'], \$F[\'minute\'], \$F[\'second\'], \$F[\'month\'], \$F[\'day\'], \$F[\'year\']);',
                                '\$T = json_decode(\$DeleteTo,true);',
                                '\$DeleteTo = mktime(\$T[\'hour\'], \$T[\'minute\'], \$T[\'second\'], \$T[\'month\'], \$T[\'day\'], \$T[\'year\']);',
                                '\$Nbr = ACMYSQL_DeleteVariableData(\$id, \$VariableId, \$DeleteFrom, \$DeleteTo);',
                                'if (\$Nbr === false){',
                                '  echo(\'" . $this->Translate('Error on delete data.') . "\');',
                                '} else {',
                                '  echo(\'MESSAGE:\'.sprintf(\'" . $this->Translate('%d entries successfully deleted.') . "\', \$Nbr));',
                                '}',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    [
        'type'  => 'RowLayout',
        'items' => [
            [
                'image'   => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAC4AAAAuCAIAAADY27xgAAAABnRSTlMA/wD/AP83WBt9AAABmUlEQVRYw+3WvW6CUBQH8GPTV5CBhc5lvatFhq5sbi1TTUx4BWMT4itoTOxEO9XtDq5oXVl1cCod7oAPYYfbEOSKHkiDDuc/8ZF7+XHuBzT2+z1cR24uDSAKUYhCFKIQ5fK5JCUWyVVQYpF0B6Os5rZaL8toHYsdAPyInbzYYvcAYDHT0DWkYxmtsxdLUGKRBHzxzhe5wsoEPJQHhq5ZzHx22g/MxDsAoIH5i4tFMpzM0och4zq2Cso5tvNxWsUzFFmJ4eSzFCIbi5lT35PPU+uBpXxF68eX18qINIau9Xsdi5nquKAoJxyGrt3pzRYz4W+eNmOx+xbJKtrIGX20CSirF0Upchi69uZ7RfNRptSYnqEcdcgiu46NHJSiZXKCcqN2oTpcx97Ox3iHpE99r1STPKU7GKmOqe/he8y9WEVKwMNcSSs7kANUSBlOZtlTuSXU4zigBDzM1bNOxwHlgy+yN1zHxnzY/stxQHly2tkJ3+916nSAuq+k22WpdSgT8HAVbUo16fc62M9hnaF/W6IQhShEIQpR4BeQ3Ad3wBtZVwAAAABJRU5ErkJggg==',
                'link'    => false,
                'type'    => 'Image',
                'width'   => '46px',
            ],
            [
                'caption' => 'Transfer Data',
                'type'    => 'PopupButton',
                'width'   => '200px',
                'enabled' => \$Variables['VariableId'] == 1 ? false : true,
                'popup'   => [
                    'caption'      => 'Select Variable to Transfer Data To',
                    'closeCaption' => 'Cancel',
                    'items'        => [
                        [
                            'caption' => 'Variable',
                            'name'    => 'TargetVariableId',
                            'type'    => 'SelectVariable',
                            'width'   => '200px',
                        ],
                    ],
                    'buttons' => [
                        [
                            'caption' => 'Transfer',
                            'confirm' => 'Are you sure?',
                            'onClick' => [
                                'if (!ACMYSQL_ChangeVariableID(\$id, \$VariableId, \$TargetVariableId)){',
                                '  echo(\'" . $this->Translate('Error on transfer data.') . "\');',
                                '} else {',
                                '  echo(\'MESSAGE:" . $this->Translate('Variable successfully changed.') . "\');',
                                '}',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'image'   => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAC4AAAAuCAIAAADY27xgAAAABnRSTlMA/wD/AP83WBt9AAABFElEQVRYw2P8//8/w+AATAPtgFGnDBmnsJCn7dCZq3hk7Uy06eSU1Lqpizbtx6OgJiOsNiOMVGMZ8WTmh89eHcTw/eEz1/C7AwLi/BxtTbTQBO1NtOWlxMhxyqJN+1PrppIRbLjA7KbsOD9HXLKDKNkOIqfgS7YKUmI1pKc+PMAeb85iHDx1EOHMvGjT/sNnrlFoTayfA8HChrBTHj57TUzuxQ/kpEQJOmUQJdtRp4w6ZdQpo04ZdcqoU0adMuqUUaeMOmXkOEVeSnSwOEUBd9+feGBPxIgLYafY4R2JIAbIS4kRM/hDVFrZPaeBGG/hCo9b26YRo5KEPvPDZ68ePntNYniIEh+ig6j7PqQy86hTBhQAACTCSXMYcos7AAAAAElFTkSuQmCC',
                'link'    => false,
                'type'    => 'Image',
                'width'   => '46px',
            ],
            [
                'caption' => 'Delete all data',
                'confirm' => 'Are you sure?',
                'type'    => 'Button',
                'width'   => '200px',
                'enabled' => \$Variables['VariableId'] == 1 ? false : true,
                'onClick' => [
                    'if (!ACMYSQL_DeleteVariable(\$id, \$VariableId)){',
                    '  echo(\'" . $this->Translate('Error on delete data.') . "\');',
                    '} else {',
                    '  //echo(\'" . $this->Translate('Logging disabled and all entries successfully deleted.') . "\');',
                    '   IPS_RequestAction(\$id, \'Reload\', \'\');',
                    '}',
                ],
            ],
        ],
    ],
];";
    }
}

/* @} */
