<?php

namespace Core\Database;

use PDO;
use Core\Logger;
use PDOException;
use RuntimeException;
use Core\Database\Database;
use InvalidArgumentException;
use Core\Database\Builders\JoinClause;
use Core\Database\Builders\JoinBuilder;
use Core\Database\Builders\WhereBuilder;
use Core\Database\Concerns\BuildsQueries;
use Core\Database\Validators\SqlValidator;
use Core\Database\Concerns\ExecutesQueries;
use Core\Database\Builders\AggregateBuilder;
use Core\Database\Concerns\ManagesTransactions;

/**
 * QueryBuilder - Constructeur de requêtes SQL avec sécurité renforcée
 *
 * Fonctionnalités complètes:
 * - SELECT avec colonnes, DISTINCT, agrégats
 * - WHERE avec conditions multiples, imbriquées, et opérateurs variés
 * - JOIN (INNER, LEFT, RIGHT, FULL, CROSS)
 * - GROUP BY, HAVING
 * - ORDER BY, LIMIT, OFFSET
 * - INSERT, UPDATE, DELETE avec protection
 * - Sous-requêtes et requêtes complexes
 * - Transactions
 * - Cache de requêtes
 * - Pagination
 * - Validation stricte pour prévenir les injections SQL
 */
class QueryBuilder
{
    use BuildsQueries, ExecutesQueries, ManagesTransactions;

    // Composants principaux
    private Database $db;
    private SqlValidator $validator;
    private WhereBuilder $whereBuilder;
    private JoinBuilder $joinBuilder;
    private AggregateBuilder $aggregateBuilder;

    // État de la requête
    private string $table;
    private array $columns = ['*'];
    private bool $distinct = false;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $orderBy = [];
    private array $groupBy = [];
    private ?string $having = null;
    private array $havingBindings = [];

    // Bindings et cache
    private array $bindings = [];
    private ?string $cachedQuery = null;
    private bool $cacheDirty = true;

    // État courant de la requête CRUD (pour toSql())
    private string $queryType = 'SELECT';
    private array $crudData        = [];  // données INSERT / UPDATE
    private array $crudBindings    = [];  // bindings générés par les méthodes CRUD

    // FIX #5 : mapping column → placeholder pour buildUpdateQuery()
    // Évite la recherche fragile par str_starts_with qui pouvait provoquer des injections.
    private array $crudColumnMap   = [];  // ['column_name' => 'placeholder_key']

    // Configuration
    private bool $debug = false;
    private const MAX_LIMIT = 10000;
    private const MAX_PER_PAGE = 100;

    /**
     * FIX #6 : compteur de placeholders statique.
     * Reste statique pour garantir l'unicité globale sur toute la durée d'une requête HTTP,
     * mais peut être remis à zéro via resetPlaceholderCounter() dans les tests unitaires.
     */
    private static int $placeholderCounter = 0;

    // ============================================
    // CONSTRUCTEUR
    // ============================================

    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->validator = new SqlValidator();
        $this->whereBuilder = new WhereBuilder($this->validator);
        $this->joinBuilder = new JoinBuilder($this->validator);
        $this->aggregateBuilder = new AggregateBuilder($this->validator);

