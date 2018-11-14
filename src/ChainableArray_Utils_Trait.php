<?php
namespace JClaveau\Arrays;

/**
 * Custom functions that can be used on arrays.
 */
trait ChainableArray_Utils_Trait
{
    /**
     * Same as $this->groupByTransformed() without the transformer to
     * improve the readability in some cases.
     *
     * @param  callable $indexGenerator   Can return a scalar or an array.
     *         Multiple indexes allow to add a row to multiple groups.
     * @param  callable $conflictResolver
     *
     * @throws Missing conflict resolver
     *
     * @return array The array containing the grouped rows.
     */
    public function groupBy( callable $indexGenerator, callable $conflictResolver=null )
    {
        // todo : this doesn't work
        // return $this->groupByTransformed($indexGenerator, null, $conflictResolver);

        $out = [];
        foreach ($this->data as $key => $row) {

            if (!$row)
                continue;

            $newIndexes     = call_user_func($indexGenerator, $key, $row);
            if (!is_array($newIndexes))
                $newIndexes = [$newIndexes];

            foreach ($newIndexes as $newIndex) {
                if (!isset($out[$newIndex])) {
                    $out[$newIndex] = $row;
                }
                else {
                    if ($conflictResolver === null) {
                        self::throwUsageException(
                            "A 'group by' provoking a conflict"
                            ." has no conflict resolver defined:\n"
                            ." + key: ".$key."\n"
                            ." + existing: ".var_export($out[$newIndex], true)."\n"
                            ." + conflict: ".var_export($row, true)."\n"
                        );
                    }

                    $out[$newIndex] = call_user_func(
                        $conflictResolver,
                        $newIndex,
                        $out[$newIndex],
                        $row
                    );
                }
            }
        }

        return $this->returnConstant($out);
    }

    /**
     * Group rows in arrays indexed by the index generated by $indexGenerator
     *
     * @param  callable $indexGenerator   Can return a scalar or an array.
     *         Multiple indexes allow to add a row to multiple groups.
     *
     * @return array The array containing the grouped rows.
     */
    public function groupInArrays( callable $indexGenerator )
    {
        $out = [];
        foreach ($this->data as $key => $row) {

            if (!$row)
                continue;

            $new_keys = call_user_func($indexGenerator, $row, $key);
            if (!is_array($new_keys))
                $new_keys = [$new_keys];

            foreach ($new_keys as $new_key) {
                if (!isset($out[ $new_key ])) {
                    $out[ $new_key ] = [
                        $key => $row
                    ];
                }
                else {
                    $out[ $new_key ][ $key ] = $row;
                }
            }
        }

        return $this->returnConstant($out);
    }

    /**
     * Equivalent of array_merge_recursive with more options.
     *
     * @param array         $existing_row
     * @param array         $conflict_row
     * @param callable|null $merge_resolver
     * @param int           $max_depth
     *
     * + If exist only in conflict row => add
     * + If same continue
     * + If different merge as array
     */
    public static function mergeRecursiveCustom(
        array $existing_row,
        array $conflict_row,
        callable $merge_resolver=null,
        $max_depth=null
    ){
        foreach ($conflict_row as $column => $conflict_value) {

            // not existing in first array
            if (!isset($existing_row[$column])) {
                $existing_row[$column] = $conflict_value;
                continue;
            }

            $existing_value = $existing_row[$column];

            // two arrays so we recurse
            if (is_array($existing_value) && is_array($conflict_value)) {

                if ($max_depth === null || $max_depth > 0) {
                    $existing_row[$column] = self::mergeRecursiveCustom(
                        $existing_value,
                        $conflict_value,
                        $merge_resolver,
                        $max_depth - 1
                    );
                    continue;
                }
            }

            if ($merge_resolver) {
                $existing_row[$column] = call_user_func_array(
                    $merge_resolver,
                    [
                        $existing_value,
                        $conflict_value,
                        $column,
                    ]
                );
            }
            else {
                // same reslution as array_merge_recursive
                if (!is_array($existing_value)) {
                    $existing_row[$column] = [$existing_value];
                }

                // We store the new value with their previous ones
                $existing_row[$column][] = $conflict_value;
            }
        }

        return $existing_row;
    }

