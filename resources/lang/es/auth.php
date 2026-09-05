<?php

// Panel de /login del tenant (resources/views/auth/login.blade.php). Se usa
// como valor por defecto sensible al idioma para login_panel_title /
// login_panel_subtitle cuando el tenant no los ha personalizado respecto al
// valor sembrado (ver SettingsController + 2026_03_24_203803_create_settings_table).
return [
    'login_panel_title' => 'Iniciar sesión',
    'login_panel_subtitle' => 'Accede a tu panel y administra todo desde un solo lugar.',
];
