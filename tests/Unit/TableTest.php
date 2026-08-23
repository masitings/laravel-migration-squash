<?php

use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;

test('Table columns are mutable (not readonly)', function () {
    $table = new Table(name: 'users');

    $table->addColumn(new Column(
        name: 'id',
        type: 'bigint',
        nullable: false,
        default: null,
        autoIncrement: true,
    ));

    $table->addColumn(new Column(
        name: 'email',
        type: 'varchar',
        nullable: false,
        default: null,
    ));

    expect($table->columns)->toHaveCount(2)
        ->and($table->columns[0]->name)->toBe('id')
        ->and($table->columns[1]->name)->toBe('email');
});

test('Table indexes are mutable', function () {
    $table = new Table(name: 'users');

    $table->addIndex(new Index(
        name: 'users_email_unique',
        type: 'unique',
        columns: ['email'],
    ));

    expect($table->indexes)->toHaveCount(1)
        ->and($table->indexes[0]->type)->toBe('unique');
});

test('Table foreignKeys are mutable', function () {
    $table = new Table(name: 'posts');

    $table->addForeignKey(new ForeignKey(
        name: 'posts_user_id_foreign',
        column: 'user_id',
        referencedTable: 'users',
        referencedColumn: 'id',
    ));

    expect($table->foreignKeys)->toHaveCount(1)
        ->and($table->foreignKeys[0]->referencedTable)->toBe('users');
});

test('Table fromDb creates correct instance', function () {
    $columns = [
        ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'length' => null, 'unsigned' => true, 'collation' => null, 'auto_increment' => true],
        ['name' => 'name', 'type' => 'varchar', 'nullable' => false, 'default' => null, 'length' => 255, 'unsigned' => false, 'collation' => null, 'auto_increment' => false],
    ];

    $indexes = [
        ['key_name' => 'PRIMARY', 'index_type' => 'primary', 'columns' => ['id']],
    ];

    $foreignKeys = [];

    $table = Table::fromDb('users', $columns, $indexes, $foreignKeys);

    expect($table->name)->toBe('users')
        ->and($table->columns)->toHaveCount(2)
        ->and($table->indexes)->toHaveCount(1)
        ->and($table->foreignKeys)->toBeEmpty();
});

test('Table getColumn returns column by name or null', function () {
    $table = new Table(name: 'users');
    $table->addColumn(new Column(name: 'email', type: 'varchar', nullable: false, default: null));

    expect($table->getColumn('email'))->not->toBeNull()
        ->and($table->getColumn('nonexistent'))->toBeNull();
});