    /**
     * This specific merge
     *
     * @param  array $existing_row
     * @param  array $conflict_row
     *
     * @return array
     */
    public static function mergePreservingDistincts(
        array $existing_row,
        array $conflict_row
    ){
        return static::mergeRecursiveCustom(
            $existing_row,
            $conflict_row,
            function ($existing_value, $conflict_value, $column) {

                if (!is_array($existing_value))
                    $existing_value = [$existing_value];

                // We store the new value with their previous ones
                if ( ! is_array($conflict_value)) {
                    $conflict_value = [
                        $conflict_value
                    ];
                }

                foreach ($conflict_value as $conflict_key => $conflict_entry) {
                    $existing_value[] = $conflict_entry;
                }

                return $existing_value;
            },
            1
        );
    }

    /**
     * This is the cleaning part of self::mergePreservingDistincts()
     *
     * @see mergePreservingDistincts()
     */
    public static function keepUniqueColumnValues(array $row, array $excluded_columns=[])
    {
        foreach ($row as $column => &$values) {
            if (!is_array($values))
                continue;

            if (in_array($column, $excluded_columns))
                continue;

            $values = array_unique($values);
            if (count($values) == 1)
                $values = $values[0];
        }

        return $row;
    }

    /**
     * Parses an array and group it rows by index. This index is generated
     * by the first parameter.
     * The row corresponding to the new index can be different from the
     * grouped ones so the second parameter allows us to transform them.
     * Finally, the third parameter is used to resolve the conflict e.g.
     * when two rows generate the same index.
     *
     * @paramb callable $indexGenerator
     * @paramb callable $rowTransformer
     * @paramb callable $conflictResolver
     *
     * @return array The array containing the grouped rows.
     */
    public function groupByTransformed(
        callable $indexGenerator,
        callable $rowTransformer,      // todo check this behavior
        callable $conflictResolver )
    {
        // The goal here is to remove the second parameter has it makes the
        // grouping process too complicated
        // if (!$conflictResolver) {
            // $conflictResolver = $rowTransformer;
            // $rowTransformer   = null;
        // }

        $out = [];
        foreach ($this->data as $key => $row) {

            if (!$row)
                continue;

            $newIndex       = call_user_func($indexGenerator, $key, $row);

            $transformedRow = $rowTransformer
                            ? call_user_func($rowTransformer, $row)
                            : $row;

            if (!isset($out[$newIndex])) {
                $out[$newIndex] = $transformedRow;
            }
            else {
                $out[$newIndex] = call_user_func(
                    $conflictResolver,
                    $newIndex,
                    $out[$newIndex],
                    $transformedRow,
                    $row
                );
            }
        }

        return $this->returnConstant($out);
    }

    /**
     * Merge a table into another one
     *
     * @param static $otherTable       The table to merge into
     * @param callable     $conflictResolver Defines what to do if two
     *                                       rows have the same index.
     * @return static
     */
    public function mergeWith( $otherTable, callable $conflictResolver=null )
    {
        if (is_array($otherTable))
            $otherTable = new static($otherTable);

        if (!$otherTable instanceof static) {
            self::throwUsageException(
                '$otherTable must be an array or an instance of '.static::class.' instead of: '
                .var_export($otherTable, true)
            );
        }

        $out = $this->data;
        foreach ($otherTable->getArray() as $key => $row) {

            if (!isset($out[$key])) {
                $out[$key] = $row;
            }
            else {
                if ($conflictResolver === null)
                    self::throwUsageException('No conflict resolver for a merge provoking one');

                $arguments = [
                    &$key,
                    $out[$key],
                    $row
                ];

                $out[$key] = call_user_func_array(
                    $conflictResolver,
                    $arguments
                );
            }
        }

        return $this->returnConstant($out);
    }

    /**
     * Merge the table $otherTable into the current table.
     * (same as self::mergeWith with the other table as $this)
     * @return static
     */
    public function mergeIn( $otherTable, callable $conflictResolver=null )
    {
        $otherTable->mergeWith($this, $conflictResolver);
        return $this;
    }

    /**
     * The same as self::mergeWith with an array of tables.
     *
     * @param array $othersTable array of HelperTable
     * @param func  $conflictResolver callback resolver
     */
    public function mergeSeveralWith(array $othersTable, callable $conflictResolver = null)
    {
        foreach ($othersTable as $otherTable) {
            $this->mergeWith($otherTable, $conflictResolver);
        }

        return $this;
    }

