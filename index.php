<?php
// index.php - Point d'entrée principal de l'extension

require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Charger les variables d'environnement depuis .env.php
if (file_exists(__DIR__ . '/.env.php')) {
    $env = include __DIR__ . '/.env.php';
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Allow cookie to be set in iframe
header("Access-Control-Allow-Origin: https://app.equipe.com");

// Décoder la requête
$decoded = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Requête POST - lire le body
    $decoded = json_decode(file_get_contents('php://input'));
    
} else {
    // Requête GET - décoder le JWT
    
    // Get secret token from .env.php
    $key = $_ENV['EQUIPE_SECRET'] ?? '';
    
    // Get token from URL
    $jwt = $_GET['token'] ?? '';
    
    if (!empty($jwt)) {
        // Decode token
        JWT::$leeway = 60; // $leeway in seconds
        try {
            $decoded = JWT::decode($jwt, new Key($key, 'HS256'));
        } catch (Exception $e) {
            http_response_code(400);
            die("Erreur token: " . $e->getMessage());
        }
    }
}

// À ce stade, $decoded contient les données décodées (POST ou GET)

// Vérifier le mode debug
$debugMode = isset($_ENV['DEBUG']) && $_ENV['DEBUG'] == '1';

// Traiter les requêtes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    // check exists
    if ($_POST['action'] === 'get_imported_status') {
        $meetingUrl = $_POST['meeting_url'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        
        if (empty($meetingUrl) || empty($apiKey)) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit;
        }
        
        try {
            $existing = [
                'classes' => [],
                'startlists' => [],
                'results' => []
            ];
            
            // Charger les compétitions existantes
            $compUrl = rtrim($meetingUrl, '/') . '/competitions.json';
            $ch = curl_init($compUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: {$apiKey}",
                "Accept: application/json"
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);
            
            foreach ($data ?? [] as $c) {
                if (!empty($c['foreignid'])) {
                    $foreignId = $c['foreignid'];
                    $existing['classes'][] = $foreignId;
                    
                    // Tester l'existence d'une startlist pour cette classe
                    $startUrl = rtrim($meetingUrl, '/') . "/competitions/{$foreignId}/starts.json";
                    $ch = curl_init($startUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "X-Api-Key: {$apiKey}",
                        "Accept: application/json"
                    ]);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                    $starts = json_decode($resp, true);
                    if (!empty($starts) && is_array($starts)) {
                        $existing['startlists'][] = $foreignId;
                    }

                    // Tester l'existence de résultats pour cette classe
                    $resultsUrl = rtrim($meetingUrl, '/') . "/competitions/{$foreignId}/H/results.json";
                    $ch = curl_init($resultsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "X-Api-Key: {$apiKey}",
                        "Accept: application/json"
                    ]);
                    $resp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        $results = json_decode($resp, true);
                        // Vérifier qu'il y a des résultats avec des données de round 1
                        $hasActualResults = false;
                        if (!empty($results) && is_array($results)) {
                            foreach ($results as $result) {
                                // Vérifier la présence de grundf ou grundt (Round 1 data)
                                if (isset($result['grundf']) || isset($result['grundt'])) {
                                    $hasActualResults = true;
                                    break;
                                }
                            }
                        }
                        if ($hasActualResults) {
                            $existing['results'][] = $foreignId;
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'existing' => $existing]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        exit;
    }
    // Nouvelle action pour récupérer les infos de l'event
    if ($_POST['action'] === 'fetch_event_info') {
        $showId = $_POST['show_id'] ?? '';
        
        if (empty($showId)) {
            echo json_encode(['success' => false, 'error' => 'Show ID is required']);
            exit;
        }
        
        try {
            // Récupérer les données depuis Hippodata
            $hippodataUrl = "https://api.hippo-server.net/scoring/event/{$showId}";
            
            $ch = curl_init($hippodataUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . ($_ENV['HIPPODATA_BEARER'] ?? ''),
                "Accept: application/json"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception("Failed to fetch data from Hippodata (HTTP {$httpCode})");
            }
            
            $hippodataData = json_decode($response, true);
            
            // Transformer les données pour l'affichage
            $classes = [];
            
            foreach ($hippodataData['CLASSES']['CLASS'] ?? [] as $class) {
                $name = !empty($class['NAME']) ? $class['NAME'] : $class['SPONSOR'];
                
                $classes[] = [
                    'id' => $class['ID'],
                    'nr' => $class['NR'] ?? $class['ID'],
                    'name' => $name,
                    'date' => $class['DATE'],
                    'category' => $class['CATEGORY'] ?? '',
                    'prize_money' => $class['PRIZE']['MONEY'] ?? 0,
                    'prize_currency' => $class['PRIZE']['CURRENCY'] ?? 'EUR',
                    'status' => $class['STATUS'] ?? 'unknown'
                ];
            }
            
            echo json_encode([
                'success' => true,
                'event' => [
                    'id' => $hippodataData['EVENT']['ID'] ?? $showId,
                    'name' => $hippodataData['EVENT']['CAPTION'] ?? '',
                    'venue' => $hippodataData['EVENT']['LOCATION'] ?? ''
                ],
                'classes' => $classes
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Action modifiée pour importer sélectivement
    if ($_POST['action'] === 'import_selected') {
        $showId = $_POST['show_id'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $meetingUrl = $_POST['meeting_url'] ?? '';
        $selections = json_decode($_POST['selections'] ?? '[]', true);
        
        if (empty($showId) || empty($selections)) {
            echo json_encode(['success' => false, 'error' => 'Show ID and selections are required']);
            exit;
        }
        
        try {
            $results = [
                'classes' => [],
                'startlists' => [],
                'results' => []
            ];
            
            // 1. D'abord, récupérer toutes les données de l'event
            $hippodataUrl = "https://api.hippo-server.net/scoring/event/{$showId}";
            
            $ch = curl_init($hippodataUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . ($_ENV['HIPPODATA_BEARER'] ?? ''),
                "Accept: application/json"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception("Failed to fetch event data (HTTP {$httpCode})");
            }
            
            $hippodataData = json_decode($response, true);
            $hippodataClasses = [];
            foreach ($hippodataData['CLASSES']['CLASS'] ?? [] as $class) {
                $hippodataClasses[$class['ID']] = $class;
            }
            
            // 2. Traiter chaque sélection
            $classesToImport = [];
            $startlistsToProcess = [];
            $resultsToProcess = [];
            $counter = 1;
            
            foreach ($selections as $selection) {
                $classId = $selection['class_id'];
                $classData = $hippodataClasses[$classId] ?? null;
                
                if (!$classData) continue;
                
                // Si import class est sélectionné
                if ($selection['import_class']) {
                    $name = !empty($classData['NAME']) ? $classData['NAME'] : $classData['SPONSOR'];
                    
                    $classToImport = [
                        'foreign_id' => (string)$classData['ID'],
                        'clabb' => 'HD-' . $counter,
                        'klass' => $name,
                        'oeverskr1' => $name,
                        'datum' => $classData['DATE'],
                        'tavlingspl' => $classData['CATEGORY'] ?? '',
                        'z' => 'H',
                        'x' => 'I',
                        'alias'=> true,
                        'premie_curr' => $classData['PRIZE']['CURRENCY'] ?? 'EUR',
                        'prsum1' => $classData['PRIZE']['MONEY'] ?? 0
                    ];
                    
                    // Ajouter l'article FEI si spécifié
                    if (!empty($selection['fei_article'])) {
                        $classToImport['fei_article'] = $selection['fei_article'];
                    }
                    
                    // Ajouter le flag team_class si coché
                    if ($selection['team_class']) {
                        $classToImport['team_class'] = true;
                    }
                    
                    $classesToImport[] = $classToImport;
                    $counter++;
                    
                    $results['classes'][] = [
                        'foreign_id' => $classData['ID'],
                        'class_id' => $classData['NR'] ?? $classData['ID'],
                        'name' => $name,
                        'status' => 'pending'
                    ];
                }
                
                // Stocker les infos pour les imports de startlists et résultats
                if ($selection['import_startlist']) {
                    $startlistsToProcess[] = [
                        'foreign_id' => $classData['ID'],
                        'class_id' => $classData['NR'] ?? $classData['ID'],
                        'name' => !empty($classData['NAME']) ? $classData['NAME'] : $classData['SPONSOR']
                    ];
                }
                
                if ($selection['import_results']) {
                    $resultsToProcess[] = [
                        'foreign_id' => $classData['ID'],
                        'class_id' => $classData['NR'] ?? $classData['ID'],
                        'name' => !empty($classData['NAME']) ? $classData['NAME'] : $classData['SPONSOR']
                    ];
                }
            }
            
            // 3. Importer les classes si nécessaire
            if (!empty($classesToImport)) {
                $batchData = [
                    'competitions' => [
                        'unique_by' => 'foreign_id',
                        'skip_user_changed' => true,
                        'records' => $classesToImport
                    ]
                ];
                
                $transactionUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                
                $batchUrl = $meetingUrl . '/batch';
                
                $ch = curl_init($batchUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($batchData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "X-Api-Key: {$apiKey}",
                    "X-Transaction-Uuid: {$transactionUuid}",
                    "Accept: application/json",
                    "Content-Type: application/json"
                ]);
                
                $equipeResponse = curl_exec($ch);
                $equipeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                // Mettre à jour les statuts
                foreach ($results['classes'] as &$class) {
                    $class['status'] = ($equipeHttpCode === 200 || $equipeHttpCode === 201) ? 'success' : 'failed';
                }
            }
            
            // Retourner les résultats avec les listes à traiter
            echo json_encode([
                'success' => true,
                'results' => $results,
                'event_id' => $hippodataData['EVENT']['ID'] ?? $showId,
                'startlists_to_process' => $startlistsToProcess,
                'results_to_process' => $resultsToProcess
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Action pour envoyer un batch à Equipe (proxy pour éviter CORS)
    if ($_POST['action'] === 'send_batch_to_equipe') {
        $batchData = json_decode($_POST['batch_data'] ?? '{}', true);
        $apiKey = $_POST['api_key'] ?? '';
        $meetingUrl = $_POST['meeting_url'] ?? '';
        $transactionUuid = $_POST['transaction_uuid'] ?? '';
        
        if (empty($batchData) || empty($apiKey) || empty($meetingUrl)) {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit;
        }
        
        try {
            $batchUrl = $meetingUrl . '/batch';
            
            $ch = curl_init($batchUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($batchData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: {$apiKey}",
                "X-Transaction-Uuid: {$transactionUuid}",
                "Accept: application/json",
                "Content-Type: application/json"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                echo json_encode([
                    'success' => true,
                    'response' => json_decode($response, true),
                    'httpCode' => $httpCode
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => "HTTP {$httpCode}" . ($curlError ? " - {$curlError}" : ""),
                    'response' => $response,
                    'httpCode' => $httpCode
                ]);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Action pour importer les startlists
    if ($_POST['action'] === 'import_startlists') {
        if ($debugMode) {
            error_log("Import startlists action triggered");
        }
        
        $eventId = $_POST['event_id'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $meetingUrl = $_POST['meeting_url'] ?? '';
        $competitions = json_decode($_POST['competitions'] ?? '[]', true);
        
        if ($debugMode) {
            error_log("Event ID: " . $eventId);
            error_log("Meeting URL: " . $meetingUrl);
            error_log("Competitions count: " . count($competitions));
        }
        
        if (empty($eventId) || empty($competitions)) {
            echo json_encode(['success' => false, 'error' => 'Event ID and competitions are required']);
            exit;
        }
        
        try {
            // 1. Récupérer la liste des personnes existantes dans Equipe
            $existingPeople = [];
            $existingPeopleFeiIds = [];
            
            $ch = curl_init($meetingUrl . '/people.json');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: {$apiKey}", "Accept: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Si people.json existe et retourne 200
            if ($httpCode === 200 && $response) {
                $people = json_decode($response, true);
                if (is_array($people)) {
                    foreach ($people as $person) {
                        // Stocker par foreign_id et par fei_id
                        if (isset($person['foreign_id'])) {
                            $existingPeople[$person['foreign_id']] = $person;
                        }
                        if (isset($person['fei_id'])) {
                            $existingPeopleFeiIds[$person['fei_id']] = $person;
                        }
                    }
                }
            }
            if ($debugMode) {
                error_log("Found " . count($existingPeople) . " existing people");
            }
            
            // 2. Récupérer la liste des chevaux existants dans Equipe
            $existingHorses = [];
            $existingHorsesFeiIds = [];
            
            $ch = curl_init($meetingUrl . '/horses.json');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: {$apiKey}", "Accept: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Si horses.json existe et retourne 200
            if ($httpCode === 200 && $response) {
                $horses = json_decode($response, true);
                if (is_array($horses)) {
                    foreach ($horses as $horse) {
                        // Stocker par foreign_id et par fei_id
                        if (isset($horse['foreign_id'])) {
                            $existingHorses[$horse['foreign_id']] = $horse;
                        }
                        if (isset($horse['fei_id'])) {
                            $existingHorsesFeiIds[$horse['fei_id']] = $horse;
                        }
                    }
                }
            }
            if ($debugMode) {
                error_log("Found " . count($existingHorses) . " existing horses");
            }
            
            $allBatchData = [];
            $processedCompetitions = [];
            
            // 3. Pour chaque compétition, récupérer la startlist
            foreach ($competitions as $comp) {
                $classId = $comp['class_id'];
                $competitionForeignId = $comp['foreign_id'];
                
                if ($debugMode) {
                    error_log("Processing competition: " . $comp['name'] . " (class_id: " . $classId . ")");
                }
                
                // Récupérer la startlist depuis Hippodata
                $url = "https://api.hippo-server.net/scoring/event/{$eventId}/startlist/{$classId}/all";
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . ($_ENV['HIPPODATA_BEARER'] ?? ''),
                    "Accept: application/json"
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    if ($debugMode) {
                        error_log("Failed to fetch startlist for class $classId (HTTP $httpCode)");
                    }
                    $processedCompetitions[] = [
                        'name' => $comp['name'],
                        'foreign_id' => $competitionForeignId,
                        'people_count' => 0,
                        'horses_count' => 0,
                        'starts_count' => 0,
                        'error' => "Failed to fetch startlist (HTTP $httpCode)"
                    ];
                    continue;
                }
                
                $startlistData = json_decode($response, true);
                
                if (!isset($startlistData['CLASS']['COMPETITORS']['COMPETITOR'])) {
                    if ($debugMode) {
                        error_log("No competitors found for class $classId");
                    }
                    $processedCompetitions[] = [
                        'name' => $comp['name'],
                        'foreign_id' => $competitionForeignId,
                        'people_count' => 0,
                        'horses_count' => 0,
                        'starts_count' => 0,
                        'error' => "No competitors in startlist"
                    ];
                    continue;
                }
                
                $newPeople = [];
                $newHorses = [];
                $starts = [];
                
                // Traiter chaque concurrent
                $competitors = $startlistData['CLASS']['COMPETITORS']['COMPETITOR'];
                
                // S'assurer que c'est un tableau d'arrays
                if (isset($competitors['RIDER'])) {
                    // Un seul concurrent, le mettre dans un tableau
                    $competitors = [$competitors];
                }
                
                foreach ($competitors as $competitor) {
                    $rider = $competitor['RIDER'] ?? [];
                    $horse = $competitor['HORSE'] ?? [];
                    
                    // Vérifier et préparer les données du cavalier
                    $riderFeiId = $rider['RFEI_ID'] ?? null;
                    if ($riderFeiId && !isset($existingPeople[$riderFeiId]) && !isset($existingPeopleFeiIds[$riderFeiId])) {
                        // Parser le nom
                        $nameParts = explode(',', $rider['RNAME'] ?? '');
                        $lastName = trim($nameParts[0] ?? '');
                        $firstName = trim($nameParts[1] ?? '');
                        
                        $newPeople[] = [
                            'foreign_id' => $riderFeiId,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'country' => $rider['NATION'] ?? '',
                            'fei_id' => $riderFeiId
                        ];
                        
                        // Marquer comme existant pour éviter les doublons dans ce batch
                        $existingPeople[$riderFeiId] = true;
                        $existingPeopleFeiIds[$riderFeiId] = true;
                    }
                    
                    // Vérifier et préparer les données du cheval
                    $horseFeiId = $horse['HFEI_ID'] ?? null;
                    if ($horseFeiId && !isset($existingHorses[$horseFeiId]) && !isset($existingHorsesFeiIds[$horseFeiId])) {
                        $horseInfo = $horse['HORSEINFO'] ?? [];
                        
                        // Mapper le sexe
                        $gender = strtolower($horseInfo['GENDER'] ?? '');
                        $sexMap = [
                            'm' => 'val', // Male/Stallion
                            'g' => 'val', // Gelding
                            'f' => 'sto', // Female/Mare
                            'mare' => 'sto',
                            'stallion' => 'hin',
                            'gelding' => 'val'
                        ];
                        $sex = $sexMap[$gender] ?? 'val';
                        
                        $newHorses[] = [
                            'foreign_id' => $horseFeiId,
                            'num' => $horse['HNR'] ?? '',
                            'name' => $horse['HNAME'] ?? '',
                            'sex' => $sex,
                            'born_year' => (string)($horseInfo['BORNYEAR'] ?? ''),
                            'owner' => $horseInfo['OWNER'] ?? '',
                            'category' => 'H',
                            'fei_id' => $horseFeiId
                        ];
                        
                        // Marquer comme existant pour éviter les doublons dans ce batch
                        $existingHorses[$horseFeiId] = true;
                        $existingHorsesFeiIds[$horseFeiId] = true;
                    }
                    
                    // Préparer la start entry
                    if ($riderFeiId && $horseFeiId) {
                        $sortOrder = $competitor['SORTROUND']['ROUND1'] ?? $competitor['SORTORDER'] ?? 0;
                        $starts[] = [
                            'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId,
                            'st' => (string)$sortOrder,
                            'ord' => (int)$sortOrder,
                            'rider' => [
                                'foreign_id' => $riderFeiId
                            ],
                            'horse' => [
                                'foreign_id' => $horseFeiId
                            ]
                        ];
                    }
                }
                
                if ($debugMode) {
                    error_log("Competition " . $comp['name'] . ": " . count($newPeople) . " new people, " . count($newHorses) . " new horses, " . count($starts) . " starts");
                }
                
                // Préparer le batch data pour cette compétition
                $batchData = [];
                
                if (!empty($newPeople)) {
                    $batchData['people'] = [
                        'unique_by' => 'foreign_id',
                        'records' => $newPeople
                    ];
                }
                
                if (!empty($newHorses)) {
                    $batchData['horses'] = [
                        'unique_by' => 'foreign_id',
                        'records' => $newHorses
                    ];
                }
                
                if (!empty($starts)) {
                    $batchData['starts'] = [
                        'unique_by' => 'foreign_id',
                        'where' => [
                            'competition' => [
                                'foreign_id' => $competitionForeignId
                            ]
                        ],
                        'abort_if_any' => [
                            'rid' => true
                        ],
                        'replace' => true,
                        'records' => $starts
                    ];
                }
                
                if (!empty($batchData)) {
                    $allBatchData[] = [
                        'competition' => $comp['name'],
                        'competition_foreign_id' => $competitionForeignId,
                        'data' => $batchData,
                        'details' => [
                            'people' => $newPeople,
                            'horses' => $newHorses,
                            'starts' => $starts
                        ]
                    ];
                }
                
                $processedCompetitions[] = [
                    'name' => $comp['name'],
                    'foreign_id' => $competitionForeignId,
                    'people_count' => count($newPeople),
                    'horses_count' => count($newHorses),
                    'starts_count' => count($starts),
                    'people' => array_map(function($p) {
                        return $p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['country'] . ')';
                    }, $newPeople),
                    'horses' => array_map(function($h) {
                        return $h['name'] . ' - ' . $h['fei_id'];
                    }, $newHorses)
                ];
            }
            
            if ($debugMode) {
                error_log("Total processed: " . count($processedCompetitions) . " competitions");
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Startlists ready for import',
                'processedCompetitions' => $processedCompetitions,
                'batchData' => $allBatchData
            ]);
            
        } catch (Exception $e) {
            if ($debugMode) {
                error_log("Exception in import_startlists: " . $e->getMessage());
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Action pour importer les résultats
    if ($_POST['action'] === 'import_results') {
        if ($debugMode) {
            error_log("Import results action triggered");
        }
        
        $eventId = $_POST['event_id'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $meetingUrl = $_POST['meeting_url'] ?? '';
        $competitions = json_decode($_POST['competitions'] ?? '[]', true);
        
        if ($debugMode) {
            error_log("Event ID: " . $eventId);
            error_log("Meeting URL: " . $meetingUrl);
            error_log("Competitions count: " . count($competitions));
        }
        
        if (empty($eventId) || empty($competitions)) {
            echo json_encode(['success' => false, 'error' => 'Event ID and competitions are required']);
            exit;
        }
        
        try {
            $allBatchData = [];
            $processedCompetitions = [];
            
            // Pour chaque compétition, récupérer les résultats
            foreach ($competitions as $comp) {
                $classId = $comp['class_id'];
                $competitionForeignId = $comp['foreign_id'];
                
                if ($debugMode) {
                    error_log("Processing results for competition: " . $comp['name'] . " (class_id: " . $classId . ")");
                }
                
                // Récupérer les résultats depuis Hippodata
                $url = "https://api.hippo-server.net/scoring/event/{$eventId}/resultlist/{$classId}";
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . ($_ENV['HIPPODATA_BEARER'] ?? ''),
                    "Accept: application/json"
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    if ($debugMode) {
                        error_log("Failed to fetch results for class $classId (HTTP $httpCode)");
                    }
                    $processedCompetitions[] = [
                        'name' => $comp['name'],
                        'foreign_id' => $competitionForeignId,
                        'results_count' => 0,
                        'error' => "Failed to fetch results (HTTP $httpCode)"
                    ];
                    continue;
                }
                
                $resultsData = json_decode($response, true);
                
                if (!isset($resultsData['CLASS']['COMPETITORS']['COMPETITOR'])) {
                    if ($debugMode) {
                        error_log("No results found for class $classId");
                    }
                    $processedCompetitions[] = [
                        'name' => $comp['name'],
                        'foreign_id' => $competitionForeignId,
                        'results_count' => 0,
                        'error' => "No results in resultlist"
                    ];
                    continue;
                }
                
                // Préparer les données de temps autorisé pour la compétition
                $competitionUpdate = [];
                if (isset($resultsData['CLASS']['TIME1_ALLOWED'])) {
                    $competitionUpdate['grundt'] = (int)$resultsData['CLASS']['TIME1_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME2_ALLOWED'])) {
                    $competitionUpdate['omh1t'] = (int)$resultsData['CLASS']['TIME2_ALLOWED'];
                }
                // Ajouter d'autres temps autorisés si disponibles dans l'API
                if (isset($resultsData['CLASS']['TIME3_ALLOWED'])) {
                    $competitionUpdate['omh2t'] = (int)$resultsData['CLASS']['TIME3_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME4_ALLOWED'])) {
                    $competitionUpdate['omg3t'] = (int)$resultsData['CLASS']['TIME4_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME5_ALLOWED'])) {
                    $competitionUpdate['omg4t'] = (int)$resultsData['CLASS']['TIME5_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME6_ALLOWED'])) {
                    $competitionUpdate['omg5t'] = (int)$resultsData['CLASS']['TIME6_ALLOWED'];
                }
                
                $results = [];
                
                // Traiter chaque concurrent
                $competitors = $resultsData['CLASS']['COMPETITORS']['COMPETITOR'];
                
                // S'assurer que c'est un tableau d'arrays
                if (isset($competitors['RIDER'])) {
                    // Un seul concurrent, le mettre dans un tableau
                    $competitors = [$competitors];
                }
                
                foreach ($competitors as $competitor) {
                    $rider = $competitor['RIDER'] ?? [];
                    $horse = $competitor['HORSE'] ?? [];
                    
                    $riderFeiId = $rider['RFEI_ID'] ?? null;
                    $horseFeiId = $horse['HFEI_ID'] ?? null;
                    
                    if (!$riderFeiId || !$horseFeiId) {
                        continue; // Skip si pas d'identifiants
                    }
                    
                    // Préparer le résultat
                    $result = [
                        'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId,
                        'rider' => ['foreign_id' => $riderFeiId],
                        'horse' => ['foreign_id' => $horseFeiId],
                        'rid' => true,
                        'result_at' => date('Y-m-d H:i:s'),
                        'last_result_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Traiter les résultats par round
                    $resultDetails = $competitor['RESULT'] ?? [];
                    $resultTotal = $competitor['RESULTTOTAL'][0] ?? [];
                    
                    // Initialiser les valeurs par défaut
                    $result['ord'] = (int)($competitor['SORTORDER'] ?? 1000);
                    $result['st'] = (string)($competitor['SORTORDER'] ?? '1');
                    
                    // Mapper les résultats selon les rounds
                    foreach ($resultDetails as $roundResult) {
                        $round = $roundResult['ROUND'] ?? 0;
                        $faults = (float)($roundResult['FAULTS'] ?? 0);
                        $time = (float)($roundResult['TIME'] ?? 0);
                        $timeFaults = (float)($roundResult['TIMEFAULTS'] ?? 0);
                        
                        switch ($round) {
                            case 1: // Round 1
                                $result['grundf'] = $faults;
                                $result['grundt'] = $time;
                                $result['tfg'] = $timeFaults;
                                // Si c'est une compétition par équipe, on peut ajouter round1_in_team
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round1_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                                
                            case 2: // Round 2 (Jump-off ou 2ème manche)
                                $result['omh1f'] = $faults;
                                $result['omh1t'] = $time;
                                $result['tf1'] = $timeFaults;
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round2_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                                
                            case 3: // Round 3
                                $result['omh2f'] = $faults;
                                $result['omh2t'] = $time;
                                $result['tf2'] = $timeFaults;
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round3_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                                
                            case 4: // Round 4
                                $result['omg3f'] = $faults;
                                $result['omg3t'] = $time;
                                $result['tf3'] = $timeFaults;
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round4_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                                
                            case 5: // Round 5
                                $result['omg4f'] = $faults;
                                $result['omg4t'] = $time;
                                $result['tf4'] = $timeFaults;
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round5_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                                
                            case 6: // Round 6
                                $result['omg5f'] = $faults;
                                $result['omg5t'] = $time;
                                $result['tf5'] = $timeFaults;
                                if (isset($roundResult['IN_TEAM'])) {
                                    $result['round6_in_team'] = (float)$roundResult['IN_TEAM'];
                                }
                                break;
                        }
                    }
                    
                    // Ajouter le rang et les prix
                    if (isset($resultTotal['RANK'])) {
                        $result['re'] = (int)$resultTotal['RANK'];
                    }
                    
                    if (isset($resultTotal['PRIZE']['MONEY'])) {
                        $result['premie'] = (float)$resultTotal['PRIZE']['MONEY'];
                        $result['premie_show'] = (float)$resultTotal['PRIZE']['MONEY'];
                    }
                    
                    // Traiter les prix en nature
                    if (isset($resultTotal['PRIZE']['TEXT']) && !isset($resultTotal['PRIZE']['MONEY'])) {
                        // Si on a un texte de prix mais pas de montant, c'est peut-être un prix en nature
                        $result['rtxt'] = $resultTotal['PRIZE']['TEXT'];
                        $result['premie'] = 0;
                        $result['premie_show'] = 0;
                    }
                    
                    // Traiter les états spéciaux (retraité, éliminé, disqualifié)
                    $status = $resultTotal['STATUS'] ?? 1;
                    $state = $competitor['STATE'] ?? 0;
                    
                    // Vérifier si le cavalier a été éliminé, retraité, etc.
                    // En se basant sur le STATUS et d'autres indicateurs
                    if ($status != 1 || $state != 0) {
                        // Chercher dans tous les rounds pour trouver où l'élimination s'est produite
                        foreach ($resultDetails as $roundResult) {
                            if (isset($roundResult['STATUS']) && $roundResult['STATUS'] != 1) {
                                // Le cavalier a eu un problème dans ce round
                                $roundStatus = $roundResult['STATUS'];
                                
                                // Mapper les statuts Hippodata vers Equipe
                                // Ces mappings peuvent nécessiter des ajustements selon la documentation exacte
                                if ($roundStatus == 2 || stripos($roundResult['NAME'] ?? '', 'retired') !== false) {
                                    $result['or'] = 'U'; // Retired/Abandonné
                                } elseif ($roundStatus == 3 || stripos($roundResult['NAME'] ?? '', 'eliminated') !== false) {
                                    $result['or'] = 'D'; // Eliminated/Éliminé
                                } elseif ($roundStatus == 4 || stripos($roundResult['NAME'] ?? '', 'disqualified') !== false) {
                                    $result['or'] = 'S'; // Disqualified/Disqualifié
                                }
                                break;
                            }
                        }
                    }
                    
                    // Vérifier les non-partants
                    if (!isset($resultDetails[0]) || count($resultDetails) == 0) {
                        // Pas de résultats du tout, peut-être non-partant
                        if ($state == 1) {
                            $result['a'] = 'Ö'; // Withdrawn/Forfait
                        } elseif ($state == 2) {
                            $result['a'] = 'U'; // No-show/Non-partant
                        }
                    }
                    
                    // Total des fautes
                    $result['totfel'] = (float)($resultTotal['FAULTS'] ?? 0);
                    
                    // Ajouter des valeurs par défaut pour les champs manquants
                    $result['k'] = 'H'; // Type: H pour Horse
                    $result['av'] = 'A'; // Valeur par défaut
                    
                    $results[] = $result;
                }
                
                if ($debugMode) {
                    error_log("Competition " . $comp['name'] . ": " . count($results) . " results");
                }
                
                // Préparer le batch data pour cette compétition
                $batchData = [];
                
                // Mise à jour des temps autorisés de la compétition
                if (!empty($competitionUpdate)) {
                    $competitionUpdate['foreign_id'] = $competitionForeignId;
                    $batchData['competitions'] = [
                        'unique_by' => 'foreign_id',
                        'records' => [$competitionUpdate]
                    ];
                }
                
                // Ajouter les résultats
                if (!empty($results)) {
                    $batchData['starts'] = [
                        'unique_by' => 'foreign_id',
                        'where' => [
                            'competition' => [
                                'foreign_id' => $competitionForeignId
                            ]
                        ],
                        'replace' => true,
                        'records' => $results
                    ];
                }
                
                if (!empty($batchData)) {
                    $allBatchData[] = [
                        'competition' => $comp['name'],
                        'competition_foreign_id' => $competitionForeignId,
                        'data' => $batchData
                    ];
                }
                
                $processedCompetitions[] = [
                    'name' => $comp['name'],
                    'foreign_id' => $competitionForeignId,
                    'results_count' => count($results),
                    'time_allowed' => $competitionUpdate['grundt'] ?? null,
                    'time_allowed_jumpoff' => $competitionUpdate['omh1t'] ?? null,
                    'time_allowed_round3' => $competitionUpdate['omh2t'] ?? null,
                    'time_allowed_round4' => $competitionUpdate['omg3t'] ?? null,
                    'time_allowed_round5' => $competitionUpdate['omg4t'] ?? null,
                    'time_allowed_round6' => $competitionUpdate['omg5t'] ?? null,
                    'rounds' => $resultsData['CLASS']['ROUNDS'] ?? [],
                    'status' => $resultsData['CLASS']['STATUS'] ?? 'unknown'
                ];
            }
            
            if ($debugMode) {
                error_log("Total processed: " . count($processedCompetitions) . " competitions");
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Results ready for import',
                'processedCompetitions' => $processedCompetitions,
                'batchData' => $allBatchData
            ]);
            
        } catch (Exception $e) {
            if ($debugMode) {
                error_log("Exception in import_results: " . $e->getMessage());
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Affichage pour le mode modal/browser
if ($decoded && isset($decoded->payload->target)) {
    if ($decoded->payload->target == "modal" || $decoded->payload->target == "browser") {
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Extension Equipe - Hippodata</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($decoded->payload->style_url ?? ''); ?>">
    <link rel="stylesheet" href="css/custom.css?version=<?php echo rand();?>">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">


</head>
<body class="extension">
    <div class="import-container">
        <h3>Import from Hippodata</h3>
        
        <div id="alertMessage" style="display: none;"></div>
        
        <!-- Étape 1: Recherche de l'event -->
        <div id="searchStep">
            <form id="searchForm">
                <div class="form-group">
                    <label for="showId">Show ID (FEI Event ID):</label>
                    <input type="text" id="showId" name="show_id" class="form-control" placeholder="Enter FEI Event ID" required>
                </div>
                
                <button type="submit" class="btn btn-primary" id="searchButton">
                    Search Event
                </button>
            </form>
        </div>
        
        <!-- Étape 2: Sélection des classes -->
        <div id="selectionStep" style="display: none;">
            <div id="eventInfo"></div>
            <!-- Ajouter après <div id="eventInfo"></div> -->
            <div id="loading-status" class="text-center my-3" style="display: none;">
                <i class="fa fa-spinner fa-spin fa-2x text-primary"></i>
                <p>Checking import status...</p>
            </div>
            <table class="classes-table" id="classesTable">
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Date</th>
                        <th>Class Import</th>
                        <th>Startlist Import</th>
                        <th>Result Import</th>
                        <th>Team Class</th>
                        <th>FEI Article</th>
                    </tr>
                    <tr class="select-all-row">
                        <td colspan="2">Select All →</td>
                        <td><input type="checkbox" id="selectAllClasses"></td>
                        <td><input type="checkbox" id="selectAllStartlists"></td>
                        <td><input type="checkbox" id="selectAllResults"></td>
                        <td><input type="checkbox" id="selectAllTeam"></td>
                        <td>
                            <select id="selectAllArticle">
                                <option value="">-- Select --</option>
                                <option style="background: #2980b9; color: #ffffff;" disabled>238.1</option>
                                <option value="238.1.1">Table A. Not against the clock (238.1.1)</option>
                                <option value="238.1.1">Table A. Not against the clock with jump off not atc (238.1.1)</option>
                                <option value="238.1.2">Table A. One round not atc with one jump-off atc (238.1.2)</option>
                                <option value="238.1.3">Table A. Not against the clock with jump off (238.1.3)</option>
                                <option value="238.1.3">Table A. One round with two jump-offs (238.1.3)</option>
                                <option style="background: #2980b9; color: #ffffff;" disabled>238.2</option>
                                <option value="238.2.1">Table A. Against the clock, no jump off (238.2.1)</option>
                                <option value="238.2.1b">Table A. One round against the clock equalty of faults and time jump off (238.2.1b)</option>
                                <option value="238.2.2">Table A. One round with one jump off (238.2.2)</option>
                                <option value="238.2.2 + 245.3">Table A against the clock with Immediate jump off (238.2.2 + 245.3)</option>
                                <option value="238.2.3">Table A. One round with two jump-offs (238.2.3)</option>
                                <option style="background: #2980b9; color: #ffffff;" disabled>other</option>
                                <option value="239">Table C. Against the clock (239)</option>
                                <option value="262.2, 262.3">Five rounds, not atc (262.2, 262.3)</option>
                                <option value="263">Table C. Against the clock (263)</option>
                                <option value="266">Fault and Out (266)</div>
                                <option value="267">Hit and Hurry (267)</div>
                                <option value="268.2.1">Relay competition - Table C (268.2.1)</div>
                                <option value="269">Accumulator against the clock (269)</div>
                                <option value="269.4">Accumulator with a jump off (269.4)</div>
                                <option value="270">Top score competition (270)</div>
                                <option value="271">Take-your-own-line (271)</div>
                                <option value="275">Competition in groups against the clock (275)</div>
                                <option style="background: #2980b9; color: #ffffff;" disabled>2 Rounds</option>
                                <option value="273.3.1, 4.1">Table A. 1st round against the clock - 2nd round not against the clock, jump off against the clock (273.3.1, 4.1)</div>
                                <option value="273.3.2, 4.2">Table A. Two rounds not against the clock, jump off (273.3.2, 4.2)</div>
                                <option value="273.3.3.1">Table A. Two rounds, both against the clock (273.3.3.1)</div>
                                <option value="273.3.4.1">Table A. Two rounds, both against the clock, with jump-off (273.3.4.1)</div>
                                <option value="273.4.3a">Table A. Two rounds, 2nd round against the clock (273.4.3a)</div>
                                <option value="273.4.3b">Table A. Two rounds, both against the clock (273.4.3b)</div>
                                <option value="273.4.4">Table A. Two rounds aggregated, with jump off atc (273.4.4)</div>
                                <option value="276.1">Two rounds and a Winning Round (276.1)</div>
                                <option value="276.2">One round and a winning round with 0 points (276.2)</div>
                                <option value="276.3">One round and a winning round (276.3)</div>
                                <option style="background: #2980b9; color: #ffffff;" disabled>2 Phases</option>
                                <option value="274.1.5.1">Table A. Two phases not against the clock (274.1.5.1)</div>
                                <option value="274.1.5.2">Table A. Two phases, the second against the clock (274.1.5.2)</div>
                                <option value="274.1.5.3">Table A. Two phases, both against the clock (274.1.5.3)</div>
                                <option value="274.1.5.4">Table A and C. Two phases. First phase not atc and second atc (274.1.5.4)</div>
                                <option value="274.2.5">Special Two Phase Competition (274.2.5)</div>
                                <option value="282.4.5">Table A. Two phases. Against the clock and table C (282.4.5)</div>
                                <option style="background: #2980b9; color: #ffffff;" disabled>Nations Cup / Team Class</option>
                                <option value="264.10.2">Nations Cup (264.10.2)</div>
                                <option value="264.10.3">Nations Cup (264.10.3)</div>
                                <option value="264.10.3">Nations Cup (1 round against the clock) (264.10.3)</div>
                                <option value="264.10.3">Nations Cup (1 round against the clock and jump-off -all riders) (264.10.3)</div>
                                <option value="264.10.4">Nations Cup (order 2nd round based on penalties only) (264.10.4)</div>
                                <option value="264.10.7">Nations Cup (264.1.7)</div>
                                <option value="265.2+273.3.3.2">Other Team Competition - two rounds without a jump-off (265.2+273.3.3.2)</div>
                            </select>
                        </td>
                    </tr>
                </thead>
                <tbody id="classesTableBody">
                    <!-- Classes will be populated here -->
                </tbody>
            </table>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-secondary" id="backButton">Back</button>
                <button type="button" class="btn btn-primary" id="importSelectedButton">Import Selected</button>
            </div>
        </div>
        
        <!-- Étape 3: Résultats -->
        <div id="resultsStep" style="display: none;">
            <h4>Import Progress</h4>
            <div id="importProgress"></div>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-primary" id="newImportButton">New Import</button>
            </div>
        </div>
    </div>
    
    <script>
        const debugMode = <?php echo $debugMode ? 'true' : 'false'; ?>;
        let currentEventData = null;
        let currentSelections = [];
        let importOptions = {
            classes: false,
            startlists: false,
            results: false
        };
        
        function debugLog(...args) {
            if (debugMode) {
                console.log(...args);
            }
        }
        
        $(document).ready(function() {
            // Recherche de l'event
            $('#searchForm').on('submit', function(e) {
                e.preventDefault();
                
                const showId = $('#showId').val();
                const button = $('#searchButton');
                const alertDiv = $('#alertMessage');
                
                alertDiv.hide();
                button.prop('disabled', true).text('Searching...');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'fetch_event_info',
                        show_id: showId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            currentEventData = response;
                            displayEventInfo(response);
                            $('#searchStep').hide();
                            $('#selectionStep').show();
                        } else {
                            alertDiv.removeClass('alert-success').addClass('alert alert-danger');
                            alertDiv.text('Error: ' + response.error).show();
                        }
                    },
                    error: function(xhr, status, error) {
                        alertDiv.removeClass('alert-success').addClass('alert alert-danger');
                        alertDiv.text('Request failed: ' + error).show();
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Search Event');
                    }
                });
            });
            
            // Afficher les infos de l'event
            // Afficher les infos de l'event
            function displayEventInfo(data) {
                $('#eventInfo').html(
                    '<h4>' + data.event.name + '</h4>' +
                    '<p><strong>Event ID:</strong> ' + data.event.id + '</p>' +
                    '<p><strong>Venue:</strong> ' + data.event.venue + '</p>'
                );
                
                const tbody = $('#classesTableBody');
                tbody.empty();
                
                // Afficher le spinner
                $('#loading-status').show();
                
                // Vérifier le statut des imports existants
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'get_imported_status',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        const imported = response.existing || { classes: [], startlists: [], results: [] };
                        
                        data.classes.forEach(function(cls, index) {
                            const row = $('<tr>');
                            const foreignId = cls.id.toString();
                            
                            // Vérifier si déjà importé
                            const isClassImported = imported.classes.includes(foreignId);
                            const isStartlistImported = imported.startlists.includes(foreignId);
                            const isResultImported = imported.results.includes(foreignId);
                            
                            row.append('<td>' + cls.nr + ' ' + cls.name + '</td>');
                            row.append('<td>' + cls.date + '</td>');
                            
                            // Class import
                            row.append('<td>' + (isClassImported ? 
                                '<i class="fa-solid fa-circle-check" title="Already imported"></i>' : 
                                '<input type="checkbox" class="class-import" data-class-id="' + cls.id + '" data-index="' + index + '">') + '</td>');
                            
                            // Startlist import
                            row.append('<td>' + (isStartlistImported ? 
                                '<i class="fa-solid fa-circle-check" title="Already imported"></i>' : 
                                '<input type="checkbox" class="startlist-import" data-class-id="' + cls.id + '" data-index="' + index + '" ' + 
                                (!isClassImported ? 'title="Import class first"' : '') + '>') + '</td>');
                            
                            // Result import
                            row.append('<td>' + (isResultImported ? 
                                '<i class="fa-solid fa-circle-check" title="Already imported"></i>' : 
                                '<input type="checkbox" class="result-import" data-class-id="' + cls.id + '" data-index="' + index + '" ' + 
                                (!isClassImported ? 'title="Import class first"' : '') + '>') + '</td>');
                            
                            // Team class - toujours disponible
                            row.append('<td><input type="checkbox" class="team-class" data-class-id="' + cls.id + '" data-index="' + index + '"></td>');
                            
                            // FEI Article - toujours disponible
                            row.append('<td><select class="fei-article" data-class-id="' + cls.id + '" data-index="' + index + '">' + $('#selectAllArticle').html() + '</select></td>');
                            
                            tbody.append(row);
                        });
                    },
                    error: function() {
                        // En cas d'erreur, afficher quand même le tableau sans statut
                        data.classes.forEach(function(cls, index) {
                            const row = $('<tr>');
                            
                            row.append('<td>' + cls.nr + ' ' + cls.name + '</td>');
                            row.append('<td>' + cls.date + '</td>');
                            row.append('<td><input type="checkbox" class="class-import" data-class-id="' + cls.id + '" data-index="' + index + '"></td>');
                            row.append('<td><input type="checkbox" class="startlist-import" data-class-id="' + cls.id + '" data-index="' + index + '"></td>');
                            row.append('<td><input type="checkbox" class="result-import" data-class-id="' + cls.id + '" data-index="' + index + '"></td>');
                            row.append('<td><input type="checkbox" class="team-class" data-class-id="' + cls.id + '" data-index="' + index + '"></td>');
                            row.append('<td><select class="fei-article" data-class-id="' + cls.id + '" data-index="' + index + '">' + $('#selectAllArticle').html() + '</select></td>');
                            
                            tbody.append(row);
                        });
                    },
                    complete: function() {
                        // Masquer le spinner
                        $('#loading-status').hide();
                    }
                });
            }
            // Gérer l'activation/désactivation des checkboxes basée sur les dépendances
            $(document).on('change', '.class-import', function() {
                const classId = $(this).data('class-id');
                const isChecked = $(this).is(':checked');
                const row = $(this).closest('tr');
                
                // Si on décoche la classe, décocher aussi startlist et results
                if (!isChecked) {
                    row.find('.startlist-import, .result-import').prop('checked', false);
                }
            });
            // Gestion des "Select All"
            $('#selectAllClasses').on('change', function() {
                $('.class-import').prop('checked', $(this).is(':checked'));
            });
            
            $('#selectAllStartlists').on('change', function() {
                $('.startlist-import').prop('checked', $(this).is(':checked'));
            });
            
            $('#selectAllResults').on('change', function() {
                $('.result-import').prop('checked', $(this).is(':checked'));
            });
            
            $('#selectAllTeam').on('change', function() {
                $('.team-class').prop('checked', $(this).is(':checked'));
            });
            
            $('#selectAllArticle').on('change', function() {
                $('.fei-article').val($(this).val());
            });
            
            // Bouton retour
            $('#backButton').on('click', function() {
                $('#selectionStep').hide();
                $('#searchStep').show();
            });
            
            // Import des sélections
            $('#importSelectedButton').on('click', function() {
                const selections = [];
                
                $('#classesTableBody tr').each(function(index) {
                    const row = $(this);
                    const classData = currentEventData.classes[index];
                    
                    // Vérifier d'abord si les checkboxes existent (pas remplacées par des coches)
                    const classCheckbox = row.find('.class-import');
                    const startlistCheckbox = row.find('.startlist-import');
                    const resultCheckbox = row.find('.result-import');
                    
                    const selection = {
                        class_id: classData.id,
                        class_nr: classData.nr,
                        class_name: classData.name,
                        import_class: classCheckbox.length > 0 && classCheckbox.is(':checked'),
                        import_startlist: startlistCheckbox.length > 0 && startlistCheckbox.is(':checked'),
                        import_results: resultCheckbox.length > 0 && resultCheckbox.is(':checked'),
                        team_class: row.find('.team-class').is(':checked'),
                        fei_article: row.find('.fei-article').val()
                    };
                    
                    // Ajouter seulement si au moins une option est sélectionnée
                    if (selection.import_class || selection.import_startlist || selection.import_results) {
                        selections.push(selection);
                    }
                });
                
                if (selections.length === 0) {
                    alert('Please select at least one import option');
                    return;
                }
                
                currentSelections = selections;
                startImport();
            });
            
            // Démarrer l'import
            function startImport() {
                $('#selectionStep').hide();
                $('#resultsStep').show();
                $('#importProgress').html('<div class="progress-section"><p>Starting import process...</p></div>');
                
                // Déterminer ce qui doit être importé
                importOptions.classes = currentSelections.some(s => s.import_class);
                importOptions.startlists = currentSelections.some(s => s.import_startlist);
                importOptions.results = currentSelections.some(s => s.import_results);
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_selected',
                        show_id: $('#showId').val(),
                        selections: JSON.stringify(currentSelections),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            displayImportResults(response);
                            
                            // Collecter toutes les startlists à traiter (même si pas de classes importées)
                            const allStartlistsToProcess = [];
                            const allResultsToProcess = [];
                            
                            currentSelections.forEach(function(sel) {
                                if (sel.import_startlist) {
                                    allStartlistsToProcess.push({
                                        foreign_id: sel.class_id,
                                        class_id: sel.class_nr,
                                        name: sel.class_name
                                    });
                                }
                                if (sel.import_results) {
                                    allResultsToProcess.push({
                                        foreign_id: sel.class_id,
                                        class_id: sel.class_nr,
                                        name: sel.class_name
                                    });
                                }
                            });
                            
                            // Si on a des startlists à traiter
                            if (allStartlistsToProcess.length > 0) {
                                setTimeout(function() {
                                    processStartlists(response.event_id, allStartlistsToProcess);
                                }, 1000);
                            } else if (allResultsToProcess.length > 0) {
                                // Si on a seulement des résultats à traiter
                                setTimeout(function() {
                                    processResults(response.event_id, allResultsToProcess);
                                }, 1000);
                            }
                        } else {
                            $('#importProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#importProgress').html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
                    }
                });
            }
            
            // Afficher les résultats de l'import
            function displayImportResults(data) {
                let html = '<div class="progress-section">';
                
                if (data.results.classes && data.results.classes.length > 0) {
                    html += '<h5>Classes Import Results:</h5>';
                    data.results.classes.forEach(function(cls) {
                        const statusClass = cls.status === 'success' ? 'success' : 'failed';
                        html += '<div class="progress-item ' + statusClass + '">';
                        html += cls.name + ' - <strong>' + cls.status + '</strong>';
                        html += '</div>';
                    });
                }
                
                html += '</div>';
                $('#importProgress').html(html);
            }
            
            // Traiter les startlists
            function processStartlists(eventId, startlistsToProcess) {
                $('#importProgress').append('<div class="progress-section"><h5>Processing Startlists...</h5><div id="startlistProgress"></div></div>');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_startlists',
                        event_id: eventId,
                        competitions: JSON.stringify(startlistsToProcess),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            displayStartlistResults(response);
                            
                            // Si on a des données à envoyer vers Equipe
                            if (response.batchData && response.batchData.length > 0) {
                                importBatchesToEquipe(response.batchData, function() {
                                    // Après les startlists, traiter les résultats si nécessaire
                                    if (importOptions.results) {
                                        const resultsToProcess = currentSelections
                                            .filter(s => s.import_results)
                                            .map(s => ({
                                                foreign_id: s.class_id,
                                                class_id: s.class_nr,
                                                name: s.class_name
                                            }));
                                        
                                        if (resultsToProcess.length > 0) {
                                            setTimeout(function() {
                                                processResults(eventId, resultsToProcess);
                                            }, 1000);
                                        }
                                    }
                                });
                            }
                        } else {
                            $('#startlistProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#startlistProgress').html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
                    }
                });
            }
            
            // Afficher les résultats des startlists
            function displayStartlistResults(response) {
                let html = '';
                
                if (response.processedCompetitions) {
                    response.processedCompetitions.forEach(function(comp) {
                        html += '<div class="progress-item pending" id="startlist-' + comp.foreign_id + '">';
                        html += '<strong>' + comp.name + '</strong>: ';
                        html += comp.people_count + ' riders, ' + comp.horses_count + ' horses, ' + comp.starts_count + ' starts';
                        html += '</div>';
                    });
                }
                
                $('#startlistProgress').html(html);
            }
            
            // Traiter les résultats
            function processResults(eventId, resultsToProcess) {
                $('#importProgress').append('<div class="progress-section"><h5>Processing Results...</h5><div id="resultsProgress"></div></div>');
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_results',
                        event_id: eventId,
                        competitions: JSON.stringify(resultsToProcess),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            displayResultsProgress(response);
                            
                            // Si on a des données à envoyer vers Equipe
                            if (response.batchData && response.batchData.length > 0) {
                                importResultsBatchesToEquipe(response.batchData);
                            }
                        } else {
                            $('#resultsProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#resultsProgress').html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
                    }
                });
            }
            
            // Afficher les résultats
            function displayResultsProgress(response) {
                let html = '';
                
                if (response.processedCompetitions) {
                    response.processedCompetitions.forEach(function(comp) {
                        html += '<div class="progress-item pending" id="result-' + comp.foreign_id + '">';
                        html += '<strong>' + comp.name + '</strong>: ';
                        html += comp.results_count + ' results';
                        
                        if (comp.time_allowed) {
                            html += ' (Time allowed: ' + comp.time_allowed + 's)';
                        }
                        
                        html += '</div>';
                    });
                }
                
                $('#resultsProgress').html(html);
            }
            
            // Importer les batches vers Equipe
            function importBatchesToEquipe(batchDataArray, onCompleteCallback) {
                const alertDiv = $('#alertMessage');
                let importResults = [];
                
                // D'abord, consolider tous les people et horses de tous les batches
                let allPeople = [];
                let allHorses = [];
                let competitionStarts = []; // Stocker les starts par compétition
                
                // Collecter toutes les données
                batchDataArray.forEach(function(batch) {
                    if (batch.data.people && batch.data.people.records) {
                        allPeople = allPeople.concat(batch.data.people.records);
                    }
                    if (batch.data.horses && batch.data.horses.records) {
                        allHorses = allHorses.concat(batch.data.horses.records);
                    }
                    if (batch.data.starts) {
                        competitionStarts.push({
                            competition: batch.competition,
                            competition_foreign_id: batch.competition_foreign_id,
                            starts: batch.data.starts,
                            details: batch.details
                        });
                    }
                });
                
                // Éliminer les doublons pour people (par foreign_id)
                const uniquePeople = [];
                const seenPeopleForeignIds = new Set();
                allPeople.forEach(function(person) {
                    if (!seenPeopleForeignIds.has(person.foreign_id)) {
                        seenPeopleForeignIds.add(person.foreign_id);
                        uniquePeople.push(person);
                    }
                });
                
                // Éliminer les doublons pour horses (par foreign_id)
                const uniqueHorses = [];
                const seenHorsesForeignIds = new Set();
                allHorses.forEach(function(horse) {
                    if (!seenHorsesForeignIds.has(horse.foreign_id)) {
                        seenHorsesForeignIds.add(horse.foreign_id);
                        uniqueHorses.push(horse);
                    }
                });
                
                debugLog('Total unique people to import:', uniquePeople.length);
                debugLog('Total unique horses to import:', uniqueHorses.length);
                
                // Étape 1: Importer d'abord tous les people et horses
                const transactionUuid1 = generateUuid();
                const consolidatedBatch = {};
                
                if (uniquePeople.length > 0) {
                    consolidatedBatch.people = {
                        unique_by: 'foreign_id',
                        records: uniquePeople
                    };
                }
                
                if (uniqueHorses.length > 0) {
                    consolidatedBatch.horses = {
                        unique_by: 'foreign_id',
                        records: uniqueHorses
                    };
                }
                
                // Si on a des people ou des horses à importer
                if (Object.keys(consolidatedBatch).length > 0) {
                    $('#startlistProgress').prepend('<div class="progress-item pending">Importing riders and horses...</div>');
                    
                    // Utiliser l'action proxy pour éviter CORS
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'send_batch_to_equipe',
                            batch_data: JSON.stringify(consolidatedBatch),
                            api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                            meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                            transaction_uuid: transactionUuid1
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                debugLog('People and horses imported successfully');
                                $('#startlistProgress .progress-item:first').removeClass('pending').addClass('success').html('Riders and horses imported successfully');
                                
                                // Étape 2: Importer les starts pour chaque compétition
                                importStartlists();
                            } else {
                                $('#startlistProgress .progress-item:first').removeClass('pending').addClass('failed').html('Failed to import riders and horses');
                                debugLog('Failed to import people and horses:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#startlistProgress .progress-item:first').removeClass('pending').addClass('failed').html('Failed to import riders and horses');
                            debugLog('Request failed:', error);
                        }
                    });
                } else {
                    // Si pas de people/horses à importer, passer directement aux starts
                    importStartlists();
                }
                
                // Fonction pour importer les startlists après les people/horses
                function importStartlists() {
                    let successCount = 0;
                    let failCount = 0;
                    let processed = 0;
                    const total = competitionStarts.length;
                    
                    competitionStarts.forEach(function(compStarts) {
                        const transactionUuid = generateUuid();
                        
                        debugLog('Sending batch for:', compStarts.competition);
                        
                        // Utiliser l'action proxy pour éviter CORS
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'send_batch_to_equipe',
                                batch_data: JSON.stringify({ starts: compStarts.starts }),
                                api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                                meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                                transaction_uuid: transactionUuid
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    successCount++;
                                    $('#startlist-' + compStarts.competition_foreign_id).removeClass('pending').addClass('success');
                                } else {
                                    failCount++;
                                    $('#startlist-' + compStarts.competition_foreign_id).removeClass('pending').addClass('failed');
                                    debugLog('Import failed for:', compStarts.competition);
                                    debugLog('Response:', response);
                                }
                                
                                processed++;
                                checkComplete();
                            },
                            error: function(xhr, status, error) {
                                failCount++;
                                $('#startlist-' + compStarts.competition_foreign_id).removeClass('pending').addClass('failed');
                                debugLog('Request failed for:', compStarts.competition);
                                debugLog('Error:', error);
                                
                                processed++;
                                checkComplete();
                            }
                        });
                    });
                    
                    function checkComplete() {
                        if (processed === total) {
                            // Appeler le callback si fourni
                            if (typeof onCompleteCallback === 'function') {
                                onCompleteCallback();
                            }
                        }
                    }
                }
            }
            
            // Importer les résultats vers Equipe
            function importResultsBatchesToEquipe(batchDataArray) {
                let successCount = 0;
                let failCount = 0;
                let processed = 0;
                const total = batchDataArray.length;
                
                batchDataArray.forEach(function(batch) {
                    const transactionUuid = generateUuid();
                    
                    debugLog('Sending results batch for:', batch.competition);
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'send_batch_to_equipe',
                            batch_data: JSON.stringify(batch.data),
                            api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                            meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                            transaction_uuid: transactionUuid
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                successCount++;
                                $('#result-' + batch.competition_foreign_id).removeClass('pending').addClass('success');
                            } else {
                                failCount++;
                                $('#result-' + batch.competition_foreign_id).removeClass('pending').addClass('failed');
                                debugLog('Results import failed for:', batch.competition);
                                debugLog('Response:', response);
                            }
                            
                            processed++;
                            checkComplete();
                        },
                        error: function(xhr, status, error) {
                            failCount++;
                            $('#result-' + batch.competition_foreign_id).removeClass('pending').addClass('failed');
                            debugLog('Request failed for results:', batch.competition);
                            debugLog('Error:', error);
                            
                            processed++;
                            checkComplete();
                        }
                    });
                });
                
                function checkComplete() {
                    if (processed === total) {
                        // Afficher le résumé final
                        showFinalSummary();
                    }
                }
            }
            
            // Afficher le résumé final
            function showFinalSummary() {
                let html = '<div class="progress-section">';
                html += '<h5>Import Complete!</h5>';
                html += '<div class="alert alert-success">';
                html += 'The import process has been completed. Check the progress above for details.';
                html += '</div>';
                html += '</div>';
                
                $('#importProgress').append(html);
            }
            
            // Générer un UUID
            function generateUuid() {
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = Math.random() * 16 | 0,
                        v = c == 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            }
            
            // Nouveau bouton d'import
            $('#newImportButton').on('click', function() {
                $('#resultsStep').hide();
                $('#searchStep').show();
                $('#showId').val('');
                currentEventData = null;
                currentSelections = [];
                importOptions = {
                    classes: false,
                    startlists: false,
                    results: false
                };
            });
        });
    </script>
</body>
</html>
<?php
    }
}
?>