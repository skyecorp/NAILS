<?php

namespace Drupal\common\Storage;

use Dkan\Datastore\Storage\Database\SqlStorageTrait;
use Drupal\Core\Database\Connection;
use Drupal\indexer\IndexManager;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\common\EventDispatcherTrait;

/**
 * Base class for database storage methods.
 */
abstract class AbstractDatabaseTable implements DatabaseTableInterface {
  use SqlStorageTrait;
  use EventDispatcherTrait;

  const EVENT_TABLE_CREATE = 'dkan_common_table_create';

  /**
   * Drupal DB connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Optional index manager service.
   *
   * @var null|\Drupal\indexer\IndexManager
   */
  protected $indexManager;

  /**
   * Get the full name of datastore db table.
   *
   * @return string
   *   Table name.
   */
  abstract protected function getTableName();

  /**
   * Prepare data.
   *
   * Transform the string data given into what should be use by the insert
   * query.
   */
  abstract protected function prepareData(string $data, string $id = NULL): array;

  /**
   * Get the primary key used in the table.
   */
  public function primaryKey() {
    return 'id';
  }

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   Drupal database connection object.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;

    if ($this->tableExist($this->getTableName())) {
      $this->setSchemaFromTable();
    }
  }

  /**
   * Get a database connection instance.
   *
   * @return \Drupal\Core\Database\Connection
   *   Drupal database connection object.
   */
  protected function getConnection(): Connection {
    return $this->connection;
  }

  /**
   * Set an optional index manager service.
   *
   * @param \Drupal\indexer\IndexManager $indexManager
   *   Index manager.
   */
  public function setIndexManager(IndexManager $indexManager) {
    $this->indexManager = $indexManager;
  }

  /**
   * {@inheritdoc}
   */
  public function retrieve(string $id) {
    $this->setTable();

    $select = $this->getConnection()->select($this->getTableName(), 't')
      ->fields('t', array_keys($this->getSchema()['fields']))
      ->condition($this->primaryKey(), $id);

    $statement = $select->execute();

    // The docs do not mention it, but fetch can return false.
    $return = (isset($statement)) ? $statement->fetch() : NULL;

    return ($return === FALSE) ? NULL : $return;
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveAll(): array {
    $this->setTable();
    $tableName = $this->getTableName();

    $result = $this->getConnection()->select($tableName, 't')
      ->fields('t', [$this->primaryKey()])
      ->execute()
      ->fetchAll();

    if ($result === FALSE) {
      return [];
    }

    $result = array_map(function ($item) {
      return $item->{$this->primaryKey()};
    }, $result);

    return $result;
  }

  /**
   * Store data.
   */
  public function store($data, string $id = NULL): string {
    $this->setTable();

    $existing = (isset($id)) ? $this->retrieve($id) : NULL;

    $data = $this->prepareData($data, $id);

    $returned_id = NULL;

    if ($existing === NULL) {
      $fields = $this->getNonSerialFields();

      if (count($fields) != count($data)) {
        throw new \Exception(
          "The number of fields and data given do not match: fields - "
            . json_encode($fields) . " data - " . json_encode($data)
        );
      }

      $q = $this->getConnection()->insert($this->getTableName());
      $q->fields($fields);
      $q->values($data);
      $returned_id = $q->execute();
    }
    else {
      $q = $this->getConnection()->update($this->getTableName());
      $q->fields($data)
        ->condition($this->primaryKey(), $id)
        ->execute();
    }

    return ($returned_id) ? "$returned_id" : "{$id}";
  }

  /**
   * Prepare to store possibly multiple values.
   *
   * @param array $data
   *   Array of values to be inserted into the database.
   *
   * @return string|null
   *   Last record id inserted into the database.
   */
  public function storeMultiple(array $data) {
    $this->setTable();

    $fields = $this->getNonSerialFields();

    $q = $this->getConnection()->insert($this->getTableName());
    $q->fields($fields);
    foreach ($data as $datum) {
      $datum = $this->prepareData($datum);
      if (count($fields) != count($datum)) {
        throw new \Exception("The number of fields and data given do not match: fields - " .
          json_encode($fields) . " data - " . json_encode($datum));
      }
      $q->values($datum);
    }
    return $q->execute();
  }

  /**
   * Private.
   */
  protected function getNonSerialFields() {
    $fields = [];
    foreach ($this->schema['fields'] as $field => $info) {
      if ($info['type'] != 'serial') {
        $fields[] = $field;
      }
    }
    return $fields;
  }

  /**
   * Inherited.
   *
   * @inheritdoc
   */
  public function remove(string $id) {
    $tableName = $this->getTableName();
    $this->getConnection()->delete($tableName)
      ->condition($this->primaryKey(), $id)
      ->execute();
  }

  /**
   * Count rows in table.
   */
  public function count(): int {
    $this->setTable();
    $query = $this->getConnection()->select($this->getTableName());
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Run a query on the database table.
   *
   * @param \Drupal\common\Storage\Query $query
   *   Query object.
   * @param string $alias
   *   (Optional) alias for primary table.
   * @param bool $fetch
   *   Fetch the rows if true, just return the result statement if not.
   *
   * @return array|\Drupal\Core\Database\StatementInterface
   *   Array of results if $fetch is true, otherwise result of
   *   Select::execute() (prepared Statement object or null).
   */
  public function query(Query $query, string $alias = 't', $fetch = TRUE) {
    $this->setTable();
    $query->collection = $this->getTableName();
    $selectFactory = new SelectFactory($this->getConnection(), $alias);
    $db_query = $selectFactory->create($query);

    try {
      $result = $db_query->execute();
    }
    catch (DatabaseExceptionWrapper $e) {
      throw new \Exception($this->sanitizedErrorMessage($e->getMessage()));
    }

    return $fetch ? $result->fetchAll() : $result;
  }

  /**
   * Create a minimal error message that does not leak database information.
   */
  private function sanitizedErrorMessage(string $unsanitizedMessage) {
    // Insert portions of exception messages you want caught here.
    $messages = [
      // Portion of the message => User friendly message.
      'Column not found' => 'Column not found',
      'Mixing of GROUP columns' => 'You may not mix simple properties and aggregation expressions in a single query. If one of your properties includes an expression with a sum, count, avg, min or max operator, remove other properties from your query and try again',
    ];
    foreach ($messages as $portion => $message) {
      if (strpos($unsanitizedMessage, $portion) !== FALSE) {
        return $message . ".";
      }
    }
    return "Database internal error.";
  }

  /**
   * Private.
   */
  private function setTable() {
    if (!$this->tableExist($this->getTableName())) {
      if ($this->schema) {
        $this->tableCreate($this->getTableName(), $this->schema);
      }
      else {
        throw new \Exception("Could not instantiate the table due to a lack of schema.");
      }
    }
  }

  /**
   * Destroy.
   *
   * Drop the database table.
   */
  public function destruct() {
    if ($this->tableExist($this->getTableName())) {
      $this->getConnection()->schema()->dropTable($this->getTableName());
    }
  }

  /**
   * Check for existence of a table name.
   */
  protected function tableExist(string $tableName): bool {
    return $this->getConnection()->schema()->tableExists($tableName);
  }

  /**
   * Create a table given a name and schema.
   */
  private function tableCreate($table_name, $schema) {
    // Opportunity to further alter the schema before table creation.
    $schema = $this->dispatchEvent(self::EVENT_TABLE_CREATE, $schema);
    // Add indexes if we have an index manager.
    if (method_exists($this->indexManager, 'modifySchema')) {
      $schema = $this->indexManager->modifySchema($table_name, $schema);
    }
    $this->getConnection()->schema()->createTable($table_name, $schema);
  }

  /**
   * Set the schema using the existing database table.
   */
  protected function setSchemaFromTable() {
    $fields_info = $this->getConnection()->query("DESCRIBE `{$this->getTableName()}`")->fetchAll();
    if (empty($fields_info)) {
      return;
    }

    foreach ($fields_info as $info) {
      $fields[] = $info->Field;
    }
    $schema = $this->getTableSchema($fields);
    if (method_exists($this->getConnection()->schema(), 'getComment')) {
      foreach ($schema['fields'] as $fieldName => $info) {
        $newInfo = $info;
        $newInfo['description'] = $this->getConnection()->schema()->getComment($this->getTableName(), $fieldName);
        $schema['fields'][$fieldName] = $newInfo;
      }
    }
    $this->setSchema($schema);
  }

  /**
   * Get table schema.
   */
  private function getTableSchema($fields) {
    $schema = [];
    $header = $fields;
    foreach ($header as $field) {
      $schema['fields'][$field] = [
        'type' => "text",
      ];
    }
    return $schema;
  }

}
