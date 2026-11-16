<?php

declare(strict_types=1);

namespace MealPlanning\Application;

use Shared\Domain\DomainException;

/**
 * UnreadableDocument: el documento de un plan no tiene la forma esperada y
 * no se puede leer.
 *
 * Falta una hoja, una cabecera no es la que toca, un día está repetido... o
 * se ha subido el Excel equivocado (el de la nutricionista como semana 2).
 * Quien lo detecta es el lector (en el servicio importer); el puerto
 * PlanReader lo cuenta con esta excepción, con todos los problemas.
 *
 * DECISIÓN: un error del usuario (422), no un fallo del sistema (ADR 0024)
 * El documento lo ha elaborado y subido el usuario, y lo que está mal es su
 * contenido: corregirlo y volver a subirlo lo arregla. Por eso hereda de
 * DomainException, como UnknownRecipe, y el manejador global lo convierte en
 * un 422 con el mensaje en español. Un servicio caído es otra cosa
 * (PlanReaderUnavailable, 503).
 *
 * DECISIÓN: el mensaje lleva todos los problemas, y problems() también
 * El manejador global solo devuelve {"message": …}, así que el mensaje los
 * enumera para que el usuario sepa qué corregir en una sola vuelta. Y
 * problems() los da uno a uno, por si una pantalla quiere mostrarlos en
 * lista (HEXM-0033). Cada problema ya trae su hoja y su celda («Hoja
 * «Detalle», celda B3: …»): así los escribe el servicio (ADR 0022).
 *
 * CAPA: Aplicación (MealPlanning\Application), junto a su puerto (como
 * UnknownRecipe junto a RecipeCatalog).
 */
final class UnreadableDocument extends DomainException
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(string $message, private readonly array $problems)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $problems  en español y con su ubicación; al menos uno
     */
    public static function withProblems(PlanDocument $document, array $problems): self
    {
        return new self(
            sprintf('El documento «%s» no tiene la forma esperada: %s', $document->fileName, implode(' · ', $problems)),
            $problems,
        );
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
