<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuración base de Pest
|--------------------------------------------------------------------------
|
| Suites (ARQUITECTURA_MAESTRA §11):
|   Unit          dominio puro: costeo, cálculo de cuenta, promociones, redondeos
|   Feature       casos de uso por endpoint e integración evento → listener
|   Architecture  reglas estructurales: scopes de tenant, fronteras de módulos
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Architecture', 'Concurrency');

/*
|--------------------------------------------------------------------------
| Base de datos en pruebas
|--------------------------------------------------------------------------
|
| Las pruebas corren contra MySQL 8 (base `comandia_testing`), no contra SQLite
| en memoria. Es más lento a propósito: este proyecto tiene que verificar FKs
| reales, DECIMAL exacto, colación acento-insensible y locks de foliación, y
| ninguna de esas cosas se comporta igual en SQLite. Prioridad del proyecto:
| correctitud > velocidad de desarrollo (ESPECIFICACION_MAESTRA §1).
|
| Decisión D60 en docs/REGISTRO_DECISIONES.md.
|
*/

pest()->use(RefreshDatabase::class)->in('Feature');

/*
| La suite `Concurrency` NO usa `RefreshDatabase`, y ésa es su razón de existir.
|
| `RefreshDatabase` envuelve cada prueba en una transacción, y una transacción hace los
| datos invisibles para cualquier OTRA conexión. Así que la herramienta que aísla las
| pruebas es exactamente la que impide probar un lock entre dos conexiones: el kardex
| congela `balance_after` y eso exige serializar escrituras, pero con todo dentro de una
| transacción no hay nada que serializar.
|
| Estas pruebas hacen COMMIT de verdad y limpian lo suyo a mano. Son pocas y lentas a
| propósito: sólo van aquí las que no se pueden escribir de otra forma.
*/

/*
|--------------------------------------------------------------------------
| Expectativas propias
|--------------------------------------------------------------------------
|
| Aquí se agregarán las expectativas de dominio conforme se construyan las
| iteraciones (por ejemplo, invariantes financieras y de aislamiento de tenant).
|
*/
