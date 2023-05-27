<?php

declare(strict_types=1);

namespace MySqlArchive;

use mysqli_result;

eval('namespace MySqlArchive {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('namespace MySqlArchive {?>' . file_get_contents(__DIR__ . '/../libs/helper/DebugHelper.php') . '}');

/**
 * @addtogroup mysqlarchiv
 * @{
 *
 * @package       MySQLArchiv
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2019 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       3.40
 */
trait Database
{
    private ?\mysqli $DB = null;
    private bool $isConnected = false;

    protected function Login(): bool
    {
        /** @var array $_IPS */
        if ($this->ReadPropertyString('Host') == '') {
            return false;
        }
        if (!$this->isConnected) {
            $this->SendDebug('Connect [' . $_IPS['THREAD'] . ']', 'Start ' . sprintf('%.3f', ((microtime(true) - $this->Runtime) * 1000)) . ' ms', 0);
            $this->DB = @new \mysqli('p:' . $this->ReadPropertyString('Host'), $this->ReadPropertyString('Username'), $this->ReadPropertyString('Password'));
            if ($this->DB->connect_errno == 0) {
                $this->isConnected = true;
                $this->SendDebug('Login [' . $_IPS['THREAD'] . ']', sprintf('%.3f', ((microtime(true) - $this->Runtime) * 1000)) . ' ms', 0);
                return true;
            }
            return false;
        }
        return true;
    }

    protected function CreateDB(): bool|\mysqli_result
    {
        if ($this->isConnected) {
            return $this->DB->query('CREATE DATABASE ' . $this->ReadPropertyString('Database'));
        }
        return false;
    }

    protected function SelectDB(): bool
    {
        if ($this->isConnected) {
            return $this->DB->select_db($this->ReadPropertyString('Database'));
        }
        return false;
    }

    protected function Logout(): bool
    {
        if ($this->isConnected) {
            return $this->DB->close();
        }
        return false;
    }

    protected function TableExists(int $VarId): bool
    {
        if (!$this->isConnected) {
            return false;
        }
        $query = 'SHOW TABLES IN ' . $this->ReadPropertyString('Database') . " LIKE  'var" . (string) $VarId . "';";
        $result = $this->DB->query($query);
        return !($result->num_rows == 0);
    }

    protected function CreateTable(int $VarId, int $VarTyp): bool|mysqli_result
    {
        if (!$this->isConnected) {
            return false;
        }
        switch ($VarTyp) {
            case VARIABLETYPE_INTEGER:
                $Typ = 'value INT SIGNED, ';
                break;
            case VARIABLETYPE_FLOAT:
                $Typ = 'value REAL, ';
                break;
            case VARIABLETYPE_BOOLEAN:
                $Typ = 'value BIT(1), ';
                break;
            case VARIABLETYPE_STRING:
                $Typ = 'value MEDIUMBLOB, ';
                break;
        }
        $query = 'CREATE TABLE var' . (string) $VarId . ' (id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' . $Typ . 'timestamp DATETIME);';
        $result = $this->DB->query($query);
        $this->SendDebug('CreateTable', $result, 0);
        return $result;
    }

    protected function RenameTable(int $OldVariableID, int $NewVariableID): bool|\mysqli_result
    {
        if (!$this->isConnected) {
            return false;
        }
        $query = 'RENAME TABLE ' . $this->ReadPropertyString('Database') . '.var' . (string) $OldVariableID . ' TO ' . $this->ReadPropertyString('Database') . '.var' . (string) $NewVariableID . ';';
        $result = $this->DB->query($query);
        $this->SendDebug('RenameTable', $result, 0);
        return $result;
    }

    protected function DeleteData(int $VariableID, int $Startzeit, int $Endzeit): false|int
    {
        if (!$this->isConnected) {
            return false;
        }
        $query = 'DELETE FROM var' . (string) $VariableID . ' WHERE ((timestamp >= from_unixtime(' . (string) $Startzeit . ')) and (timestamp <= from_unixtime(' . (string) $Endzeit . ')));';
        $result = $this->DB->query($query);
        if ($result) {
            $result = $this->DB->affected_rows;
        }
        return $result;
    }

    protected function GetLoggedData(int $VariableID, int $Startzeit, int $Endzeit, int $Limit): false|array
    {
        if (!$this->isConnected) {
            return false;
        }

        $query = 'SELECT  unix_timestamp(timestamp) AS \'TimeStamp\', value AS \'Value\' ' .
                'FROM  var' . (string) $VariableID . ' ' .
                'WHERE  ((timestamp >= from_unixtime(' . (string) $Startzeit . ')) ' .
                'and (timestamp <= from_unixtime(' . (string) $Endzeit . '))) ' .
                'ORDER BY timestamp DESC ' .
                'LIMIT ' . (string) $Limit;
        $result = $this->DB->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    protected function GetLoggedDataTyp(int $VariableID): false|int
    {
        $query = 'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS ' .
                "WHERE ((TABLE_NAME = 'var" . (string) $VariableID . "') AND (COLUMN_NAME = 'value'))";
        $result = $this->DB->query($query);
        if ($result->num_rows == 0) {
            return false;
        }
        switch (strtolower($result->fetch_row()[0])) {
            case 'double':
            case 'real':
                return VARIABLETYPE_FLOAT;
            case 'int':
                return VARIABLETYPE_INTEGER;
            case 'bit':
                return VARIABLETYPE_BOOLEAN;
            default:
                return VARIABLETYPE_STRING;
        }
    }

    protected function GetAggregatedData(int $VariableID, int $Typ, int $Startzeit, int $Endzeit, int $Limit): array
    {
        switch ($Typ) {
            case 0:
                $Time = 10000;
                $Half = 3000;
                break;
            case 1:
                // YYMMDDhhmmss
                $Time = 1000000;
                $Half = 120000;
                break;
            case 2:
                // YYMMDDhhmmss
                $Time = 7000000;
                $Half = 350000;
                break;
            case 3:
                //    YYMMDDhhmmss
                $Time = 100000000;
                $Half = 15000000;
                break;
            case 4:
                //     YYMMDDhhmmss
                $Time = 10000000000;
                $Half = 600000000;
                break;
            case 5: //5 min
                $Time = 500;
                $Half = 230;
                break;
            case 6: //1 min
                $Time = 100;
                $Half = 30;
                break;
        }
        $query = "SELECT MIN(value) AS 'Min', MAX(value) AS 'Max', AVG(value) AS 'Avg', " .
                'UNIX_TIMESTAMP(convert((min(timestamp) div ' . $Time . ')*' . $Time . ' + ' . $Half . ', datetime)) ' .
                "as 'TimeStamp' FROM var" . (string) $VariableID . ' ' .
                'WHERE timestamp BETWEEN from_unixtime(' . (string) $Startzeit . ') ' .
                'AND from_unixtime(' . (string) $Endzeit . ') GROUP BY timestamp div ' . $Time . ' ' .
                "ORDER BY 'TimeStamp' DESC " .
                'LIMIT ' . (string) $Limit;
        $result = $this->DB->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    protected function GetVariableTables(): array
    {
        if (!$this->isConnected) {
            return [];
        }
        $query = "SELECT right(TABLE_NAME,5) as 'VariableID' FROM information_schema.TABLES WHERE table_schema = '" . $this->ReadPropertyString('Database') . "' ORDER BY 'VariableID' ASC";
        $result = $this->DB->query($query);
        if ($result === false) {
            return [];
        }
        $Result = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($Result as &$Item) {
            $Item['VariableID'] = (int) $Item['VariableID'];
        }
        return $Result;
    }

    protected function GetSummary(int $VariableId): false|array
    {
        if (!$this->isConnected) {
            return false;
        }

        $query = "SELECT unix_timestamp(timestamp) AS 'TimeStamp' " .
                'FROM  var' . (string) $VariableId . ' ' .
                'ORDER BY timestamp ASC ' .
                'LIMIT 1';
        $mysql_result = $this->DB->query($query);
        if ($mysql_result->num_rows == 0) {
            return false;
        }
        $Result['FirstTimestamp'] = (int) $mysql_result->fetch_row()[0];
        $query = "SELECT unix_timestamp(timestamp) AS 'TimeStamp' " .
                'FROM  var' . (string) $VariableId . ' ' .
                'ORDER BY timestamp DESC ' .
                'LIMIT 1';
        $mysql_result = $this->DB->query($query);
        $Result['LastTimestamp'] = (int) $mysql_result->fetch_row()[0];

        $query = "SELECT count(*) AS 'Count' " .
                'FROM  var' . (string) $VariableId . ' ';
        $mysql_result = $this->DB->query($query);
        $Result['Count'] = (int) $mysql_result->fetch_row()[0];

        $query = "SELECT data_length AS 'Size' " .
                'FROM information_schema.TABLES ' .
                "WHERE table_schema = '" . $this->ReadPropertyString('Database') . "' " .
                "AND table_name = 'var" . (string) $VariableId . "' ";
        $mysql_result = $this->DB->query($query);
        $Result['Size'] = (int) $mysql_result->fetch_row()[0];
        return $Result;
    }

    protected function WriteValue($Variable, $NewValue, $HasChanged, $Timestamp): bool|\mysqli_result
    {
        if (!$HasChanged) {
            $query = 'SELECT id,value FROM var' . $Variable . ' ORDER BY timestamp DESC LIMIT 2';
            $result = $this->DB->query($query);
            if ($result === false) {
                echo $this->DB->error;
                return false;
            }

            if ($result->num_rows === 2) {
                $ids = $result->fetch_all(MYSQLI_ASSOC);
                if ($ids[0]['value'] === $ids[1]['value']) {
                    $query = 'UPDATE var' . $Variable . ' SET timestamp=from_unixtime(' . $Timestamp . ') WHERE id=' . $ids[0]['id'];
                    $result = $this->DB->query($query);
                    return $result;
                }
            }
        }
        $query = 'INSERT INTO var' . $Variable . ' (value,timestamp) VALUES(' . $NewValue . ',from_unixtime(' . $Timestamp . '));';
        $result = $this->DB->query($query);
        if ($result === false) {
            echo $this->DB->error;
            return false;
        }
        return true;
    }
}

trait VariableWatch
{
    /**
     * Unregistriert eine Überwachung einer Variable.
     *
     * @param int $VarId IPS-ID der Variable.
     */
    protected function UnregisterVariableWatch($VarId)
    {
        if ($VarId == 0) {
            return;
        }

        $this->SendDebug('UnregisterVM', $VarId, 0);
        $this->UnregisterMessage($VarId, VM_DELETE);
        $this->UnregisterMessage($VarId, VM_UPDATE);
        $this->UnregisterReference($VarId);
    }

    /**
     * Registriert eine Überwachung einer Variable.
     *
     * @param int $VarId IPS-ID der Variable.
     */
    protected function RegisterVariableWatch(int $VarId)
    {
        if ($VarId == 0) {
            return;
        }
        $this->SendDebug('RegisterVM', $VarId, 0);
        $this->RegisterReference($VarId);
        $this->RegisterMessage($VarId, VM_DELETE);
        $this->RegisterMessage($VarId, VM_UPDATE);
    }
}

/* @} */