    /**
     *
     */
    public function each(callable $rowTransformer)
    {
        $out  = [];
        foreach ($this->data as $key => $row) {
            $out[$key] = call_user_func_array(
                $rowTransformer,
                [$row, &$key, $this->data]
            );
        }

        return $this->returnConstant($out);
    }

    /**
     * Rename a column on every row.
     *
     * @todo remove this method and force the usage of $this->renameColumns()?
     * @deprecated use $this->renameColumns(Array) instead]
     *
     */
    public function renameColumn($old_name, $new_name)
    {
        return $this->renameColumns([$old_name => $new_name]);
    }

    /**
     * Rename a column on every row.
     *
     * @return static
     */
    public function renameColumns(array $old_to_new_names)
    {
        $out  = [];
        foreach ($this->data as $key => $row) {
            try {
                foreach ($old_to_new_names as $old_name => $new_name) {
                    $row[$new_name] = $row[$old_name];
                    unset($row[$old_name]);
                }
            }
            catch (\Exception $e) {
                self::throwUsageException( $e->getMessage() );
            }

            $out[$key] = $row;
        }

        return $this->returnConstant($out);
    }

    /**
     * Limits the size of the array.
     *
     * @param  int         $max
     * @return Heper_Table $this
     *
     * @todo implement other parameters for this function like in SQL
     */
    public function limit()
    {
        $arguments = func_get_args();
        if (count($arguments) == 1 && is_numeric($arguments[0]))
            $max = $arguments[0];
        else
            self::throwUsageException("Bad arguments type and count for limit()");

        $out   = [];
        $count = 0;
        foreach ($this->data as $key => $row) {

            if ($max <= $count)
                break;

            $out[$key] = $row;

            $count++;
        }

        return $this->returnConstant($out);
    }

    /**
     * Appends an array to the current one.
     *
     * @param  array|static $new_rows to append
     * @param  callable           $conflict_resolver to use if a new row as the
     *                            same key as an existing row. By default, the new
     *                            key will be lost and the row appended as natively.
     *
     * @throws UsageException     If the $new_rows parameter is neither an array
     *                            nor a static.
     * @return static       $this
     */
    public function append($new_rows, callable $conflict_resolver=null)
    {
        if ($new_rows instanceof static)
            $new_rows = $new_rows->getArray();

        if (!is_array($new_rows)) {
            $this->throwUsageException(
                "\$new_rows parameter must be an array or an instance of " . __CLASS__
            );
        }

        if (!$conflict_resolver) {
            // default conflict resolver: append with numeric key
            $conflict_resolver = function (&$data, $existing_row, $confliuct_row, $key) {
                $data[] = $confliuct_row;
            };
        }

        foreach ($new_rows as $key => $new_row) {
            if (isset($this->data[$key])) {
                $arguments = [
                    &$this->data,
                    $existing_row,
                    $confliuct_row,
                    $key
                ];

                call_user_func_array($conflict_resolver, $arguments);
            }
            else {
                $this->data[$key] = $new_row;
            }
        }

        return $this;
    }

    /**
     * @param $columnNames scalar[] The names of the newly created columns.
     * @param $options     array    Unsed presently
     *
     * @see self::dimensionsAsColumns_recurser()
     *
     * @return static
     */
    public function dimensionsAsColumns(array $columnNames, array $options=null)
    {
        $out = $this->dimensionsAsColumns_recurser($this->data, $columnNames);
        return $this->returnConstant($out);
    }

