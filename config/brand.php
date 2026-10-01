<?php

// Shared with the frontend and generated CSS/SCSS. No tenant settings live here.
return json_decode(file_get_contents(dirname(__DIR__).'/resources/brand/prodex.json'), true, 512, JSON_THROW_ON_ERROR);
