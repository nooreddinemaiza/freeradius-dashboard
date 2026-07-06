<?php

use Core\System\Environment;

$env = new Environment();

return [
    "name" => '<a class="" href="https://nooreddinemaiza.github.io" target="_blank" rel="noopener noreferrer">
                    <span style="color:rgb(0, 91, 150);">NM</span> 
                </a>
                <a class="" href="https://nooreddinemaiza.github.io/projects/nm-network-access-manager.html" target="_blank" rel="noopener noreferrer">
                    <span style="color:rgb(1, 31, 75);">Network Access Manager</span>
                </a>',
    "slogan" => $env->get("APP_SLOGAN", "Solution de portail captif moderne"),
    "url" => $env->get("APP_URL", ""),
    "logo_url" => '<a class="" href="https://nooreddinemaiza.github.io" target="_blank" rel="noopener noreferrer">
                    <img src="/Assets/images/logo.png" alt="">
                </a>',
    "captive_URL" => $env->get("CAP_URL", ""),
    "key" => $env->get("APP_key", ""),
    "max_nas" => (int)$env->get("MAX_NAS", 1),
    "env" => $env->get("APP_ENV", "production"),
];
