<?php

declare(strict_types=1);

namespace MealPlanning\Application;

/**
 * PlanDocument: un documento de un plan (uno de sus Excel) listo para leer:
 * su nombre y su contenido.
 *
 * CONCEPTO: el núcleo no conoce los tipos del framework
 * Quien sube el Excel lo recibe como un Illuminate\Http\UploadedFile (o lo
 * lee de disco un comando de Artisan). Ninguno de los dos puede entrar en el
 * núcleo, así que el adaptador de entrada lo convierte en este objeto con
 * dos textos: el nombre (para los mensajes de error y para la subida) y los
 * bytes del fichero. Es el mismo papel que un comando: transportar datos
 * hacia dentro con tipos de PHP.
 *
 * DECISIÓN: el contenido, no la ruta del fichero
 * Con una ruta, el puerto dependería de que el fichero siguiera en el disco
 * de esta máquina cuando el adaptador lo leyera. Con el contenido, cualquier
 * adaptador (HTTP, un lector local en PHP, una cola) lo tiene todo. Los Excel
 * de un plan ocupan unos pocos KB: llevarlos en memoria no cuesta nada.
 *
 * CAPA: Aplicación (MealPlanning\Application): entrada del puerto PlanReader.
 */
final readonly class PlanDocument
{
    public function __construct(
        public string $fileName,
        public string $contents,
    ) {}
}
