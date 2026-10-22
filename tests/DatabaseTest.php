<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';
include_once __DIR__ . '/../ArchiveControlMySQL/module.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ersatz für mysqli ohne Verbindung. Merkt sich alle Abfragen und liefert vorbereitete Ergebnisse.
 */
class FakeMysqli extends mysqli
{
    /** @var string[] Alle gesendeten Abfragen */
    public array $Queries = [];

    /** @var array<int, mysqli_result|bool> Ergebnisse in der Reihenfolge der Abfragen */
    public array $Results = [];

    public function __construct()
    {
        // Bewusst ohne parent::__construct(): keine echte Verbindung.
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $this->Queries[] = $query;
        return array_shift($this->Results) ?? true;
    }

    public function real_escape_string(string $string): string
    {
        return addslashes($string);
    }
}

/**
 * Testmodul: macht die protected Methoden des Database-Traits aufrufbar und setzt die Fake-Verbindung.
 */
class ArchiveControlMySQLTestModule extends ArchiveControlMySQL
{
    public function SetFakeDB(FakeMysqli $DB): void
    {
        // $DB und $isConnected sind private im Modul (Trait), daher im Klassenkontext von ArchiveControlMySQL setzen.
        $Set = function () use ($DB): void
        {
            $this->DB = $DB;
            $this->isConnected = true;
        };
        Closure::bind($Set, $this, ArchiveControlMySQL::class)();
    }

    public function CallQuotedDatabase(): string
    {
        return $this->GetQuotedDatabase();
    }

    public function CallVariableTables(): array
    {
        return $this->GetVariableTables();
    }

    public function CallTableExists(int $VarId): bool
    {
        return $this->TableExists($VarId);
    }

    public function CallCreateTable(int $VarId, int $VarTyp): bool|mysqli_result
    {
        return $this->CreateTable($VarId, $VarTyp);
    }

    public function CallDropTable(int $VariableID): bool
    {
        return $this->DeleteTable($VariableID);
    }
}

/**
 * Tests der SQL-Erzeugung im Database-Trait (ohne echte Datenbank).
 */
class DatabaseTest extends TestCase
{
    private int $InstanceID;
    private FakeMysqli $DB;

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        parent::setUp();
        $this->InstanceID = IPS\ObjectManager::registerObject(OBJECTTYPE_INSTANCE);
        IPS\InstanceManager::createInstance($this->InstanceID, [
            'ModuleID'   => '{FDCB334A-AFFF-4785-9596-D380252CEE4E}',
            'ModuleName' => 'Archive Control MySQL',
            'ModuleType' => MODULETYPE_DEVICE,
            'Class'      => ArchiveControlMySQLTestModule::class
        ]);
        $this->DB = new FakeMysqli();
        $this->Module()->SetFakeDB($this->DB);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function databaseNames(): array
    {
        return [
            'Standard'          => ['IPS', '`IPS`'],
            'Bindestrich'       => ['ips-test', '`ips-test`'],
            'Leerzeichen'       => ['my db', '`my db`'],
            'Backtick'          => ['a`b', '`a``b`'],
            'Injection-Versuch' => ['x`; DROP DATABASE y; --', '`x``; DROP DATABASE y; --`'],
        ];
    }

    #[DataProvider('databaseNames')]
    public function testQuotedDatabase(string $Name, string $Expected): void
    {
        $this->SetDatabase($Name);
        $this->assertSame($Expected, $this->Module()->CallQuotedDatabase());
    }

    public function testVariableTablesFiltersAndConvertsIds(): void
    {
        $Result = $this->createMock(mysqli_result::class);
        $Result->method('fetch_all')->willReturn([['VariableID' => '123'], ['VariableID' => '49591']]);
        $this->DB->Results = [$Result];
        $this->SetDatabase("o'brien");

        $this->assertSame([['VariableID' => 123], ['VariableID' => 49591]], $this->Module()->CallVariableTables());

        $Query = $this->DB->Queries[0];
        $this->assertStringContainsString("table_schema = 'o\\'brien'", $Query, 'Datenbankname muss als Literal maskiert werden');
        $this->assertStringContainsString("REGEXP '^var[0-9]+\$'", $Query, 'Nur Tabellen var<ID>');
        $this->assertStringContainsString('substr(TABLE_NAME,4)', $Query, 'ID ist alles hinter "var"');
        $this->assertStringNotContainsString('right(', $Query);
    }

    public function testVariableTablesWithFailedQuery(): void
    {
        $this->DB->Results = [false];
        $this->assertSame([], $this->Module()->CallVariableTables());
    }

    public function testTableExists(): void
    {
        $this->SetDatabase('ips-test');
        $this->DB->Results = [false];
        $this->assertFalse($this->Module()->CallTableExists(12345), 'Fehlgeschlagene Abfrage darf nicht abstürzen');
        $this->assertSame("SHOW TABLES IN `ips-test` LIKE 'var12345';", $this->DB->Queries[0]);
    }

    public function testDropTableUsesQuotedDatabase(): void
    {
        $this->SetDatabase('ips-test');
        $this->Module()->CallDropTable(4711);
        $this->assertSame('DROP TABLE `ips-test`.var4711;', $this->DB->Queries[0]);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function variableTypes(): array
    {
        return [
            'Boolean' => [VARIABLETYPE_BOOLEAN, 'value BIT(1), '],
            'Integer' => [VARIABLETYPE_INTEGER, 'value INT SIGNED, '],
            'Float'   => [VARIABLETYPE_FLOAT, 'value REAL, '],
            'String'  => [VARIABLETYPE_STRING, 'value MEDIUMBLOB, '],
        ];
    }

    #[DataProvider('variableTypes')]
    public function testCreateTableColumnType(int $Type, string $Column): void
    {
        $this->Module()->CallCreateTable(42, $Type);
        $this->assertSame(
            'CREATE TABLE var42 (id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' . $Column . 'timestamp DATETIME);',
            $this->DB->Queries[0]
        );
    }

    public function testCreateTableUnknownTypeSendsNothing(): void
    {
        $this->assertFalse($this->Module()->CallCreateTable(42, 99));
        $this->assertSame([], $this->DB->Queries);
    }

    private function Module(): ArchiveControlMySQLTestModule
    {
        return IPS\InstanceManager::getInstanceInterface($this->InstanceID);
    }

    private function SetDatabase(string $Name): void
    {
        IPS_SetProperty($this->InstanceID, 'Database', $Name);
        IPS_ApplyChanges($this->InstanceID);
        $this->Module()->SetFakeDB($this->DB);
    }
}
