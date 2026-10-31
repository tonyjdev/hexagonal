<?php

/*
|--------------------------------------------------------------------------
| Configuración global de Pest
|--------------------------------------------------------------------------
|
| CONCEPTO: Pest frente a PHPUnit
| Pest no sustituye a PHPUnit: funciona por encima de él. Cada test de Pest se
| convierte internamente en un test de PHPUnit, así que el motor, las
| aserciones y phpunit.xml son los mismos. Lo que cambia es la sintaxis:
|
|     PHPUnit:  class HealthTest extends TestCase {
|                   public function test_it_responds(): void { ... }
|               }
|
|     Pest:     it('responds', function () { ... });
|
| Menos ceremonia: los tests se leen como frases ("it responds"), y se usa
| expect($valor)->toBe(...) en lugar de $this->assertSame(...).
|
| Pest añade además cosas que PHPUnit no tiene, como los tests de
| arquitectura (arch()), que usaremos en HEXM-0003 para comprobar que el
| dominio no depende de Laravel.
|
*/

use Tests\TestCase;

/*
| CONCEPTO: tests de Feature frente a tests de Unit
|
| - tests/Feature: arrancan la aplicación Laravel completa (contenedor de
|   servicios, rutas, base de datos...). Por eso extienden Tests\TestCase.
|   Son tests de integración: comprueban que las piezas encajan.
|
| - tests/Unit: no arrancan Laravel. Son rápidos y aislados. Aquí vivirán los
|   tests del DOMINIO hexagonal: como el dominio no depende del framework,
|   se puede probar sin él. Que un test de dominio necesite Laravel será una
|   señal de alarma de que algo está en la capa equivocada.
*/
pest()->extend(TestCase::class)->in('Feature');
