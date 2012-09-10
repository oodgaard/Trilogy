<?php

namespace Trilogy\Driver;
use PDO;
use Trilogy\Statement;

/**
 * Common driver behavior.
 * 
 * @category Iterators
 * @package  Trilogy
 * @author   Trey Shugart <treshugart@gmail.com>
 * @license  MIT http://opensource.org/licenses/mit-license.php
 */
abstract class SqlDriverAbstract implements DriverInterface
{
    /**
     * The PDO instance.
     * 
     * @var PDO
     */
    private $pdo;
    
    /**
     * List of concatenators for conditions.
     * 
     * @var array
     */
    private $concatenators = [
        'and' => 'AND',
        'or'  => 'OR'
    ];
    
    /**
     * All DBs handle limiting differently.
     * 
     * @param int $limit  The limit.
     * @param int $offset The offset.
     * 
     * @return string
     */
    protected abstract function compileLimit($limit, $offset);

    /**
     * Creates a DSN from the configuration.
     * 
     * @return string
     */
    public function __construct(array $config)
    {
        $dsn = $config['driver']
            . ':dbname=' . $config['database']
            . ';host=' . $config['host']
            . ';port=' . $config['port'];
        
        $this->pdo = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $config['options']
        );
    }
    
    /**
     * Returns the raw PDO connection.
     * 
     * @return PDO
     */
    public function raw()
    {
        return $this->pdo;
    }
    
    /**
     * Executes the statement using PDO.
     * 
     * @param mixed $statement The statement to prepare, execute and return the result of.
     * @param array $params    The parameters to execute the statement with.
     * 
     * @return mixed
     */
    public function execute($statement, array $params = [])
    {
        // Prepare statement.
        $statement = $this->pdo->prepare((string) $statement);
        
        // Return an associative array if it is a SELECT statement.
        if (strpos($statement->queryString, 'SELECT ') === 0) {
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // If not a SELECT statement, return execution result.
        return $statement->execute($params);;
    }
    
    /**
     * Quotes the specified identifier.
     * 
     * @param string $identifier The identifier to quote.
     * 
     * @return string
     */
    public function quote($identifier)
    {
        $identifiers = explode('.', $identifier);
        return '"' . implode('"."', $identifiers) . '"';
    }
    
    /**
     * Quotes an array of identifiers.
     * 
     * @param array $identifiers The identifiers to quote.
     * 
     * @return array
     */
    public function quoteAll(array $identifiers)
    {
        $modified = [];
        foreach ($identifiers as $identifier) {
            $modified[] = $this->quote($identifier);
        }
        return $modified;
    }
    
    /**
     * Compiles a find statement.
     * 
     * @param Statement\Find $find The find statement.
     * 
     * @return string
     */
    public function compileFind(Statement\Find $find)
    {
        $sqls = [];
        
        $sqls[] = $this->compileSelect($find->getFields());
        $sqls[] = $this->compileFrom($find->getTables());
        
        if ($part = $find->getWheres()) {
            $sqls[] = $this->compileWhere($part);
        }
        
        if ($part = $find->getJoins()) {
            $sqls[] = $this->compileJoin($part);
        }
        
        if ($sql = $this->compileOrderBy($find->getSortFields(), $find->getSortDirection())) {
            $sqls[] = $sql;
        }
        
        if ($sql = $this->compileLimit($find->getLimit(), $find->getOffset())) {
            $sqls[] = $sql;
        }
        
        return implode(' ', $sqls);
    }
    
    /**
     * Compiles a save statement.
     * 
     * @param Statement\Save $save The save statement.
     * 
     * @return string
     */
    public function compileSave(Statement\Save $save)
    {
        if ($save->getWheres()) {
            return $this->compileUpdate($save);
        }
        return $this->compileInsert($save);
    }
    
    /**
     * Compiles a remove statement.
     * 
     * @param Statement\Remove $remove The remove statement.
     * 
     * @return string
     */
    public function compileRemove(Statement\Remove $remove)
    {
        return sprintf(
            'DELETE FROM %s %s',
            $this->quote($remove->getTables()[0]),
            $this->compileWhere($remove->getWheres())
        );
    }
    
    /**
     * Compiles an insert statement.
     * 
     * @param Statement\Save $save The save statement.
     * 
     * @return string
     */
    protected function compileInsert(Statement\Save $save)
    {
        $table  = $save->getTables()[0];
        $table  = $this->quote($table);
        $fields = array_keys($save->getData());
        $fields = $this->quoteAll($fields);
        $values = str_repeat('?', count($fields));
        $values = str_split($values);
        
        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $fields),
            implode(', ', $values)
        );
    }
    
    /**
     * Compiles an update statement.
     * 
     * @param Statement\Save $save The save statement.
     * 
     * @return string
     */
    protected function compileUpdate(Statement\Save $save)
    {
        $table = $save->getTables()[0];
        $table = $this->quote($table);
        
        $fields = array_keys($save->getData());
        $fields = $this->quoteAll($fields);
        
        foreach ($fields as &$field) {
            $field = 'SET ' . $field . ' = ?';
        }
        
        $values = str_repeat('?', count($fields));
        $values = str_split($values);
        
        return sprintf(
            'UPDATE %s %s %s',
            $table,
            implode(', ', $fields),
            $this->compileWhere($save->getWheres())
        );
    }
    
    /**
     * Compiles the SELECT part of a find statement.
     * 
     * @param array $fields The fields to select.
     * 
     * @return string
     */
    protected function compileSelect(array $fields)
    {
        if ($fields) {
            $fields = $this->quoteAll($fields);
            $fields = implode(', ', $fields);
        } else {
            $fields = '*';
        }
        
        return 'SELECT ' . $fields;
    }
    
    /**
     * Compiles the FROM part of a find statement.
     * 
     * @param array $tables The tables to select from.
     * 
     * @return string
     */
    protected function compileFrom(array $tables)
    {
        $tables = $this->quoteAll($tables);
        $tables = implode(', ', $tables);
        return 'FROM ' . $tables;
    }
    
    /**
     * Compiles the WHERE part of a find statement.
     * 
     * @param array $wheres The where parts to compile.
     * 
     * @return string
     */
    protected function compileWhere(array $wheres)
    {
        return 'WHERE ' . $this->compileExpression($wheres);
    }
    
    /**
     * Compiles the JOIN part of a query.
     * 
     * @param array $joins The joins to compile.
     * 
     * @return string
     */
    protected function compileJoin(array $joins)
    {
        $sql = '';
        
        foreach ($joins as $join) {
            $sql .= sprintf(
                '%s JOIN %s ON %s',
                strtoupper($join['type']),
                $this->quote($join['table']),
                $this->compileExpression($join['wheres'])
            );
        }
        
        return $sql;
    }
    
    /**
     * Compiles an array of expressions into a WHERE or JOIN clause.
     * 
     * @param array $exprs The expressions to compile.
     * 
     * return string
     */
    protected function compileExpression(array $exprs)
    {
        $sql = '';
        
        foreach ($exprs as $expr) {
            $op     = $expr['expression']->operator();
            $concat = $this->concatenators[$expr['concatenator']];
            $open   = str_repeat('(', $expr['open']);
            $close  = str_repeat(')', $expr['close']);
            $field  = $this->quote($expr['expression']->field());
            $value  = $expr['expression']->value();
            
            if ($value !== '?') {
                $value = $this->quote($value);
            }
            
            $sql .= $open . $concat . ' ' . $field . ' ' . $op . ' ' . $value . $close . ' ';
        }
        
        $sql = preg_replace('/^([(]*)\s*(AND|OR)\s*/', '$1', $sql);
        $sql = trim($sql);
        
        return $sql;
    }
    
    /**
     * Compiles the ORDER BY part of a find statement.
     * 
     * @param array  $fields    The fields to order by.
     * @param string $direction The sort direction.
     * 
     * @return string
     */
    protected function compileOrderBy(array $fields, $direction)
    {
        if (!$fields) {
            return;
        }
        
        $fields    = $this->quoteAll($fields);
        $fields    = implode(', ', $fields);
        $direction = $this->directions[$direction];
        
        return 'ORDER BY ' . $fields . ' ' . $direction;
    }
}