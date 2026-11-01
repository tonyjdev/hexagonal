<?php

declare(strict_types=1);

namespace Recipes\Domain;

/**
 * Step: un paso de preparación de una receta ("Sofreír la cebolla 5 minutos").
 *
 * CONCEPTO: Value Object
 * Un paso es solo su texto: dos pasos con el mismo texto son iguales. Se
 * normaliza al crearse (sin espacios al principio ni al final), de modo que
 * "  Batir los huevos " y "Batir los huevos" son el mismo paso.
 *
 * DECISIÓN: el paso no guarda su número de orden
 * El orden ("paso 3") no es una propiedad del paso, sino de su posición en la
 * lista de pasos de la receta. Si lo guardara aquí, al insertar un paso en
 * medio habría que renumerar todos los demás. Lo gestionará Recipe
 * (HEXM-0006) como una lista ordenada.
 *
 * CAPA: Dominio (Recipes\Domain).
 */
final readonly class Step
{
    private function __construct(private string $text) {}

    public static function fromString(string $text): self
    {
        $text = trim($text);

        if ($text === '') {
            throw InvalidStep::empty();
        }

        return new self($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function equals(self $other): bool
    {
        return $this->text === $other->text;
    }
}
