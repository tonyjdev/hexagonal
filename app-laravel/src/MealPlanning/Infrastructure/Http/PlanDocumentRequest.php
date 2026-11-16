<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;
use MealPlanning\Application\PlanDocument;

/**
 * PlanDocumentRequest: la subida de uno de los Excel de un plan (HEXM-0033).
 *
 * La comparten los tres endpoints que reciben un documento: empezar una
 * importación con el Excel de la nutricionista, sustituirlo y añadir (o
 * sustituir) el de la semana 2. Los tres esperan lo mismo: un campo `file`
 * en una petición multipart/form-data.
 *
 * CONCEPTO: validación de forma, no de contenido (ADR 0007)
 * Aquí solo se comprueba que llega un fichero, con extensión .xlsx y de un
 * tamaño razonable. Si el Excel tiene las hojas y las celdas esperadas no lo
 * sabe Laravel: lo decide el servicio importer al leerlo, y su respuesta
 * vuelve como UnreadableDocument (422 con cada problema, su hoja y su celda).
 * Repetir aquí esas comprobaciones sería duplicar el conocimiento del
 * formato en dos lenguajes.
 *
 * Por qué `extensions` y no `mimes`: `mimes` adivina el tipo por el
 * contenido, y un .xlsx es por dentro un ZIP, así que según la versión de
 * libmagic sale como application/zip. `extensions` mira el nombre, que basta
 * para cortar el error típico (subir el PDF o un .xls antiguo); el resto lo
 * dice el importador.
 *
 * El límite de 5 MB es de sentido común (los Excel de un plan pesan unos
 * KB): evita mandar al servicio ficheros enormes por error.
 *
 * CONCEPTO: el adaptador traduce el mundo de Laravel al del núcleo
 * toDocument() convierte el UploadedFile (una clase de Symfony/Laravel) en un
 * PlanDocument, un DTO de primitivos de MealPlanning\Application. Así los
 * casos de uso y el puerto PlanReader no conocen nada de HTTP (HEXM-0031).
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Http), adaptador de entrada.
 */
final class PlanDocumentRequest extends FormRequest
{
    /** Tamaño máximo del fichero, en KB (la unidad de la regla `max` para ficheros). */
    private const int MAX_KILOBYTES = 5 * 1024;

    public function authorize(): bool
    {
        // Sin autenticación todavía (fuera de alcance, como en el resto de la API).
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    public function toDocument(): PlanDocument
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('La validación ya ha comprobado que llega un único fichero en «file».');
        }

        return new PlanDocument(
            fileName: $file->getClientOriginalName(),
            contents: $file->getContent(),
        );
    }
}
