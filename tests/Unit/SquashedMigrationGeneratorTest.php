<?php

use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Table;

test('Generator produces valid PHP via stub, not heredoc', function () {
    $generator = new SquashedMigrationGenerator;

    $table = new Table(name: 'users');
    $table->addColumn(new Column(name: 'id', type: 'bigint', nullable: false, default: null, unsigned: true, autoIncrement: true));
    $table->addColumn(new Column(name: 'email', type: 'varchar', nullable: false, default: null, length: 255));
    $table->addColumn(new Column(name: 'name', type: 'varchar', nullable: true, default: null, length: 100));

    $code = $generator->generate($table, '2024_01_01_000000_create_users_table.php');

    // C-3: Must not contain heredoc syntax errors
    expect($code)->toContain("Schema::create('users'")
        ->and($code)->toContain('$table->id()')
        ->and($code)->toContain("\$table->string('email', 255)")
        ->and($code)->toContain("\$table->string('name', 100)")
        ->and($code)->toContain('->nullable()');

    // FR-6.6: Generated code must pass php -l
    $tempFile = sys_get_temp_dir().'/test_generated_'.uniqid().'.php';
    file_put_contents($tempFile, $code);

    exec("php -l {$tempFile} 2>&1", $output, $exitCode);
    @unlink($tempFile);

    expect($exitCode)->toBe(0, 'Generated PHP must be syntactically valid: '.implode("\n", $output));
});

test('Generator does not include foreign keys in table migration', function () {
    $generator = new SquashedMigrationGenerator;

    $table = new Table(name: 'posts');
    $table->addColumn(new Column(name: 'id', type: 'bigint', nullable: false, default: null, autoIncrement: true));
    $table->addColumn(new Column(name: 'user_id', type: 'bigint', nullable: false, default: null, unsigned: true));
    $table->addForeignKey(new ForeignKey(
        name: 'posts_user_id_foreign',
        column: 'user_id',
        referencedTable: 'users',
        referencedColumn: 'id',
    ));

    $code = $generator->generate($table, 'test.php');

    // D-3: FK should NOT be in the table migration
    expect($code)->not->toContain('->foreign(')
        ->and($code)->not->toContain('->references(');
});

test('Generator produces separate FK migration file', function () {
    $generator = new SquashedMigrationGenerator;

    $postsTable = new Table(name: 'posts');
    $postsTable->addForeignKey(new ForeignKey(
        name: 'posts_user_id_foreign',
        column: 'user_id',
        referencedTable: 'users',
        referencedColumn: 'id',
        onDelete: 'cascade',
    ));

    $fkCode = $generator->generateForeignKeyMigration(['posts' => $postsTable]);

    expect($fkCode)->not->toBeNull()
        ->and($fkCode)->toContain("Schema::table('posts'")
        ->and($fkCode)->toContain("->foreign('user_id')")
        ->and($fkCode)->toContain("->references('id')")
        ->and($fkCode)->toContain("->on('users')")
        ->and($fkCode)->toContain("->onDelete('cascade')");

    // FK migration must also pass php -l
    $tempFile = sys_get_temp_dir().'/test_fk_'.uniqid().'.php';
    file_put_contents($tempFile, $fkCode);

    exec("php -l {$tempFile} 2>&1", $output, $exitCode);
    @unlink($tempFile);

    expect($exitCode)->toBe(0, 'Generated FK PHP must be syntactically valid');
});

test('Generator returns null when no tables have foreign keys', function () {
    $generator = new SquashedMigrationGenerator;

    $table = new Table(name: 'users');

    $result = $generator->generateForeignKeyMigration(['users' => $table]);

    expect($result)->toBeNull();
});

test('Generator handles unsigned integer types correctly', function () {
    $generator = new SquashedMigrationGenerator;

    $table = new Table(name: 'products');
    $table->addColumn(new Column(name: 'price', type: 'integer', nullable: false, default: null, unsigned: true));
    $table->addColumn(new Column(name: 'stock', type: 'bigint', nullable: false, default: 0, unsigned: true));

    $code = $generator->generate($table, 'test.php');

    expect($code)->toContain("unsignedInteger('price')")
        ->and($code)->toContain("unsignedBigInteger('stock')");
});

test('Generator does not produce backslash-escaped quotes', function () {
    $generator = new SquashedMigrationGenerator;

    $table = new Table(name: 'items');
    $table->addColumn(new Column(name: 'id', type: 'bigint', nullable: false, default: null, autoIncrement: true));
    $table->addColumn(new Column(name: 'label', type: 'varchar', nullable: false, default: null));

    $code = $generator->generate($table, 'test.php');

    // M-12: Must not contain \' backslash-escaped quotes in column names
    expect($code)->not->toContain("\\'label\\'")
        ->and($code)->not->toContain("\\'items\\'");
});
