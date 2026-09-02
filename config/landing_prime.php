<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan destacado comercialmente (sección de planes de landing-prime)
    |--------------------------------------------------------------------------
    |
    | La sección de planes marca con el badge "Recomendado", su color y su CTA
    | especial al plan cuyo SLUG coincida con este valor. Es una decisión de
    | MARKETING y es totalmente INDEPENDIENTE de lo que sugiera la calculadora
    | (PlanRecommendationService), que sigue siendo dinámica y no toca el badge
    | de esta sección.
    |
    | Se resuelve SIEMPRE por slug — nunca por posición ni por id
    | autoincremental (esos no son estables entre entornos).
    |
    | DEFAULT = null a propósito: no se asume ningún mapping. Si no está
    | configurado, o si NINGÚN plan público coincide con el slug indicado,
    | simplemente NO se destaca ninguna card (degradación segura; nunca se
    | destaca el plan equivocado).
    |
    | Para activarlo hay que definirlo explícitamente por entorno:
    |
    |     LANDING_PRIME_FEATURED_PLAN_SLUG=<slug-del-plan>
    |
    | Slug de "Pyme" verificado por entorno:
    |   - LOCAL (stocky_saas @ 127.0.0.1): "enterprise"  (id 3, precio 599)
    |   - PRODUCCIÓN: sin verificar desde este entorno -> confírmalo en la
    |     tabla `plans` de prod antes de fijar el valor en el .env de prod.
    |
    | Solución limpia a futuro: una columna `plans.is_featured` (boolean)
    | editable desde Superadmin. Requiere migración -> fuera del alcance de
    | esta ronda; este config es el puente estable y mantenible mientras tanto.
    |
    */

    'featured_plan_slug' => env('LANDING_PRIME_FEATURED_PLAN_SLUG'),

];
