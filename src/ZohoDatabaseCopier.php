<?php

namespace Wabel\Zoho\CRM\Copy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wabel\Zoho\CRM\AbstractZohoDao;
use function Stringy\create as s;

/**
 * This class is in charge of synchronizing one table of your database with Zoho records.
 */
class ZohoDatabaseCopier
{
    /**
     * @var Connection
     */
    private $connection;

    private $prefix;

    /**
     * @var ZohoChangeListener[]
     */
    private $listeners;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * ZohoDatabaseCopier constructor.
     *
     * @param Connection $connection
     * @param string $prefix Prefix for the table name in DB
     * @param ZohoChangeListener[] $listeners The list of listeners called when a record is inserted or updated.
     */
    public function __construct(Connection $connection, $prefix = 'zoho_', array $listeners = [], LoggerInterface $logger = null)
    {
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->listeners = $listeners;
        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }


    /**
     * @param AbstractZohoDao $dao
     * @param bool            $incrementalSync Whether we synchronize only the modified files or everything.
     */
    public function copy(AbstractZohoDao $dao, $incrementalSync = true)
    {
        $this->synchronizeDbModel($dao);
        $this->copyData($dao, $incrementalSync);
    }

    /**
     * Synchronizes the DB model with Zoho.
     *
     * @param AbstractZohoDao $dao
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    private function synchronizeDbModel(AbstractZohoDao $dao)
    {
        $tableName = $this->getTableName($dao);
        $this->logger->info("Synchronizing DB Model for ".$tableName);

        $schema = new Schema();
        $table = $schema->createTable($tableName);

        $flatFields = $this->getFlatFields($dao->getFields());

        $table->addColumn('id', 'text');
        $table->setPrimaryKey(['id']);

        foreach ($flatFields as $field) {
            $columnName = $field['name'];

            $length = null;
            $index = false;

            // Note: full list of types available here: https://www.zoho.com/crm/help/customization/custom-fields.html
            switch ($field['type']) {
                case 'Lookup ID':
                case 'Lookup':
                    $type = 'text';
                    $index = true;
                    break;
                case 'OwnerLookup':
                    $type = 'text';
                    $index = true;
                    break;
                case 'Formula':
                    // Note: a Formula can return any type, but we have no way to know which type it returns...
                    $type = 'text';
                    break;
                case 'DateTime':
                    $type = 'datetime';
                    break;
                case 'Date':
                    $type = 'date';
                    break;
                case 'Boolean':
                    $type = 'boolean';
                    break;
                case 'TextArea':
                    $type = 'text';
                    break;
                case 'BigInt':
                    $type = 'bigint';
                    break;
                case 'Phone':
                case 'Auto Number':
                case 'Text':
                case 'URL':
                case 'Email':
                case 'Website':
                case 'Pick List':
                case 'Multiselect Pick List':
                    $type = 'text';
                    break;
                case 'Double':
                case 'Percent':
                    $type = 'float';
                    break;
                case 'Integer':
                    $type = 'integer';
                    break;
                case 'Currency':
                case 'Decimal':
                    $type = 'decimal';
                    break;
                default:
                    throw new \RuntimeException('Unknown type "'.$field['type'].'"');
            }

            $options = [];

            if ($length) {
                $options['length'] = $length;
            }

            //$options['notnull'] = $field['req'];
            $options['notnull'] = false;

            $table->addColumn($columnName, $type, $options);

            if ($index) {
                $table->addIndex([$columnName]);
            }
        }

        $dbSchema = $this->connection->getSchemaManager()->createSchema();
        if ($this->connection->getSchemaManager()->tablesExist($tableName)) {
            $dbTable = $dbSchema->getTable($tableName);

            $comparator = new \Doctrine\DBAL\Schema\Comparator();
            $tableDiff = $comparator->diffTable($dbTable, $table);

            if ($tableDiff !== false) {
                $this->logger->notice("Changes detected in table structure for ".$tableName.". Applying patch.");
                $diff = new SchemaDiff();
                $diff->fromSchema = $dbSchema;
                $diff->changedTables[$tableName] = $tableDiff;
                $statements = $diff->toSaveSql($this->connection->getDatabasePlatform());
                foreach ($statements as $sql) {
                    $this->connection->exec($sql);
                }
            } else {
                $this->logger->info("No changes detected in table structure for ".$tableName);
            }
        } else {
            $this->logger->notice("Creating new table '$tableName'.");
            $diff = new SchemaDiff();
            $diff->fromSchema = $dbSchema;
            $diff->newTables[$tableName] = $table;
            $statements = $diff->toSaveSql($this->connection->getDatabasePlatform());
            foreach ($statements as $sql) {
                $this->connection->exec($sql);
            }
        }
    }

    /**
     * @param AbstractZohoDao $dao
     * @param bool            $incrementalSync Whether we synchronize only the modified files or everything.
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Wabel\Zoho\CRM\Exception\ZohoCRMResponseException
     */
    private function copyData(AbstractZohoDao $dao, $incrementalSync = true)
    {
        $tableName = $this->getTableName($dao);

        $lastActivityTime = null;
        if ($incrementalSync) {
            // Let's get the last modification date:
            $lastActivityTime = $this->connection->fetchColumn('SELECT MAX(lastActivityTime) FROM '.$tableName);
            if ($lastActivityTime !== null) {
                $lastActivityTime = new \DateTime($lastActivityTime);
                $this->logger->info("Last activity time: ".$lastActivityTime->format('c'));
                // Let's add one second to the last activity time (otherwise, we are fetching again the last record in DB).
                $lastActivityTime->add(new \DateInterval("PT1S"));
            }
            $this->logger->info("Copying incremental data for '$tableName'");
        } else {
            $this->logger->notice("Copying FULL data for '$tableName'");
        }

        $table = $this->connection->getSchemaManager()->createSchema()->getTable($tableName);

        $flatFields = $this->getFlatFields($dao->getFields());
        $fieldsByName = [];
        foreach ($flatFields as $field) {
            $fieldsByName[$field['name']] = $field;
        }

        $fieldsByName = array_change_key_case($fieldsByName);

        $select = $this->connection->prepare('SELECT * FROM '.$tableName.' WHERE id = :id');

        $this->connection->beginTransaction();

        $limit = 1000;
        $page = 1;
        $offset = ($page - 1) * $limit;
        $i = 0;
        while($records = $dao->getPaginatedRecords(null, null, $lastActivityTime, null, $limit, $offset))
        {
            foreach ($records as $record) {
                $data = [];
                $types = [];
                foreach ($table->getColumns() as $column) {
                    if ($column->getName() === 'id') {
                        continue;
                    } else {
                        $field = $fieldsByName[strtolower($column->getName())];
                        $getterName = $field['getter'];
                        $formattedData = $record->$getterName();
                        if(is_array($formattedData))
                        {
                            $formattedData = json_encode($formattedData);
                        }

                        $data[$column->getName()] = $formattedData;
                        $types[$column->getName()] = $column->getType()->getName();
                    }
                }

                $select->execute(['id' => $record->getZohoId()]);
                $result = $select->fetch(\PDO::FETCH_ASSOC);
                if ($result === false) {
                    $this->logger->debug("Inserting record with ID '".$record->getZohoId()."'.");

                    $data['id'] = $record->getZohoId();
                    $types['id'] = 'text';

                    $this->connection->insert($tableName, $data, $types);

                    foreach ($this->listeners as $listener) {
                        $listener->onInsert($data, $dao);
                    }
                } else {
                    $this->logger->debug("Updating record with ID '".$record->getZohoId()."'.");
                    $identifier = ['id' => $record->getZohoId()];
                    $types['id'] = 'text';

                    $this->connection->update($tableName, $data, $identifier, $types);

                    // Let's add the id for the update trigger
                    $data['id'] = $record->getZohoId();
                    foreach ($this->listeners as $listener) {
                        $listener->onUpdate($data, $result, $dao);
                    }
                }

                $i++;

                $this->logger->info("$tableName: Processed record $i");
                echo "$tableName: Processed record $i \n";
            }

            $page++;
            $offset = ($page - 1) * $limit;
        }

        $this->connection->commit();
    }

    private function getFlatFields(array $fields)
    {
        $flatFields = [];
        foreach ($fields as $cat) {
            $flatFields = array_merge($flatFields, $cat);
        }

        return $flatFields;
    }

    /**
     * Computes the name of the table based on the DAO plural module name.
     *
     * @param AbstractZohoDao $dao
     *
     * @return string
     */
    public function getTableName(AbstractZohoDao $dao)
    {
        $tableName = $this->prefix.$dao->getPluralModuleName();
        $tableName = s($tableName)->upperCamelize()->underscored();

        return (string) $tableName;
    }
}
