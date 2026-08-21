<?php

namespace MigrationSquash\Discovery;

class TableGrouping
{
    /**
     * Group migrations by table and resolve dependencies
     * 
     * @param array<int, array{file: string, timestamp: string, name: string}> $migrations
     * @return array<string, array{table: string, migrations: array<int, array{file: string, timestamp: string}>, dependsOn: array<int, string>}>
     */
    public function group(array $migrations): array
    {
        $scanner = new MigrationScanner();
        $migrationFiles = array_column($migrations, 'file');
        
        $tableInfo = $scanner->extractTableInfo($migrationFiles);
        
        // Build map of table -> migrations
        $groups = [];
        
        foreach ($tableInfo as $migrationName => $info) {
            foreach ($info['tables'] as $tableName => $operation) {
                if (! isset($groups[$tableName])) {
                    $groups[$tableName] = [
                        'table' => $tableName,
                        'migrations' => [],
                        'dependsOn' => [],
                    ];
                }
                
                $found = false;
                foreach ($migrations as $migration) {
                    if (basename($migration['file']) === $migrationName) {
                        $groups[$tableName]['migrations'][] = [
                            'file' => $migration['file'],
                            'timestamp' => $migration['timestamp'],
                        ];
                        $found = true;
                        break;
                    }
                }
            }
        }
        
        return $groups;
    }

    /**
     * Resolve table creation order using topological sort based on foreign key dependencies
     * 
     * @param array<string, mixed> $groups
     * @return array<string, array{
     *     table: string,
     *     migrations: array<int, array{file: string, timestamp: string}>,
     *     deferredForeignKeys?: array<string, array<int, string>>
     * }>
     */
    public function resolveOrder(array $groups): array
    {
        // Build adjacency list for dependency graph
        $graph = [];
        $allTables = array_keys($groups);
        
        foreach ($allTables as $table) {
            $graph[$table] = [];
        }
        
        // For now, assume no dependencies (will be enhanced with FK analysis)
        // This is a simplified version - full implementation would need
        // introspection of foreign keys from sandbox
        
        // Topological sort (Kahn's algorithm)
        $inDegree = array_fill_keys($allTables, 0);
        $adjacency = $graph;
        
        foreach ($adjacency as $from => $toList) {
            foreach ($toList as $to) {
                $inDegree[$to]++;
            }
        }
        
        $queue = array_filter($allTables, fn($t) => $inDegree[$t] === 0);
        $sorted = [];
        
        while (! empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;
            
            foreach ($adjacency[$current] ?? [] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }
        
        // Detect cycles
        if (count($sorted) !== count($allTables)) {
            // Circular dependency detected
            return $this->handleCircularDependencies($groups, $sorted);
        }
        
        // Reorder groups according to sorted order
        $orderedGroups = [];
        foreach ($sorted as $table) {
            if (isset($groups[$table])) {
                $orderedGroups[$table] = $groups[$table];
            }
        }
        
        return $orderedGroups;
    }

    /**
     * Handle tables with circular foreign key dependencies
     */
    protected function handleCircularDependencies(array $groups, array $sorted): array
    {
        // Tables not in sorted list have circular dependencies
        $circularTables = array_diff(array_keys($groups), $sorted);
        
        if (empty($circularTables)) {
            return $groups;
        }
        
        // Mark these tables to have their foreign keys added separately
        foreach ($circularTables as $table) {
            if (isset($groups[$table])) {
                $groups[$table]['deferredForeignKeys'] = $circularTables;
            }
        }
        
        return $groups;
    }
}
