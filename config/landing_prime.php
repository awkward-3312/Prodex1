<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan destacado comercialmente (sección de planes de landing-prime)
    |--------------------------------------------------------------------------
    |
    | La sección de planes marca SIEMPRE este plan con el badge "Recomendado",
    | su color y su CTA especial. Es una decisión de MARKETING y es totalmente
    | INDEPENDIENTE de lo que sugiera la calculadora (PlanRecommendationService),
    | que sigue siendo dinámica y no toca el badge de esta sección.
    |
    | Se resuelve por SLUG del plan (identificador más estable que el id
    | autoincremental o la posición). Ajústalo por entorno con la variable
    | LANDING_PRIME_FEATURED_PLAN_SLUG si el slug de "Pyme" difiere.
    |
    | Si ningún plan público coincide con este slug, simplemente NINGUNA card
    | queda destacada (degradación segura, nunca se destaca la equivocada).
    |
    | Solución limpia a futuro: una columna `plans.is_featured` (boolean)
    | editable desde Superadmin. Requiere migración -> fuera del alcance de
    | esta ronda; este config es el puente estable y mantenible mientras tanto.
    |
    */

    'featured_plan_slug' => env('LANDING_PRIME_FEATURED_PLAN_SLUG', 'enterprise'),

];
