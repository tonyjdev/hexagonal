<?php

use Illuminate\Support\Facades\DB;

/*
| Test de la base de datos de los tests.
|
| CONCEPTO: probar contra el motor real
| Los tests se ejecutan contra PostgreSQL (base de datos `hexagonal_test`),
| el mismo motor que en desarrollo, y no contra SQLite en memoria, que es lo
| que Laravel trae por defecto. Así un test no puede pasar en SQLite y fallar
| en producción por diferencias de tipos, restricciones o funciones (y en el
| bloque C necesitaremos pgvector, que SQLite no tiene).
|
| Este test protege esa decisión: si alguien vuelve a cambiar phpunit.xml a
| SQLite, o apunta los tests a la base de datos de desarrollo, falla.
| Ver docs/adr/0002-base-de-datos-de-los-tests.md.
*/

it('runs against the PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('hexagonal_test');
});
