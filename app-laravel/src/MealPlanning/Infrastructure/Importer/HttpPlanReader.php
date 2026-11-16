<?php

declare(strict_types=1);

namespace MealPlanning\Infrastructure\Importer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use MealPlanning\Application\NutritionistPlanReading;
use MealPlanning\Application\PlanDocument;
use MealPlanning\Application\PlanReader;
use MealPlanning\Application\PlanReaderUnavailable;
use MealPlanning\Application\SelfWeekReading;
use MealPlanning\Application\UnreadableDocument;
use RuntimeException;

/**
 * HttpPlanReader: lee los documentos de un plan llamando al servicio
 * importer (Python + FastAPI) por HTTP.
 *
 * CONCEPTO: adaptador driven (de salida) hacia un servicio externo
 * Implementa el puerto PlanReader traduciendo en los dos sentidos:
 *   - hacia fuera: un PlanDocument se sube como multipart/form-data (campo
 *     `file`) a POST /nutritionist-plans/read o POST /self-weeks/read;
 *   - hacia dentro: el JSON de la respuesta pasa por ImporterResponse, que
 *     comprueba el contrato y construye las lecturas del puerto.
 * Para el núcleo es un adaptador más, como EloquentNutritionPlanRepository:
 * no sabe que hay un servicio Python, ni HTTP, ni JSON.
 *
 * CONCEPTO: traducción de errores (ADR 0024)
 * Cada cosa que puede salir mal fuera se convierte en un error del puerto:
 *
 *   | Lo que pasa                                  | Error del puerto       | HTTP |
 *   |----------------------------------------------|------------------------|------|
 *   | 422 del servicio: el Excel está mal          | UnreadableDocument     | 422  |
 *   | sin conexión o sin respuesta a tiempo        | PlanReaderUnavailable  | 503  |
 *   | 5xx u otro código inesperado del servicio    | PlanReaderUnavailable  | 503  |
 *   | 200 con un JSON que no cumple el contrato    | PlanReaderUnavailable  | 503  |
 *
 * El error técnico original nunca se pierde: va como $previous, para el log.
 *
 * CONCEPTO: el cliente HTTP de Laravel
 * Recibe la Factory del cliente (lo que hay detrás de la facade Http) por el
 * constructor, inyectada desde MealPlanningServiceProvider, en vez de llamar
 * a Http:: directamente: así se ve qué necesita la clase. En los tests,
 * Http::fake() sustituye las respuestas de esa misma Factory sin levantar el
 * servicio. Sin ->throw(), el cliente no lanza excepciones por un 4xx o un
 * 5xx: devuelve la respuesta y aquí se decide qué significa cada código.
 *
 * CAPA: Infraestructura (MealPlanning\Infrastructure\Importer).
 */
final readonly class HttpPlanReader implements PlanReader
{
    private const string XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  string  $baseUrl  la URL del servicio (config/services.php, IMPORTER_URL)
     * @param  float  $timeout  segundos que se espera la respuesta (IMPORTER_TIMEOUT)
     */
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private float $timeout,
    ) {}

    public function readNutritionistPlan(PlanDocument $document): NutritionistPlanReading
    {
        $response = $this->upload('/nutritionist-plans/read', $document);

        return $this->translate(fn (): NutritionistPlanReading => ImporterResponse::nutritionistPlan($response->json()));
    }

    public function readSelfWeek(PlanDocument $document): SelfWeekReading
    {
        $response = $this->upload('/self-weeks/read', $document);

        return $this->translate(fn (): SelfWeekReading => ImporterResponse::selfWeek($response->json()));
    }

    /**
     * Sube el documento y devuelve la respuesta si es un 2xx; cualquier otra
     * cosa la traduce a un error del puerto.
     */
    private function upload(string $route, PlanDocument $document): Response
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                // timeout(): cuánto se espera la respuesta entera. Leer un
                // Excel tarda milisegundos; si no llega en este tiempo, el
                // servicio está atascado y es mejor decirlo que esperar.
                ->timeout($this->timeout)
                ->acceptJson()
                // attach() convierte la petición en multipart/form-data, lo
                // que espera el UploadFile de FastAPI (como curl -F file=@…).
                ->attach('file', $document->contents, $document->fileName, ['Content-Type' => self::XLSX])
                ->post($route);
        } catch (ConnectionException $exception) {
            // Servicio caído, nombre que no resuelve, conexión rechazada o
            // timeout: Laravel los reúne todos en ConnectionException.
            throw PlanReaderUnavailable::unreachable($exception);
        }

        if ($response->status() === 422) {
            throw UnreadableDocument::withProblems(
                $document,
                $this->translate(fn (): array => ImporterResponse::problems($response->json())),
            );
        }

        if (! $response->successful()) {
            throw PlanReaderUnavailable::unexpectedResponse(new RuntimeException(sprintf(
                'El importador respondió %d a POST %s: %s',
                $response->status(),
                $route,
                mb_substr($response->body(), 0, 500),
            )));
        }

        return $response;
    }

    /**
     * Ejecuta una traducción del JSON y convierte un contrato roto en un
     * error del puerto.
     *
     * @template T
     *
     * @param  callable(): T  $translation
     * @return T
     */
    private function translate(callable $translation): mixed
    {
        try {
            return $translation();
        } catch (InvalidImporterResponse $exception) {
            throw PlanReaderUnavailable::unexpectedResponse($exception);
        }
    }
}
