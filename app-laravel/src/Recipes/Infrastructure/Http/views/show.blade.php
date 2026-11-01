{{--
| Página web de una receta: GET /recipes/{id} (HEXM-0016).
|
| CONCEPTO: la presentación es responsabilidad del adaptador
| Recibe un Recipes\Application\RecipeView (el MISMO DTO de lectura que la
| API convierte en JSON con RecipeResource) y decide cómo se ve en HTML. La
| vista solo lee datos ya calculados: la cantidad legible ("1 ½") la escribió
| el dominio (Quantity::amountAsText()) y la etiqueta de la unidad ("bote")
| también (Unit::label()). Aquí no hay ni una regla de negocio.
|
| DECISIÓN: tabla sin concordancia de número (HEXM-0016)
| Unit::label() da el nombre en singular, y "200 gramo" se leería mal. En una
| tabla con la columna "Unidad", la unidad funciona como rótulo y no necesita
| concordar con la cantidad. Pluralizar bien ("200 gramos", "2 cucharadas
| soperas") es conocimiento del negocio y le corresponde al dominio: queda
| registrado como tech_debt. Por el mismo motivo se escribe "Raciones: 2" y
| no "2 raciones".
|
| CONCEPTO: escapado automático de Blade
| {{ }} escapa el HTML: si el nombre de una receta contuviera "<script>", se
| mostraría como texto y no se ejecutaría (protección frente a XSS).
|
| Se usa como view('recipes::show'): el prefijo "recipes::" es el namespace
| que registra RecipesServiceProvider con loadViewsFrom().
|
| @var \Recipes\Application\RecipeView $recipe
--}}
<x-layout :title="$recipe->name">
    <h1>{{ $recipe->name }}</h1>
    <p class="meta">Raciones: {{ $recipe->servings }}</p>

    <h2>Ingredientes</h2>
    <table>
        <thead>
            <tr>
                <th scope="col">Ingrediente</th>
                <th scope="col">Cantidad</th>
                <th scope="col">Unidad</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($recipe->ingredients as $ingredient)
                <tr>
                    <td>{{ $ingredient->name }}</td>
                    @if ($ingredient->toTaste)
                        <td class="to-taste" colspan="2">al gusto</td>
                    @else
                        <td class="amount">{{ $ingredient->amount }}</td>
                        <td>{{ $ingredient->unitLabel }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Preparación</h2>
    {{-- Regla de negocio 12: los pasos son opcionales. --}}
    @if ($recipe->steps === [])
        <p class="empty">Esta receta no detalla pasos de preparación.</p>
    @else
        <ol>
            @foreach ($recipe->steps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    @endif
</x-layout>
