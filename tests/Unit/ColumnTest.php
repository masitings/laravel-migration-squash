<?php

use MigrationSquash\Schema\Column;

test('Column fromDb creates correct instance from normalized array', function () {
    $data = [
        'name' => 'email',
        'type' => 'varchar',
        'nullable' => false,
        'default' => null,
        'length' => 255,
        'unsigned' => false,
        'collation' => null,
        'auto_increment' => false,
    ];

    $column = Column::fromDb($data);

    expect($column->name)->toBe('email')
        ->and($column->type)->toBe('varchar')
        ->and($column->nullable)->toBeFalse()
        ->and($column->default)->toBeNull()
        ->and($column->length)->toBe(255)
        ->and($column->unsigned)->toBeFalse()
        ->and($column->autoIncrement)->toBeFalse();
});

test('Column autoIncrement is bool, not string', function () {
    $data = [
        'name' => 'id',
        'type' => 'bigint',
        'nullable' => false,
        'default' => null,
        'length' => null,
        'unsigned' => true,
        'collation' => null,
        'auto_increment' => true,
    ];

    $column = Column::fromDb($data);

    expect($column->autoIncrement)->toBeBool()
        ->and($column->autoIncrement)->toBeTrue();
});

test('Column nullable does not default to true when key is missing', function () {
    $data = [
        'name' => 'status',
        'type' => 'varchar',
        'nullable' => false,
        'default' => 'active',
        'length' => null,
        'unsigned' => false,
        'collation' => null,
        'auto_increment' => false,
    ];

    $column = Column::fromDb($data);

    expect($column->nullable)->toBeFalse();
});

test('Column equals compares all attributes', function () {
    $col1 = new Column(
        name: 'email',
        type: 'varchar',
        nullable: true,
        default: null,
        length: 255,
    );

    $col2 = new Column(
        name: 'email',
        type: 'varchar',
        nullable: true,
        default: null,
        length: 255,
    );

    $col3 = new Column(
        name: 'email',
        type: 'text',
        nullable: true,
        default: null,
    );

    expect($col1->equals($col2))->toBeTrue()
        ->and($col1->equals($col3))->toBeFalse();
});
