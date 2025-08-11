<?php
// .env.php.example - Template pour le fichier de configuration
// Copier ce fichier vers .env.php et remplir avec vos vraies clés

return [
    // Clé secrète JWT fournie par Equipe pour décoder les tokens
    'EQUIPE_SECRET' => 'Your_Equipe_Extension_Secret_Key',
    
    // Token Bearer pour l'authentification avec l'API Hippodata
    'HIPPODATA_BEARER' => 'Hippodata_Bearer_Key',

    // DEBUG Inormation On/Off
    'DEBUG' => 1,

    // Version
    "VERSION" => "1.1.2",
];