        $this->setTable($table);
    }

    /**
     * Définit la table avec validation
     */
    private function setTable(string $table): void
    {
        $table = $this->validator->cleanInput($table);

        if (!$this->validator->isValidTableIdentifier($table)) {
            throw new InvalidArgumentException("Invalid table name: $table");
        }

        $this->table = $table;
    }

    /**
     * Active le mode debug
     */
    public function debug(bool $debug = true): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Marque le cache comme invalide
     */
    private function invalidateCache(): void
    {
        $this->cacheDirty = true;
        $this->cachedQuery = null;
    }

    // ============================================
    // FIX #4 : getter public pour whereBuilder
    // Avant : accès direct à la propriété privée depuis whereNested() via une
    // autre instance de QueryBuilder. PHP l'autorise au sein d'une même classe,
    // mais c'est fragile lors d'une extraction en Trait ou sous-classe.
    // ============================================

    /**
     * Retourne le WhereBuilder interne (utilisé par whereNested et whereExists).
     */
    public function getWhereBuilder(): WhereBuilder
    {
        return $this->whereBuilder;
    }

    // ============================================
    // FIX #6 : reset du compteur pour les tests
    // ============================================

    /**
     * Remet à zéro le compteur de placeholders.
     * À appeler dans setUp() des tests unitaires pour des assertions déterministes.
     */
    public static function resetPlaceholderCounter(): void
    {
        self::$placeholderCounter = 0;
    }

    // ============================================
    // SELECT - Sélection de colonnes
    // ============================================

    public function select(...$columns): self
    {
        if (empty($columns)) {
            $this->columns = ['*'];
            return $this;
        }

        $validatedColumns = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                foreach ($column as $col) {
                    $validatedColumns[] = $this->validateColumn($col);
                }
            } else {
                $validatedColumns[] = $this->validateColumn($column);
            }
        }

        $this->columns = empty($validatedColumns) ? ['*'] : $validatedColumns;
        $this->invalidateCache();

        return $this;
    }

    private function validateColumn(string $column): string
    {
        $cleanColumn = $this->validator->cleanInput($column);

        if (empty($cleanColumn)) {
            throw new InvalidArgumentException("Column name cannot be empty");
        }

        if (!$this->validator->isValidColumnIdentifier($cleanColumn)) {
            throw new InvalidArgumentException("Invalid column name: $column");
        }

        return $cleanColumn;
    }

    public function selectRaw(string $expression, array $bindings = []): self
    {
        Logger::warning('Using raw SQL in SELECT', [
            'expression'      => $expression,
            'bindings_count'  => count($bindings),
        ]);

        $cleanExpression = $this->validator->cleanInput($expression);
        $this->columns[] = $cleanExpression;

        foreach ($bindings as $key => $value) {
            $placeholder = is_numeric($key)
                ? $this->createPlaceholder('raw_select_' . $key)
                : ltrim($key, ':');
            $this->bindings[$placeholder] = $value;
        }

        $this->invalidateCache();
        return $this;
    }

    public function selectOnly(string $column): self
    {
        return $this->select($column);
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;
        $this->invalidateCache();
        return $this;
    }

    // ============================================
    // WHERE - Conditions
    // ============================================

    /**
     * Ajoute une condition WHERE.
     *
     * Comportement avec 2 arguments (FIX #7, documenté) :
     *   ->where('col', 'value')   → col = 'value'
     *   ->where('col', null)      → col IS NULL   (via le bloc null de buildWhere)
     *   ->where('col', 0)         → col = 0
     *   ->where('col', false)     → col = false
     *
     * Si $value est null ET $operator vaut déjà '=' (valeur par défaut), le builder
     * génère IS NULL sans ambiguïté. Pour IS NOT NULL explicite, préférer whereNotNull().
     */
    public function where($column, $operator = '=', $value = null, string $boolean = 'AND'): self
    {
        if (is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        if (is_array($column)) {
            return $this->whereArray($column, $boolean);
        }

        // Forme à 2 arguments : ->where('col', $value)
        // On décale uniquement si $value est encore null ET que $operator n'est pas déjà '='
        if ($value === null && $operator !== '=') {
            $value    = $operator;
            $operator = '=';
        }

        $whereData = $this->whereBuilder->buildWhere(
            $column,
            $operator,
            $value,
            $boolean,
            fn($col) => $this->createPlaceholder($col)
        );

        $this->whereBuilder->addWhere($whereData);

        if (isset($whereData['bindings'])) {
            $this->bindings = array_merge($this->bindings, $whereData['bindings']);
        }

        $this->invalidateCache();
        return $this;
    }

    private function whereArray(array $conditions, string $boolean = 'AND'): self
    {
        foreach ($conditions as $key => $value) {
            if (is_numeric($key)) {
                if (is_array($value)) {
                    $this->where(...$value);
                }
            } else {
                $this->where($key, '=', $value, $boolean);
            }
        }

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if (empty($values)) {
            throw new InvalidArgumentException("whereIn requires at least one value");
        }

        $whereData = $this->whereBuilder->buildWhereIn(
            $column,
            $values,
            $boolean,
            $not,
            fn($col, $index) => $this->createPlaceholder($col . '_in_' . $index)
        );

        $this->whereBuilder->addWhere($whereData);
        $this->bindings = array_merge($this->bindings, $whereData['bindings']);
        $this->invalidateCache();

        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR', true);
    }

    public function whereBetween(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if (count($values) !== 2) {
            throw new InvalidArgumentException("whereBetween requires exactly 2 values");
        }

        $whereData = $this->whereBuilder->buildWhereBetween(
            $column,
            $values,
            $boolean,
            $not,
            fn($col, $suffix) => $this->createPlaceholder($col . '_between_' . $suffix)
        );

        $this->whereBuilder->addWhere($whereData);
        $this->bindings = array_merge($this->bindings, $whereData['bindings']);
        $this->invalidateCache();

        return $this;
    }

    public function whereNotBetween(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    public function orWhereBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'OR');
    }

    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->whereBetween($column, $values, 'OR', true);
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $whereData = $this->whereBuilder->buildWhereNull($column, $boolean, false);
        $this->whereBuilder->addWhere($whereData);
        $this->invalidateCache();

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $whereData = $this->whereBuilder->buildWhereNull($column, $boolean, true);
        $this->whereBuilder->addWhere($whereData);
        $this->invalidateCache();

        return $this;
    }

    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    /**
     * WHERE avec conditions imbriquées.
     *
     * FIX #4 : utilisation du getter getWhereBuilder() au lieu de l'accès direct
     * à la propriété privée $query->whereBuilder. Résistant à une future extraction
     * en Trait ou sous-classe.
     */
    private function whereNested(callable $callback, string $boolean = 'AND'): self
    {
        $query = new static($this->db, $this->table);

        try {
            $callback($query);
        } catch (\Exception $e) {
            Logger::error('Error in nested where callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new RuntimeException(
                "Error in nested WHERE clause: " . $e->getMessage(),
                0,
                $e
            );
        }

        $nestedWheres = $query->getWhereBuilder()->getWheres(); // FIX #4

        if (!empty($nestedWheres)) {
            $this->whereBuilder->addWhere([
                'type'    => 'nested',
                'query'   => $query->getWhereBuilder(), // FIX #4
                'boolean' => strtoupper($boolean),
            ]);

            $this->bindings = array_merge($this->bindings, $query->bindings);
            $this->invalidateCache();
        }

        return $this;
    }

    /**
     * WHERE EXISTS (sous-requête).
     *
     * FIX #9 : validation que le callback produit bien une requête SELECT.
     * Un callback qui appellerait insert()/update() sur la sous-requête générait
     * un EXISTS (INSERT …) syntaxiquement invalide.
     */
    public function whereExists(callable $callback, string $boolean = 'AND', bool $not = false): self
    {
        $query = new static($this->db, $this->table);
        $callback($query);

        // FIX #9 : on s'assure que la sous-requête est bien un SELECT
        if ($query->queryType !== 'SELECT') {
            throw new InvalidArgumentException(
                "whereExists() requires a SELECT subquery, got: {$query->queryType}"
            );
        }

        $subQuery    = $query->toSql();
        $subBindings = $query->getBindings();

        $operator = $not ? 'NOT EXISTS' : 'EXISTS';

        $this->whereBuilder->addWhere([
            'type'     => 'exists',
            'operator' => $operator,
            'query'    => $subQuery,
            'boolean'  => strtoupper($boolean),
        ]);

        $this->bindings = array_merge($this->bindings, $subBindings);
        $this->invalidateCache();

        return $this;
    }

    public function whereNotExists(callable $callback, string $boolean = 'AND'): self
    {
        return $this->whereExists($callback, $boolean, true);
    }

    public function orWhereExists(callable $callback): self
    {
        return $this->whereExists($callback, 'OR');
    }

    public function orWhereNotExists(callable $callback): self
    {
        return $this->whereExists($callback, 'OR', true);
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): self
    {
        $first  = $this->validateColumn($first);
        $second = $this->validateColumn($second);

        $this->validator->validateOperator($operator);

        $this->whereBuilder->addWhere([
            'type'     => 'column',
            'first'    => $first,
            'operator' => strtoupper($operator),
            'second'   => $second,
            'boolean'  => strtoupper($boolean),
        ]);

        $this->invalidateCache();
        return $this;
    }

    public function orWhereColumn(string $first, string $operator, string $second): self
    {
        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    public function whereDate(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        return $this->whereDateBased('DATE', $column, $operator, $value, $boolean);
    }

    public function whereTime(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        return $this->whereDateBased('TIME', $column, $operator, $value, $boolean);
    }

    public function whereYear(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        return $this->whereDateBased('YEAR', $column, $operator, $value, $boolean);
    }

    public function whereMonth(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        return $this->whereDateBased('MONTH', $column, $operator, $value, $boolean);
    }

    public function whereDay(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        return $this->whereDateBased('DAY', $column, $operator, $value, $boolean);
    }

    private function whereDateBased(string $function, string $column, string $operator, $value, string $boolean): self
    {
        $column = $this->validateColumn($column);
        $this->validator->validateOperator($operator);

        $placeholder = $this->createPlaceholder($column . '_' . strtolower($function));

        $this->whereBuilder->addWhere([
            'type'        => 'date',
            'function'    => $function,
            'column'      => $column,
            'operator'    => strtoupper($operator),
            'placeholder' => $placeholder,
            'boolean'     => strtoupper($boolean),
        ]);

        $this->bindings[$placeholder] = $value;
        $this->invalidateCache();

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        Logger::warning('Using raw SQL in WHERE clause', [
            'sql'            => $sql,
            'bindings_count' => count($bindings),
        ]);

        $sql = $this->validator->cleanInput($sql);

        $processedBindings = [];
        foreach ($bindings as $key => $value) {
            if (is_numeric($key)) {
                $placeholder = $this->createPlaceholder('raw_' . $key);
                $sql = preg_replace('/\?/', ':' . $placeholder, $sql, 1);
                $processedBindings[$placeholder] = $value;
            } else {
                $placeholder = ltrim($key, ':');
                $processedBindings[$placeholder] = $value;
            }
        }

        $this->whereBuilder->addWhere([
            'type'    => 'raw',
            'sql'     => $sql,
            'boolean' => strtoupper($boolean),
        ]);

        $this->bindings = array_merge($this->bindings, $processedBindings);
        $this->invalidateCache();

        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    // ============================================
    // JOIN - Jointures
    // ============================================

    public function join(string $table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER'): self
    {
        if (is_callable($first)) {
            return $this->joinWithClosure($table, $first, $type);
        }

        $joinData = $this->joinBuilder->buildJoin($table, $first, $operator, $second, $type);
        $this->joinBuilder->addJoin($joinData);
        $this->invalidateCache();

        return $this;
    }

    private function joinWithClosure(string $table, callable $callback, string $type): self
    {
        $table = $this->validator->cleanInput($table);

        if (!$this->validator->isValidTableIdentifier($table)) {
            throw new InvalidArgumentException("Invalid table name: $table");
        }

        $joinClause = new JoinClause($this->validator, $table, $type);
        $callback($joinClause);

        $this->joinBuilder->addJoin([
            'type'      => 'complex',
            'join_type' => strtoupper($type),
            'table'     => $table,
            'clauses'   => $joinClause->getClauses(),
        ]);

        $this->invalidateCache();
        return $this;
    }

    public function leftJoin(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function fullJoin(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'FULL');
    }

    public function crossJoin(string $table): self
    {
        $joinData = $this->joinBuilder->buildCrossJoin($table);
        $this->joinBuilder->addJoin($joinData);
        $this->invalidateCache();

        return $this;
    }

    public function joinRaw(string $sql, array $bindings = []): self
    {
        $sql = $this->validator->cleanInput($sql);

        $this->joinBuilder->addJoin([
            'type' => 'raw',
            'sql'  => $sql,
        ]);

        foreach ($bindings as $key => $value) {
            $placeholder = is_numeric($key)
                ? $this->createPlaceholder('join_raw_' . $key)
                : ltrim($key, ':');
            $this->bindings[$placeholder] = $value;
        }

        $this->invalidateCache();
        return $this;
    }

    // ============================================
    // ORDER BY, GROUP BY, HAVING
    // ============================================

    public function orderBy($column, string $direction = 'ASC'): self
    {
        if (is_array($column)) {
            foreach ($column as $col => $dir) {
                if (is_numeric($col)) {
                    $this->orderBy($dir, $direction);
                } else {
                    $this->orderBy($col, $dir);
                }
            }
            return $this;
        }

        $column = $this->validateColumn($column);

        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid order direction: $direction");
        }

        $this->orderBy[] = [
            'column'    => $column,
            'direction' => $direction,
        ];

        $this->invalidateCache();
        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        Logger::warning('Using raw SQL in ORDER BY', ['sql' => $sql]);

        $sql = $this->validator->cleanInput($sql);

        $this->orderBy[] = [
            'type' => 'raw',
            'sql'  => $sql,
        ];

        foreach ($bindings as $key => $value) {
            $placeholder = is_numeric($key)
                ? $this->createPlaceholder('order_raw_' . $key)
                : ltrim($key, ':');
            $this->bindings[$placeholder] = $value;
        }

        $this->invalidateCache();
        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderByDesc($column);
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    public function inRandomOrder(): self
    {
        $this->orderBy[] = ['type' => 'random'];
        $this->invalidateCache();
        return $this;
    }

    public function groupBy(...$columns): self
    {
        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->groupBy(...$column);
            } else {
                $validated       = $this->validateColumn($column);
                $this->groupBy[] = $validated;
            }
        }

        $this->invalidateCache();
        return $this;
    }

    public function groupByRaw(string $sql, array $bindings = []): self
    {
        Logger::warning('Using raw SQL in GROUP BY', ['sql' => $sql]);

        $sql             = $this->validator->cleanInput($sql);
        $this->groupBy[] = $sql;

        foreach ($bindings as $key => $value) {
            $placeholder = is_numeric($key)
                ? $this->createPlaceholder('group_raw_' . $key)
                : ltrim($key, ':');
            $this->bindings[$placeholder] = $value;
        }

        $this->invalidateCache();
        return $this;
    }

    public function having(string $column, string $operator, $value = null): self
    {
        if ($value === null) {
            $value    = $operator;
            $operator = '=';
        }

        $column = $this->validateColumn($column);
        $this->validator->validateOperator($operator);

        $placeholder = $this->createPlaceholder('having_' . $column);
        $this->having = "$column " . strtoupper($operator) . " :$placeholder";
        $this->havingBindings[$placeholder] = $value;

        $this->invalidateCache();
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): self
    {
        Logger::warning('Using raw SQL in HAVING', ['sql' => $sql]);

        $this->having = $this->validator->cleanInput($sql);

        foreach ($bindings as $key => $value) {
            $placeholder = is_numeric($key)
                ? $this->createPlaceholder('having_raw_' . $key)
                : ltrim($key, ':');
            $this->havingBindings[$placeholder] = $value;
        }

        $this->invalidateCache();
        return $this;
    }

    // ============================================
    // LIMIT, OFFSET, PAGINATION
    // ============================================

    public function limit(?int $limit): self
    {
        if ($limit !== null) {
            if ($limit < 0) {
                throw new InvalidArgumentException("Limit must be positive or null");
            }

            if ($limit > self::MAX_LIMIT) {
                Logger::warning('Limit exceeds maximum allowed', [
                    'requested' => $limit,
                    'max'       => self::MAX_LIMIT,
                ]);
                $limit = self::MAX_LIMIT;
            }
        }

        $this->limit = $limit;
        $this->invalidateCache();
        return $this;
    }

    public function offset(?int $offset): self
    {
        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException("Offset must be positive or null");
        }

        $this->offset = $offset;
        $this->invalidateCache();
        return $this;
    }

    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    public function paginate(int $page = 1, int $perPage = 15): array
    {
        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 15;
        if ($perPage > self::MAX_PER_PAGE) {
            Logger::warning('High per-page value in pagination', ['per_page' => $perPage]);
            $perPage = self::MAX_PER_PAGE;
        }

        $total    = $this->count();
        $lastPage = (int) ceil($total / $perPage);
        $from     = ($page - 1) * $perPage + 1;
        $to       = min($page * $perPage, $total);

        $data = (clone $this)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'data'               => $data,
            'total'              => $total,
            'per_page'           => $perPage,
            'current_page'       => $page,
            'last_page'          => $lastPage,
            'from'               => $from,
            'to'                 => $to,
            'has_more_pages'     => $page < $lastPage,
            'has_previous_pages' => $page > 1,
        ];
    }

    public function simplePaginate(int $page = 1, int $perPage = 15): array
    {
        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 15;

        $data = (clone $this)
            ->limit($perPage + 1)
            ->offset(($page - 1) * $perPage)
            ->get();

        $hasMorePages = count($data) > $perPage;

        if ($hasMorePages) {
            array_pop($data);
        }

        return [
            'data'               => $data,
            'per_page'           => $perPage,
            'current_page'       => $page,
            'has_more_pages'     => $hasMorePages,
            'has_previous_pages' => $page > 1,
        ];
    }

    // ============================================
    // AGRÉGATS
    // ============================================

    public function count(string $column = '*'): int
    {
        return $this->aggregateBuilder->count($this, $column);
    }

    public function min(string $column)
    {
        return $this->aggregateBuilder->min($this, $column);
    }

    public function max(string $column)
    {
        return $this->aggregateBuilder->max($this, $column);
    }

    public function avg(string $column)
    {
        return $this->aggregateBuilder->avg($this, $column);
    }

    public function sum(string $column)
    {
        return $this->aggregateBuilder->sum($this, $column);
    }

    public function aggregate(string $function, string $column)
    {
        return $this->aggregateBuilder->aggregate($this, $function, $column);
    }

    // ============================================
    // EXÉCUTION DE REQUÊTES SELECT
    // ============================================

    public function get(): array
    {
        try {
            $query    = $this->toSql();
            $bindings = $this->getAllBindings();

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $startTime     = microtime(true);
            $result        = $this->db->query($query, $bindings, false);
            $executionTime = microtime(true) - $startTime;

            if ($executionTime > 2.0) {
                Logger::warning('Slow query detected', [
                    'query'           => substr($query, 0, 200),
                    'execution_time'  => $executionTime,
                    'bindings_count'  => count($bindings),
                ]);
            }

            return $result ?: [];
        } catch (PDOException $e) {
            Logger::error('Database query failed', [
                'query' => isset($query) ? substr($query, 0, 200) : 'N/A',
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
            ]);
            throw new RuntimeException(
                "Database query failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    public function firstOrFail(): array
    {
        $result = $this->first();

        if ($result === null) {
            throw new RuntimeException("No results found for query");
        }

        return $result;
    }

    public function find($id, string $column = 'id'): ?array
    {
        return $this->where($column, $id)->first();
    }

    public function findOrFail($id, string $column = 'id'): array
    {
        $result = $this->find($id, $column);

        if ($result === null) {
            throw new RuntimeException("No record found with $column = $id");
        }

        return $result;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function value(string $column)
    {
        $column = $this->validateColumn($column);
        $this->select($column);

        $result = $this->first();

        if ($result === null) {
            return null;
        }

        $columnName = $column;
        if (preg_match('/(?:AS\s+)?(\w+)$/i', $column, $matches)) {
            $columnName = $matches[1];
        } elseif (preg_match('/\.(\w+)$/', $column, $matches)) {
            $columnName = $matches[1];
        }

        return $result[$columnName] ?? null;
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $column = $this->validateColumn($column);

        $columns = [$column];
        if ($key !== null) {
            $key       = $this->validateColumn($key);
            $columns[] = $key;
        }

        $this->select(...$columns);
        $results = $this->get();

        $columnName = $this->extractColumnName($column);
        $keyName    = $key !== null ? $this->extractColumnName($key) : null;

        if ($keyName !== null) {
            return array_column($results, $columnName, $keyName);
        }

        return array_column($results, $columnName);
    }

    private function extractColumnName(string $column): string
    {
        if (preg_match('/(?:AS\s+)?(\w+)$/i', $column, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\.(\w+)$/', $column, $matches)) {
            return $matches[1];
        }

        return $column;
    }

    public function chunk(int $size, callable $callback): bool
    {
        if ($size < 1) {
            throw new InvalidArgumentException("Chunk size must be at least 1");
        }

        $page = 1;

        do {
            $results = (clone $this)
                ->offset(($page - 1) * $size)
                ->limit($size)
                ->get();

            if (empty($results)) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while (count($results) === $size);

        return true;
    }

    public function each(callable $callback, int $chunkSize = 1000): bool
    {
        return $this->chunk($chunkSize, function ($results) use ($callback) {
            foreach ($results as $key => $result) {
                if ($callback($result, $key) === false) {
                    return false;
                }
            }
            return true;
        });
    }

    public function cursor(): \Generator
    {
        $query    = $this->toSql();
        $bindings = $this->getAllBindings();

        if ($this->debug) {
            $this->logQuery($query, $bindings);
        }

        try {
            $pdo  = $this->db->getPdo();
            $stmt = $pdo->prepare($query);
            $stmt->execute($bindings);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } catch (PDOException $e) {
            Logger::error('Cursor query failed', [
                'query' => substr($query, 0, 200),
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Cursor query failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ============================================
    // INSERT, UPDATE, DELETE
    // ============================================

    /**
     * Prépare les données INSERT.
     */
    private function prepareInsert(array $data): array
    {
        foreach (array_keys($data) as $column) {
            $this->validateColumn($column);
        }

        $tableName    = $this->getTableNameWithoutAlias();
        $columns      = implode(', ', array_keys($data));
        $bindings     = [];
        $placeholders = [];

        foreach ($data as $column => $value) {
            $placeholder        = $this->createPlaceholder('insert_' . $column);
            $placeholders[]     = ':' . $placeholder;
            $bindings[$placeholder] = $value;
        }

        $query = "INSERT INTO $tableName ($columns) VALUES (" . implode(', ', $placeholders) . ")";

        $this->queryType    = 'INSERT';
        $this->crudData     = $data;
        $this->crudBindings = $bindings;
        $this->crudColumnMap = [];
        $this->invalidateCache();

        return [$query, $bindings];
    }

    public function insert(array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Insert data cannot be empty");
        }

        try {
            [$query, $bindings] = $this->prepareInsert($data);

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $stmt = $this->db->getPdo()->prepare($query);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Insert failed', [
                'table' => $this->table,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Insert failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function insertGetId(array $data, ?string $sequence = null): int
    {
        $this->insert($data);
        return (int) $this->db->getPdo()->lastInsertId($sequence);
    }

    public function insertMany(array $rows): int
    {
        if (empty($rows)) {
            throw new InvalidArgumentException("Insert rows cannot be empty");
        }

        $inserted = 0;

        try {
            $this->db->beginTransaction();

            foreach ($rows as $row) {
                $this->insert($row);
                $inserted++;
            }

            $this->db->commitTransaction();
            return $inserted;
        } catch (\Exception $e) {
            $this->db->rollbackTransaction();
            throw $e;
        }
    }

    public function upsert(array $data, array $uniqueKeys, array $updateColumns = []): int
    {
        Logger::warning('Upsert called - implementation may vary by database');

        $exists = false;
        foreach ($uniqueKeys as $key) {
            if (isset($data[$key])) {
                $exists = $this->where($key, $data[$key])->exists();
                if ($exists) break;
            }
        }

        if ($exists) {
            $updateData = empty($updateColumns)
                ? $data
                : array_intersect_key($data, array_flip($updateColumns));
            return $this->update($updateData);
        } else {
            return $this->insert($data);
        }
    }

    /**
     * Prépare les données UPDATE.
     *
     * FIX #5 : on construit et stocke un mapping explicite column → placeholder
     * dans $this->crudColumnMap. buildUpdateQuery() l'utilise directement sans
     * la recherche fragile par str_starts_with (qui pouvait :
     *   - donner de faux positifs sur des colonnes partageant un préfixe,
     *   - injecter $value brut dans le SQL en cas de match raté).
     */
    private function prepareUpdate(array $data): array
    {
        foreach (array_keys($data) as $column) {
            $this->validateColumn($column);
        }

        $where     = $this->whereBuilder->buildWhereClause();
        $tableName = $this->getTableNameWithoutAlias();

        $sets        = [];
        $bindings    = [];
        $columnMap   = [];   // FIX #5 : mapping direct

        foreach ($data as $column => $value) {
            $placeholder        = $this->createPlaceholder('set_' . $column);
            $sets[]             = "$column = :$placeholder";
            $bindings[$placeholder] = $value;
            $columnMap[$column] = $placeholder;  // FIX #5
        }

        // Fusionner avec les bindings WHERE
        $bindings = array_merge($bindings, $this->bindings);

        $query = "UPDATE $tableName SET " . implode(', ', $sets) . " WHERE $where";

        $this->queryType     = 'UPDATE';
        $this->crudData      = $data;
        $this->crudBindings  = $bindings;
        $this->crudColumnMap = $columnMap;  // FIX #5
        $this->invalidateCache();

        return [$query, $bindings];
    }

    public function update(array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Update data cannot be empty");
        }

        $where = $this->whereBuilder->buildWhereClause();

        if (empty($where)) {
            throw new RuntimeException(
                "UPDATE requires WHERE clause for safety. Use updateAll() to update without conditions."
            );
        }

        try {
            [$query, $bindings] = $this->prepareUpdate($data);

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $stmt = $this->db->getPdo()->prepare($query);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Update failed', [
                'table' => $this->table,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Update failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * UPDATE sans condition (DANGEREUX).
     *
     * FIX #10 : on ne pollue plus l'état du builder avec whereRaw('1=1').
     * La requête est construite directement via prepareUpdate() après avoir
     * injecté un WHERE factice dans une copie isolée.
     */
    public function updateAll(array $data, bool $iAmSure = false): int
    {
        if (!$iAmSure) {
            throw new RuntimeException(
                "updateAll() is dangerous and requires explicit confirmation. Pass true as second parameter."
            );
        }

        Logger::warning('UPDATE ALL executed', [
            'table' => $this->table,
            'data'  => $data,
        ]);

        // FIX #10 : on travaille sur un clone pour ne pas polluer l'état courant
        $clone = clone $this;
        $clone->whereRaw('1=1');

        [$query, $bindings] = $clone->prepareUpdate($data);

        // Synchroniser l'état CRUD vers $this pour que toSql()/getBindings() soient cohérents
        $this->queryType     = $clone->queryType;
        $this->crudData      = $clone->crudData;
        $this->crudBindings  = $clone->crudBindings;
        $this->crudColumnMap = $clone->crudColumnMap;
        $this->invalidateCache();

        if ($this->debug) {
            $this->logQuery($query, $bindings);
        }

        $stmt = $this->db->getPdo()->prepare($query);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $record = (clone $this)->where($attributes)->first();

        if ($record !== null) {
            (clone $this)->where($attributes)->update($values);
            return false;
        } else {
            $this->insert(array_merge($attributes, $values));
            return true;
        }
    }

    /**
     * Prépare la requête DELETE.
     */
    private function prepareDelete(string $where): array
    {
        $tableName = $this->getTableNameWithoutAlias();
        $bindings  = $this->bindings;

        $query = "DELETE FROM $tableName WHERE $where";

        $this->queryType     = 'DELETE';
        $this->crudData      = [];
        $this->crudBindings  = $bindings;
        $this->crudColumnMap = [];
        $this->invalidateCache();

        return [$query, $bindings];
    }

    public function delete(): int
    {
        $where = $this->whereBuilder->buildWhereClause();

        if (empty($where)) {
            throw new RuntimeException(
                "DELETE requires WHERE clause for safety. Use deleteAll() to delete without conditions."
            );
        }

        try {
            [$query, $bindings] = $this->prepareDelete($where);

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $stmt = $this->db->getPdo()->prepare($query);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Delete failed', [
                'table' => $this->table,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Delete failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function deleteAll(bool $iAmSure = false): int
    {
        if (!$iAmSure) {
            throw new RuntimeException(
                "deleteAll() is dangerous and requires explicit confirmation. Pass true as parameter."
            );
        }

        Logger::warning('DELETE ALL executed', ['table' => $this->table]);

        [$query, $bindings] = $this->prepareDelete('1=1');

        if ($this->debug) {
            $this->logQuery($query, $bindings);
        }

        $stmt = $this->db->getPdo()->prepare($query);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    public function truncate(bool $iAmSure = false): bool
    {
        if (!$iAmSure) {
            throw new RuntimeException(
                "truncate() is dangerous and requires explicit confirmation. Pass true as parameter."
            );
        }

        Logger::warning('TRUNCATE executed', ['table' => $this->table]);

        try {
            $tableName = $this->getTableNameWithoutAlias();

            $this->queryType     = 'TRUNCATE';
            $this->crudData      = [];
            $this->crudBindings  = [];
            $this->crudColumnMap = [];
            $this->invalidateCache();

            $query = "TRUNCATE TABLE $tableName";

            if ($this->debug) {
                $this->logQuery($query, []);
            }

            $this->db->query($query);
            return true;
        } catch (PDOException $e) {
            Logger::error('Truncate failed', [
                'table' => $this->table,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Truncate failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ============================================
    // INCREMENT / DECREMENT
    // ============================================

    public function increment(string $column, int $amount = 1, array $extra = []): int
    {
        $column = $this->validateColumn($column);

        if ($amount <= 0) {
            throw new InvalidArgumentException("Increment amount must be positive");
        }

        $where = $this->whereBuilder->buildWhereClause();

        if (empty($where)) {
            throw new RuntimeException("INCREMENT requires WHERE clause for safety");
        }

        try {
            $tableName = $this->getTableNameWithoutAlias();

            $sets     = ["$column = $column + :increment_amount"];
            $bindings = array_merge(['increment_amount' => $amount], $this->bindings);

            foreach ($extra as $key => $value) {
                $placeholder        = $this->createPlaceholder($key);
                $sets[]             = "$key = :$placeholder";
                $bindings[$placeholder] = $value;
            }

            $query = "UPDATE $tableName SET " . implode(', ', $sets) . " WHERE $where";

            $this->queryType     = 'INCREMENT';
            $this->crudData      = array_merge([$column => "$column + $amount"], $extra);
            $this->crudBindings  = $bindings;
            $this->crudColumnMap = [];
            $this->invalidateCache();

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $stmt = $this->db->getPdo()->prepare($query);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Increment failed', [
                'column' => $column,
                'amount' => $amount,
                'error'  => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Increment failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function decrement(string $column, int $amount = 1, array $extra = []): int
    {
        $column = $this->validateColumn($column);

        if ($amount <= 0) {
            throw new InvalidArgumentException("Decrement amount must be positive");
        }

        $where = $this->whereBuilder->buildWhereClause();

        if (empty($where)) {
            throw new RuntimeException("DECREMENT requires WHERE clause for safety");
        }

        try {
            $tableName = $this->getTableNameWithoutAlias();

            $sets     = ["$column = $column - :decrement_amount"];
            $bindings = array_merge(['decrement_amount' => $amount], $this->bindings);

            foreach ($extra as $key => $value) {
                $placeholder        = $this->createPlaceholder($key);
                $sets[]             = "$key = :$placeholder";
                $bindings[$placeholder] = $value;
            }

            $query = "UPDATE $tableName SET " . implode(', ', $sets) . " WHERE $where";

            $this->queryType     = 'DECREMENT';
            $this->crudData      = array_merge([$column => "$column - $amount"], $extra);
            $this->crudBindings  = $bindings;
            $this->crudColumnMap = [];
            $this->invalidateCache();

            if ($this->debug) {
                $this->logQuery($query, $bindings);
            }

            $stmt = $this->db->getPdo()->prepare($query);
            $stmt->execute($bindings);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            Logger::error('Decrement failed', [
                'column' => $column,
                'amount' => $amount,
                'error'  => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Decrement failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    // ============================================
    // CONSTRUCTION DE REQUÊTES
    // ============================================

    private function buildSelectQuery(): string
    {
        if (!$this->cacheDirty && $this->cachedQuery !== null) {
            return $this->cachedQuery;
        }

        $columns  = implode(', ', $this->columns);
        $distinct = $this->distinct ? 'DISTINCT ' : '';

        $query = "SELECT {$distinct}{$columns} FROM {$this->table}";

        $query .= $this->buildJoins();

        $where = $this->whereBuilder->buildWhereClause();
        if (!empty($where)) {
            $query .= ' WHERE ' . $where;
        }

        $query .= $this->buildGroupBy();
        $query .= $this->buildHaving();
        $query .= $this->buildOrderBy();
        $query .= $this->buildLimit();

        $this->cachedQuery = $query;
        $this->cacheDirty  = false;

        return $query;
    }

    private function buildInsertQuery(): string
    {
        if (empty($this->crudData)) {
            throw new RuntimeException("No INSERT data available. Call insert() or prepareInsert() first.");
        }

        $tableName    = $this->getTableNameWithoutAlias();
        $columns      = implode(', ', array_keys($this->crudData));
        $placeholders = implode(', ', array_map(fn($k) => ':' . $k, array_keys($this->crudBindings)));

        return "INSERT INTO $tableName ($columns) VALUES ($placeholders)";
    }

    /**
     * Construit la requête UPDATE à partir de l'état CRUD courant.
     *
     * FIX #5 : on utilise $this->crudColumnMap (mapping direct column → placeholder)
     * au lieu de la recherche fragile par str_starts_with qui pouvait :
     *   - Retourner un faux positif sur des colonnes partageant un préfixe.
     *   - Injecter $value brut dans le SQL si aucun placeholder n'était trouvé.
     */
    private function buildUpdateQuery(): string
    {
        if (empty($this->crudData)) {
            throw new RuntimeException("No UPDATE data available. Call update() or prepareUpdate() first.");
        }

        $tableName = $this->getTableNameWithoutAlias();
        $where     = $this->whereBuilder->buildWhereClause();

        $sets = [];
        foreach ($this->crudData as $column => $value) {
            if (isset($this->crudColumnMap[$column])) {
                // FIX #5 : lookup direct, O(1), sans risque d'injection
                $sets[] = "$column = :{$this->crudColumnMap[$column]}";
            } else {
                // Ne devrait jamais arriver si prepareUpdate() a été appelé correctement.
                // On lève une exception plutôt que d'interpoler la valeur brute.
                throw new RuntimeException(
                    "No placeholder found for column '$column' in UPDATE query. "
                        . "This is an internal error — ensure prepareUpdate() is called before buildUpdateQuery()."
                );
            }
        }

        $query = "UPDATE $tableName SET " . implode(', ', $sets);

        if (!empty($where)) {
            $query .= " WHERE $where";
        }

        return $query;
    }

    private function buildDeleteQuery(): string
    {
        $tableName = $this->getTableNameWithoutAlias();
        $where     = $this->whereBuilder->buildWhereClause();

        $query = "DELETE FROM $tableName";

        if (!empty($where)) {
            $query .= " WHERE $where";
        }

        return $query;
    }

    private function buildTruncateQuery(): string
    {
        return "TRUNCATE TABLE " . $this->getTableNameWithoutAlias();
    }

    private function buildJoins(): string
    {
        return $this->joinBuilder->buildJoinClause();
    }

    private function buildOrderBy(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        $orders = [];

        foreach ($this->orderBy as $order) {
            if (isset($order['type'])) {
                if ($order['type'] === 'raw') {
                    $orders[] = $order['sql'];
                } elseif ($order['type'] === 'random') {
                    $orders[] = 'RAND()';
                }
            } else {
                $orders[] = "{$order['column']} {$order['direction']}";
            }
        }

        return ' ORDER BY ' . implode(', ', $orders);
    }

    private function buildGroupBy(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }

    private function buildHaving(): string
    {
        if (empty($this->having)) {
            return '';
        }

        return ' HAVING ' . $this->having;
    }

    private function buildLimit(): string
    {
        $sql = '';

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    private function getAllBindings(): array
    {
        return array_merge($this->bindings, $this->havingBindings);
    }

    private function getTableNameWithoutAlias(): string
    {
        return preg_replace('/\s+(?:AS\s+)?\w+$/i', '', $this->table);
    }

    // ============================================
    // UTILITAIRES
    // ============================================

    private function createPlaceholder(string $column): string
    {
        self::$placeholderCounter++;
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        $cleaned = substr($cleaned, 0, 30);
        return $cleaned . '_' . self::$placeholderCounter;
    }

    private function logQuery(string $query, array $params = []): void
    {
        Logger::debug('SQL Query', [
            'query'          => $query,
            'bindings'       => $params,
            'bindings_count' => count($params),
        ]);

        if ($this->debug) {
            echo "\n=== DEBUG QUERY ===\n";
            echo "SQL: $query\n";
            echo "Bindings: " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
            echo "===================\n\n";
        }
    }

    // ============================================
    // toSql / getBindings — API publique unifiée
    // ============================================

    public function toSql(): string
    {
        return match ($this->queryType) {
            'INSERT'                => $this->buildInsertQuery(),
            'UPDATE', 'INCREMENT',
            'DECREMENT'             => $this->buildUpdateQuery(),
            'DELETE'                => $this->buildDeleteQuery(),
            'TRUNCATE'              => $this->buildTruncateQuery(),
            default                 => $this->buildSelectQuery(),
        };
    }

    public function getBindings(): array
    {
        return match ($this->queryType) {
            'INSERT', 'UPDATE', 'INCREMENT',
            'DECREMENT', 'DELETE'   => $this->crudBindings,
            'TRUNCATE'              => [],
            default                 => $this->getAllBindings(),
        };
    }

    public function toArray(): array
    {
        return [
            'type'     => $this->queryType,
            'sql'      => $this->toSql(),
            'bindings' => $this->getBindings(),
            'table'    => $this->table,
            'wheres'   => $this->whereBuilder->getWheres(),
            'joins'    => $this->joinBuilder->getJoins(),
            'orders'   => $this->orderBy,
            'groups'   => $this->groupBy,
            'having'   => $this->having,
            'limit'    => $this->limit,
            'offset'   => $this->offset,
            'distinct' => $this->distinct,
        ];
    }

    public function dd(): void
    {
        var_dump($this->toArray());
        die();
    }

    public function dump(): self
    {
        var_dump($this->toArray());
        return $this;
    }

    public function reset(): self
    {
        $this->whereBuilder   = new WhereBuilder($this->validator);
        $this->joinBuilder    = new JoinBuilder($this->validator);
        $this->bindings       = [];
        $this->orderBy        = [];
        $this->groupBy        = [];
        $this->having         = null;
        $this->havingBindings = [];
        $this->limit          = null;
        $this->offset         = null;
        $this->columns        = ['*'];
        $this->distinct       = false;
        $this->queryType      = 'SELECT';
        $this->crudData       = [];
        $this->crudBindings   = [];
        $this->crudColumnMap  = [];
        $this->invalidateCache();

        return $this;
    }

    public function clone(): self
    {
        return clone $this;
    }

    public function __clone()
    {
        $this->whereBuilder     = clone $this->whereBuilder;
        $this->joinBuilder      = clone $this->joinBuilder;
        $this->aggregateBuilder = clone $this->aggregateBuilder;
        $this->invalidateCache();
    }

    public function __toString(): string
    {
        return $this->toSql();
    }

    // ============================================
    // Getters/Setters pour AggregateBuilder
    // ============================================

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
        $this->invalidateCache();
    }

    public function setLimit(?int $limit): void
    {
        $this->limit = $limit;
        $this->invalidateCache();
    }

    public function setOffset(?int $offset): void
    {
        $this->offset = $offset;
        $this->invalidateCache();
    }

    public function getValidator(): SqlValidator
    {
        return $this->validator;
    }
}