    /**
     *
     * @todo Fix case of other columns
     *
     * Example:
     *  dimensionsAsColumns_recurser([
     *      [
     *          0,
     *          'me',
     *      ],
     *      [
     *          1,
     *          'me_too',
     *      ],
     *  ],
     *  [
     *      'id',
     *      'name',
     *  ]
     *
     * => [
     *      'id:0-name:me'     => [
     *          'id'   => 0,
     *          'name' => 'me',
     *      ],
     *      'id:1-name:me_too' => [
     *          'id'   => 1,
     *          'name' => 'me_too',
     *      ],
     * ]
     */
    protected function dimensionsAsColumns_recurser(array $data, $columnNames, $rowIdParts=[])
    {
        $out = [];
        // if (!$columnNames)
            // return $data;
        $no_more_column = !(bool) $columnNames;

        // If all the names have been given to the dimensions
        // we compile the index key of the row at the current level
        if (empty($columnNames)) {
            // echo json_encode([
                // 'columnNames' => $columnNames,
                // 'rowIdParts'  => $rowIdParts,
                // 'data'        => $data,
            // ]);
            // exit;

            $indexParts = [];
            foreach ($rowIdParts as $name => $value) {
                $indexParts[] = $name.':'.$value;
            }
            $row_id = implode('-', $indexParts);

            // If we are at a "leaf" of the tree
            foreach ($rowIdParts as $name => $value) {
                if (isset($data[$name]) && $data[$name] !== $value) {
                    self::throwUsageException(
                         "Trying to populate a column '$name' that "
                        ."already exists with a different value "
                        .var_export($data[$name], true). " => '$value'"
                    );
                }
                $data[$name] = $value;
            }

            $out = [
                $row_id => $data,
            ];

            return $out;
        }

        $currentDimensionName = array_shift($columnNames);

        foreach ($data as $key => $row) {

            // if (!$no_more_column)
                $rowIdParts[$currentDimensionName] = $key;
            // else
                // $rowIdParts[] = $key;


            if (is_array($row)) {
                $rows = $this->dimensionsAsColumns_recurser($row, $columnNames, $rowIdParts);
                foreach ($rows as $row_id => $joined_row) {
                    $out[$row_id] = $joined_row;
                }
            }
            else {

                if (!isset($rows)) {
                    echo json_encode([
                        '$rowIdParts' => $rowIdParts,
                        '$row' => $row,
                    ]);
                    exit;
                }

                foreach ($rowIdParts as $rowIdPartName => $rowIdPartValue)
                    $row[$rowIdPartName] = $rowIdPartValue;

                $indexParts = [];
                foreach ($rowIdParts as $name => $value) {
                    $indexParts[] = $name.':'.$value;
                }
                $row_id = implode('-', $indexParts);

                $out[$row_id] = $row;
            }

        }

        return $out;
    }

    /**
     * Generates an id usable in hashes to identify a single grouped row.
     *
     * @param array $row    The row of the array to group by.
     * @param array $groups A list of the different groups. Groups can be
     *                      strings describing a column name or a callable
     *                      function, an array representing a callable,
     *                      a function or an integer representing a column.
     *                      If the index of the group is a string, it will
     *                      be used as a prefix for the group name.
     *                      Example:
     *                      [
     *                          'column_name',
     *                          'function_to_call',
     *                          4,  //column_number
     *                          'group_prefix'  => function($row){},
     *                          'group_prefix2' => [$object, 'method'],
     *                      ]
     *
     * @return string       The unique identifier of the group
     */
    public static function generateGroupId(array $row, array $groups)
    {
        $group_parts = [];

        foreach ($groups as $key => $value) {
            $part_name = '';

            if (is_string($key)) {
                $part_name .= $key.'_';
            }

            if (is_string($value) && array_key_exists($value, $row)) {
                $part_name  .= $value;
                $group_value = $row[ $value ];
            }
            elseif (is_callable($value)) {

                if (is_string($value)) {
                    $part_name  .= $value;
                }
                // elseif (is_function($value)) {
                elseif (is_object($value) && ($value instanceof Closure)) {
                    $part_name .= 'unnamed-closure-'
                                . hash('crc32b', var_export($value, true));
                }
                elseif (is_array($value)) {
                    $part_name .= implode('::', $value);
                }

                $group_value = call_user_func_array($value, [
                    $row, &$part_name
                ]);
            }
            elseif (is_int($value)) {
                $part_name  .= $value ? : '0';
                $group_value = $row[ $value ];
            }
            else {
                self::throwUsageException(
                    'Bad value provided for groupBy id generation: '
                    .var_export($value, true)
                    ."\n" . var_export($row, true)
                );
            }

            if (!is_null($part_name))
                $group_parts[ $part_name ] = $group_value;
        }

        // sort the groups by names (without it the same group could have multiple ids)
        ksort($group_parts);

        // bidimensional implode
        $out = [];
        foreach ($group_parts as $group_name => $group_value) {
            $out[] = $group_name.':'.$group_value;
        }

        return implode('-', $out);
    }

    /**
     * Returns the first element of the array
     */
    public function first($strict=false)
    {
        if (!$this->count()) {
            if ($strict)
                throw new \ErrorException("No first element found in this array");
            else
                $first = null;
        }
        else {
            $key   = key($this->data);
            $first = reset($this->data);
            $this->move($key);
        }

        return $first;
    }

