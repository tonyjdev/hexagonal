<?php

declare(strict_types=1);

namespace MealPlanning\Application;

/**
 * PlanReader: lee los documentos de un plan nutricional.
 *
 *     «aquí tienes el Excel de la nutricionista (o el de la semana 2):
 *      dime qué contiene»
 *
 * Un plan llega en dos documentos (DOMINIO.md, nivel 7): el Excel de la
 * nutricionista (número, pautas fijas, intercambios y semana 1) y el Excel
 * de la semana 2 que elabora el usuario. Cada uno tiene su formato, y por
 * eso el puerto tiene una lectura para cada uno.
 *
 * CONCEPTO: un servicio externo como adaptador detrás de un puerto
 * Hoy quien lee los Excel es un servicio Python (services/importer, con
 * FastAPI), y el adaptador que habla con él es HttpPlanReader. Pero el
 * núcleo no lo sabe: solo conoce esta interfaz, escrita en su idioma
 * (documentos y lecturas, no peticiones ni JSON). El día que el PDF se lea
 * directamente con un LLM (nivel 15) o que el lector se reescriba en PHP,
 * solo cambiará el adaptador. Es la misma inversión de dependencias que con
 * la base de datos, y la razón de ser de la arquitectura hexagonal:
 *
 *     caso de uso ──► PlanReader ◄── HttpPlanReader ──HTTP──► FastAPI
 *                                ◄── InMemoryPlanReader (tests)
 *
 * DECISIÓN: el puerto vive en Application, no en Domain (ADR 0024)
 * Como RecipeCatalog y Clock (ADR 0017, ADR 0012): leer un fichero externo
 * es orquestación que solo harán los casos de uso de importación
 * (HEXM-0032, HEXM-0033); el dominio no hace preguntas al exterior.
 *
 * DECISIÓN: dos métodos, uno por formato
 * Igual que el servicio tiene una ruta por formato (ADR 0023): quien sube el
 * fichero sabe cuál es, y adivinarlo sería otra forma de fallar. Cada método
 * devuelve su propia lectura, con su forma.
 *
 * Errores que forman parte del contrato (los cumplen todos los adaptadores):
 *   - UnreadableDocument: el documento no tiene la forma esperada (422);
 *   - PlanReaderUnavailable: el lector no puede leer ahora (503).
 *
 * CAPA: Aplicación (MealPlanning\Application): puerto de salida.
 */
interface PlanReader
{
    /**
     * Lee el Excel de la nutricionista. Una consulta: no guarda nada (CQS).
     *
     * @throws UnreadableDocument si el documento no tiene la forma esperada
     * @throws PlanReaderUnavailable si el lector no está disponible
     */
    public function readNutritionistPlan(PlanDocument $document): NutritionistPlanReading;

    /**
     * Lee el Excel de la semana 2 (días 8-14). Una consulta: no guarda nada.
     *
     * @throws UnreadableDocument si el documento no tiene la forma esperada
     * @throws PlanReaderUnavailable si el lector no está disponible
     */
    public function readSelfWeek(PlanDocument $document): SelfWeekReading;
}
