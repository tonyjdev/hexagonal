<?php

/*
| Tests de arranque de la aplicación.
|
| Son tests de Feature (integración): levantan Laravel completo y hacen
| peticiones HTTP simuladas, sin servidor real. Comprueban que el esqueleto
| creado en HEXM-0001 funciona.
*/

it('serves the home page', function () {
    // $this->get() simula una petición GET dentro de la aplicación.
    // $this es el TestCase de Laravel (configurado en tests/Pest.php).
    $this->get('/')->assertOk();
});

it('exposes a health check endpoint', function () {
    // /up es el endpoint de salud de Laravel: lo usarán los despliegues y
    // los balanceadores para saber si la aplicación está viva.
    $this->get('/up')->assertOk();
});