    /**
     * Returns the last element of the array
     *
     * @todo Preserve the offset
     */
    public function last($strict=false)
    {
        if (!$this->count()) {
            if ($strict)
                throw new \ErrorException("No last element found in this array");
            else
                $last = null;
        }
        else {
            $key  = key($this->data);
            $last = end($this->data);
            $this->move($key);
        }

        return $last;
    }

    /**
     *
     */
    public function firstKey($strict=false)
    {
        if (!$this->count()) {
            if ($strict)
                throw new \ErrorException("No last element found in this array");
            else
                $firstKey = null;
        }
        else {
            $key      = key($this->data);
            reset($this->data);
            $firstKey = key($this->data);
            $this->move($key);
        }

        return $firstKey;
    }

    /**
     *
     */
    public function lastKey($strict=false)
    {
        if (!$this->count()) {
            if ($strict)
                throw new \ErrorException("No last element found in this array");
            else
                $lastKey = null;
        }
        else {
            $key  = key($this->data);
            end($this->data);
            $lastKey = key($this->data);
            $this->move($key);
        }

        return $lastKey;
    }

    /**
     * Move the internal pointer of the array to the key given as parameter
     */
    public function move($key, $strict=true)
    {
        if (array_key_exists($key, $this->data)) {
            foreach ($this->data as $i => &$value) {
                if ($i === $key) {
                    prev($this->data);
                    break;
                }
            }
        }
        elseif ($strict) {
            throw new \ErrorException("Unable to move the internal pointer to a key that doesn't exist.");
        }

        return $this;
    }

    /**
     * Chained equivalent of in_array().
     * @return bool
     */
    public function contains($value)
    {
        return in_array($value, $this->data);
    }

    /**
     * Checks if the array is associative or not.
     * @return bool
     */
    public function isAssoc()
    {
        return Arrays::isAssoc($this->getArray());
    }

    /**
     * Checks if the array is empty or not.
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->getArray());
    }

    /**
     * Computes the weighted mean of the values of a column weighted
     * by the values of a second one.
     *
     * @param  string $valueColumnName
     * @param  string $weightColumnName
     *
     * @return float The calculated weighted mean.
     */
    public function weightedMean($valueColumnName, $weightColumnName)
    {
        $values  = array_column($this->data, $valueColumnName);
        $weights = array_column($this->data, $weightColumnName);

        return Helper_Math::weightedMean($values, $weights);
    }

    /**
     * Equivalent of var_dump().
     *
     * @see http://php.net/manual/fr/function.var-dump.php
     * @todo Handle xdebug dump formatting
     */
    public function dump($exit=false)
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $bt[0];

        header('content-type: text/html');
        var_dump($caller['file'] . ':' . $caller['line']);
        var_dump($this->data);

        if ($exit)
            exit;

        return $this;
    }

    /**
     * Scans the array recursivelly (until the max depthis reached) and replaces
     * the entries with the callback;
     *
     * @todo move it to an Arrays class storing static methods
     */
    public static function replaceEntries(
        array $array, callable $replacer, $max_depth=null
    ) {
        foreach ($array as $key => &$row) {
            $arguments = [&$row, $key];
            call_user_func_array($replacer, $arguments);

            if (is_array($row) && $max_depth !== 0) { // allowing null to have no depth limit
                $row = self::replaceEntries(
                    $row, $replacer, $max_depth ? $max_depth-1 : $max_depth
                );
            }
        }

        return $array;
    }

    /**
     * Equivalent of ->filter() but removes the matching values
     *
     * @param  callable|array $callback The filter logic with $value and $key
     *                            as parameters.
     *
     * @return static $this or a new static.
     */
    public function extract($callback=null)
    {
        if ($callback) {

            if (is_array($callback)) {
                $callback = new \JClaveau\LogicalFilter\LogicalFilter($callback);
            }

            if (!is_callable($callback)) {
                $this->throwUsageException(
                    "\$callback must be a logical filter description array or a callable"
                    ." instead of "
                    .var_export($callback, true)
                );
            }

            $out = [];
            foreach ($this->data as $key => $value) {
                if ($callback($value, $key)) {
                    $out[$key] = $value;
                    unset( $this->data[$key] );
                }
            }
        }

        return new static($out);
    }

    /**/
}
