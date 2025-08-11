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
                    $classid = $c['kq'];
                    $existing['classes'][] = $foreignId;
                    
                    // Détecter si c'est une compétition par équipe
                    $isTeamCompetition = isset($c['lag']) && $c['lag'] === true;
                    //error_log('IsTeamCompetition: '. $isTeamCompetition."Forgein ID: ".$foreignId);
                    // Tester l'existence d'une startlist pour cette classe
                    $startUrl = rtrim($meetingUrl, '/') . "/competitions/{$classid}/starts.json";
                    $ch = curl_init($startUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "X-Api-Key: {$apiKey}",
                        "Accept: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $resp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode === 200) {
                        $starts = json_decode($resp, true);
                        //error_log("Starts : ".print_r($starts,true));
                        if (!empty($starts) && is_array($starts)) {
                            // Pour les compétitions par équipe, vérifier la présence de teams
                            if ($isTeamCompetition) {
                                $hasTeamStarts = false;
                              
                                foreach ($starts as $start) {
                                    //error_log('Start Lag: '.$start['lag_id']);
                                    if (isset($start['lag_id']) || isset($start['team'])) {
                                        $hasTeamStarts = true;
                                        break;
                                    }
                                }
                                if ($hasTeamStarts || count($starts) > 0) {
                                    $existing['startlists'][] = $foreignId;
                                }
                            } else {
                                // Pour les compétitions individuelles
                                $existing['startlists'][] = $foreignId;
                            }
                        }
                    }

                    // Tester l'existence de résultats
                    // D'abord essayer avec /H/results.json (standard)
                    $resultsUrl = rtrim($meetingUrl, '/') . "/competitions/{$classid}/H/results.json";
                    error_log("URL: ".$resultsUrl);
                    $ch = curl_init($resultsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "X-Api-Key: {$apiKey}",
                        "Accept: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $resp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    $hasResults = false;
                    
                    if ($httpCode === 200) {
                        $results = json_decode($resp, true);
                        if (!empty($results) && is_array($results)) {
                            foreach ($results as $result) {
                                if ($debugMode) {
                                    error_log("Result data: " . json_encode($result));
                                }
                                
                                // Un résultat est considéré comme importé seulement s'il a des données réelles
                                $hasResultData = false;
                                
                                // Vérifier les données du round 1 - doit avoir une valeur numérique
                                if (isset($result['grundf']) && is_numeric($result['grundf']) && $result['grundf'] !== '') {
                                    $hasResultData = true;
                                    if ($debugMode) {
                                        error_log("Found grundf with value: " . $result['grundf']);
                                    }
                                }
                                
                                if (isset($result['grundt']) && is_numeric($result['grundt']) && $result['grundt'] !== '') {
                                    $hasResultData = true;
                                    if ($debugMode) {
                                        error_log("Found grundt with value: " . $result['grundt']);
                                    }
                                }
                                if ($hasResultData) {
                                    $hasResults = true;
                                    break;
                                } 
                            }
                            if ($debugMode && !$hasResults) {
                                error_log("No actual result data found for competition " . $foreignId);
                            }
                        }
                    }
                    
                    if ($hasResults) {
                        $existing['results'][] = $foreignId;
                    }
                    
                    // Pour debug
                    if ($debugMode && $isTeamCompetition) {
                        error_log("Team competition $foreignId - Starts found: " . (in_array($classid, $existing['startlists']) ? 'yes' : 'no'));
                        error_log("Team competition $foreignId - Results found: " . ($hasResults ? 'yes' : 'no'));
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
            $ordCounter = 0; // Compteur ord démarrant à 0
            
            foreach ($selections as $selection) {
                $classId = $selection['class_id'];
                $classData = $hippodataClasses[$classId] ?? null;
                
                if (!$classData) continue;
                
                // Si import class est sélectionné
                if ($selection['import_class']) {
                    $name = !empty($classData['NAME']) ? $classData['NAME'] : $classData['SPONSOR'];
                    
                    // Extraire l'heure depuis DATETIME
                    $klock = '';
                    if (!empty($classData['DATETIME'])) {
                        // Format: "2025-02-12 09:00:00"
                        $dateTime = new DateTime($classData['DATETIME']);
                        $klock = $dateTime->format('H:i'); // Format HH:MM
                    }
                    
                    $classToImport = [
                        'foreign_id' => (string)$classData['ID'],
                        'clabb' => 'HD-' . $counter,
                        'klass' => $name,
                        'oeverskr1' => $name,
                        'datum' => $classData['DATE'],
                        'klock' => $klock, // Heure au format HH:MM
                        'ord' => $ordCounter, // Compteur démarrant à 0
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
                    $ordCounter++; // Incrémenter le compteur ord
                    
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
                        'name' => !empty($classData['NAME']) ? $classData['NAME'] : $classData['SPONSOR'],
                        'is_team' => $selection['team_class']
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
            
            if ($httpCode === 200 && $response) {
                $people = json_decode($response, true);
                if (is_array($people)) {
                    foreach ($people as $person) {
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
            
            if ($httpCode === 200 && $response) {
                $horses = json_decode($response, true);
                if (is_array($horses)) {
                    foreach ($horses as $horse) {
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
            
            // 3. Récupérer la liste des clubs existants dans Equipe (pour les équipes)
            $existingClubs = [];
            
            $ch = curl_init($meetingUrl . '/clubs.json');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Api-Key: {$apiKey}", "Accept: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $clubs = json_decode($response, true);
                if (is_array($clubs)) {
                    foreach ($clubs as $club) {
                        if (isset($club['foreign_id'])) {
                            $existingClubs[$club['foreign_id']] = $club;
                        }
                    }
                }
            }
            
            // Mapper les codes IOC vers les noms de pays en anglais
            $countryNames = [
                'GER' => 'Germany',
                'FRA' => 'France',
                'GBR' => 'Great Britain',
                'USA' => 'United States',
                'NED' => 'Netherlands',
                'BEL' => 'Belgium',
                'SUI' => 'Switzerland',
                'SWE' => 'Sweden',
                'ITA' => 'Italy',
                'ESP' => 'Spain',
                'AUT' => 'Austria',
                'IRL' => 'Ireland',
                'CAN' => 'Canada',
                'AUS' => 'Australia',
                'NZL' => 'New Zealand',
                'JPN' => 'Japan',
                'BRA' => 'Brazil',
                'ARG' => 'Argentina',
                'CHI' => 'Chile',
                'MEX' => 'Mexico',
                'NOR' => 'Norway',
                'DEN' => 'Denmark',
                'FIN' => 'Finland',
                'POL' => 'Poland',
                'CZE' => 'Czech Republic',
                'HUN' => 'Hungary',
                'POR' => 'Portugal',
                'RUS' => 'Russia',
                'UKR' => 'Ukraine',
                'RSA' => 'South Africa',
                'UAE' => 'United Arab Emirates',
                'KSA' => 'Saudi Arabia',
                'QAT' => 'Qatar',
                'HKG' => 'Hong Kong',
                'SGP' => 'Singapore',
                'IND' => 'India',
                'COL' => 'Colombia',
                'VEN' => 'Venezuela',
                'URY' => 'Uruguay',
                'ECU' => 'Ecuador',
                'ISR' => 'Israel',
                'TUR' => 'Turkey',
                'GRE' => 'Greece',
                'EGY' => 'Egypt',
                'MAR' => 'Morocco',
                'KOR' => 'South Korea',
                'TPE' => 'Chinese Taipei',
                'LUX' => 'Luxembourg',
                'EST' => 'Estonia',
                'LAT' => 'Latvia',
                'LTU' => 'Lithuania',
                'SVK' => 'Slovakia',
                'SLO' => 'Slovenia',
                'CRO' => 'Croatia',
                'BUL' => 'Bulgaria',
                'ROU' => 'Romania'
            ];
            
            $allBatchData = [];
            $processedCompetitions = [];
            
            // 4. Pour chaque compétition, récupérer la startlist
            foreach ($competitions as $comp) {
                $classId = $comp['class_id'];
                $competitionForeignId = $comp['foreign_id'];
                $isTeamCompetition = isset($comp['is_team']) && $comp['is_team'];
                
                if ($debugMode) {
                    error_log("Competition " . $comp['name'] . " - is_team from frontend: " . ($isTeamCompetition ? 'yes' : 'no'));
                    error_log("Competition data: " . json_encode($comp));
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
                        'teams_count' => 0,
                        'is_team' => $isTeamCompetition,
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
                        'teams_count' => 0,
                        'is_team' => $isTeamCompetition,
                        'error' => "No competitors in startlist"
                    ];
                    continue;
                }
                
                $newPeople = [];
                $newHorses = [];
                $newClubs = [];
                $newTeams = [];
                $starts = [];
                
                // Traiter chaque concurrent
                $competitors = $startlistData['CLASS']['COMPETITORS']['COMPETITOR'];
                
                // S'assurer que c'est un tableau d'arrays
                if (isset($competitors['RIDER'])) {
                    $competitors = [$competitors];
                }
                
                // Si c'est une compétition par équipe, analyser d'abord les nations
                $competitorsByNation = [];
                $teamsByNation = [];
                $teamCounter = 1;
                
                if ($isTeamCompetition) {
                    // D'abord, compter les cavaliers par nation
                    foreach ($competitors as $competitor) {
                        $rider = $competitor['RIDER'] ?? [];
                        $nation = $rider['NATION'] ?? '';
                        $club = $rider['CLUB'] ?? '';
                        
                        if ($nation) {
                            if (!isset($competitorsByNation[$nation])) {
                                $competitorsByNation[$nation] = [
                                    'competitors' => [],
                                    'club_name' => $club ?: $nation
                                ];
                            }
                            $competitorsByNation[$nation]['competitors'][] = $competitor;
                        }
                    }
                    
                    // Créer les équipes seulement pour les nations avec 3+ cavaliers
                    foreach ($competitorsByNation as $nation => $data) {
                        if (count($data['competitors']) >= 3) {
                            $clubForeignId = 'club_' . $nation;
                            $teamForeignId = 'team_' . $competitionForeignId . '_' . $nation;
                            
                            // Déterminer le nom du club
                            $clubName = $data['club_name'];
                            if (isset($countryNames[$nation])) {
                                $clubName = $countryNames[$nation] . ' Team';
                            } elseif ($data['club_name'] && $data['club_name'] !== $nation) {
                                $clubName = $data['club_name'];
                            } else {
                                $clubName = $nation . ' Team';
                            }
                            
                            // Ajouter le club s'il n'existe pas
                            if (!isset($existingClubs[$clubForeignId]) && !isset($newClubs[$clubForeignId])) {
                                $newClubs[$clubForeignId] = [
                                    'foreign_id' => $clubForeignId,
                                    'name' => $clubName,
                                    'logo_id' => $nation,
                                    'logo_group' => 'flags48'
                                ];
                            }
                            
                            // Créer l'équipe
                            $teamsByNation[$nation] = [
                                'foreign_id' => $teamForeignId,
                                'st' => $teamCounter,
                                'ord' => $teamCounter,
                                'lagnr' => $teamCounter,
                                'lagledare' => '',
                                'club' => ['foreign_id' => $clubForeignId]
                            ];
                            
                            $newTeams[] = $teamsByNation[$nation];
                            $teamCounter++;
                            
                            if ($debugMode) {
                                error_log("Created team for " . $nation . " with " . count($data['competitors']) . " riders");
                            }
                        } else {
                            if ($debugMode) {
                                error_log("Nation " . $nation . " has only " . count($data['competitors']) . " riders, no team created");
                            }
                        }
                    }
                }
                
                // Traiter chaque concurrent
                foreach ($competitors as $competitorIndex => $competitor) {
                    $rider = $competitor['RIDER'] ?? [];
                    $horse = $competitor['HORSE'] ?? [];
                    $nation = $rider['NATION'] ?? '';
                    
                    // Gérer les cavaliers avec ou sans FEI ID
                    $riderFeiId = $rider['RFEI_ID'] ?? null;
                    $riderName = $rider['RNAME'] ?? '';
                    
                    // Si pas de FEI ID, créer un ID temporaire basé sur le nom et l'event
                    if (!$riderFeiId && $riderName) {
                        // Créer un ID unique basé sur le nom du cavalier et l'ID de l'event
                        $riderFeiId = 'TEMP_R_' . $eventId . '_' . md5($riderName);
                        
                        if ($debugMode) {
                            error_log("Created temporary rider ID: $riderFeiId for $riderName");
                        }
                    }
                    
                    // Vérifier et préparer les données du cavalier
                    if ($riderFeiId && !isset($existingPeople[$riderFeiId]) && !isset($existingPeopleFeiIds[$riderFeiId])) {
                        $nameParts = explode(',', $riderName);
                        $lastName = trim($nameParts[0] ?? '');
                        $firstName = trim($nameParts[1] ?? '');
                        
                        $newPerson = [
                            'foreign_id' => $riderFeiId,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'country' => $nation ?: 'XXX' // Code pays par défaut si non spécifié
                        ];
                        
                        // Ajouter le FEI ID seulement s'il est réel (pas temporaire)
                        if (strpos($riderFeiId, 'TEMP_') !== 0) {
                            $newPerson['fei_id'] = $riderFeiId;
                        }
                        
                        $newPeople[] = $newPerson;
                        $existingPeople[$riderFeiId] = true;
                        $existingPeopleFeiIds[$riderFeiId] = true;
                    }
                    
                    // Gérer les chevaux avec ou sans FEI ID
                    $horseFeiId = $horse['HFEI_ID'] ?? null;
                    $horseName = $horse['HNAME'] ?? '';
                    $horseNumber = $horse['HNR'] ?? '';
                    
                    // Si pas de FEI ID, créer un ID temporaire
                    if (!$horseFeiId && $horseName) {
                        // Créer un ID unique basé sur le nom du cheval, son numéro et l'ID de l'event
                        $horseFeiId = 'TEMP_H_' . $eventId . '_' . md5($horseName . '_' . $horseNumber);
                        
                        if ($debugMode) {
                            error_log("Created temporary horse ID: $horseFeiId for $horseName");
                        }
                    }
                    
                    // Vérifier et préparer les données du cheval
                    if ($horseFeiId && !isset($existingHorses[$horseFeiId]) && !isset($existingHorsesFeiIds[$horseFeiId])) {
                        $horseInfo = $horse['HORSEINFO'] ?? [];
                        
                        // Gérer le genre du cheval
                        $gender = strtolower($horseInfo['GENDER'] ?? '');
                        $sexMap = [
                            'm' => 'val',
                            'g' => 'val',
                            'f' => 'sto',
                            'mare' => 'sto',
                            'stallion' => 'hin',
                            'gelding' => 'val'
                        ];
                        $sex = $sexMap[$gender] ?? 'val';
                        
                        // Gérer l'année de naissance
                        $bornYear = $horseInfo['BORNYEAR'] ?? '';
                        // Si l'année est 2025 et l'âge est 0, c'est probablement une erreur
                        if ($bornYear == 2025 && ($horseInfo['AGE'] ?? 0) == 0) {
                            // Calculer l'année de naissance basée sur l'âge si disponible
                            if (isset($horseInfo['AGE']) && $horseInfo['AGE'] > 0) {
                                $bornYear = date('Y') - $horseInfo['AGE'];
                            } else {
                                $bornYear = ''; // Laisser vide si on ne peut pas déterminer
                            }
                        }
                        
                        $newHorse = [
                            'foreign_id' => $horseFeiId,
                            'num' => $horseNumber,
                            'name' => $horseName,
                            'sex' => $sex,
                            'born_year' => (string)$bornYear,
                            'owner' => $horseInfo['OWNER'] ?? '',
                            'category' => 'H'
                        ];
                        
                        // Ajouter le FEI ID seulement s'il est réel (pas temporaire)
                        if (strpos($horseFeiId, 'TEMP_') !== 0) {
                            $newHorse['fei_id'] = $horseFeiId;
                        }
                        
                        // Ajouter les infos généalogiques si disponibles
                        if (!empty($horseInfo['FATHER'])) {
                            $newHorse['father'] = $horseInfo['FATHER'];
                        }
                        if (!empty($horseInfo['MOTHERFATHER'])) {
                            $newHorse['mother_father'] = $horseInfo['MOTHERFATHER'];
                        }
                        if (!empty($horseInfo['BREED'])) {
                            $newHorse['breed'] = $horseInfo['BREED'];
                        }
                        if (!empty($horseInfo['COLOR'])) {
                            $newHorse['color'] = $horseInfo['COLOR'];
                        }
                        
                        $newHorses[] = $newHorse;
                        $existingHorses[$horseFeiId] = true;
                        $existingHorsesFeiIds[$horseFeiId] = true;
                    }
                    
                    // Préparer la start entry
                    if ($riderFeiId && $horseFeiId) {
                        $sortOrder = $competitor['SORTROUND']['ROUND1'] ?? $competitor['SORTORDER'] ?? ($competitorIndex + 1);
                        
                        // Pour les compétitions nationales, gérer le club différemment
                        $clubInfo = $rider['CLUB'] ?? '';
                        
                        if ($isTeamCompetition && $nation && isset($teamsByNation[$nation])) {
                            // Start entry pour compétition par équipe (seulement si l'équipe existe)
                            $starts[] = [
                                'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId,
                                'st' => (string)$sortOrder,
                                'ord' => (int)$sortOrder,
                                'category' => 'H',
                                'section' => 'A',
                                'rider' => ['foreign_id' => $riderFeiId],
                                'horse' => ['foreign_id' => $horseFeiId],
                                'team' => ['foreign_id' => $teamsByNation[$nation]['foreign_id']],
                                'club' => ['foreign_id' => 'club_' . $nation]
                            ];
                        } else {
                            // Start entry normale (individuelle ou nationale)
                            $startEntry = [
                                'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId,
                                'st' => (string)$sortOrder,
                                'ord' => (int)$sortOrder,
                                'rider' => ['foreign_id' => $riderFeiId],
                                'horse' => ['foreign_id' => $horseFeiId]
                            ];
                            
                            // Pour les compétitions nationales, ajouter le club comme texte si disponible
                            if ($clubInfo && !$nation) {
                                $startEntry['club_text'] = $clubInfo;
                            }
                            
                            $starts[] = $startEntry;
                        }
                    } else {
                        if ($debugMode) {
                            error_log("Skipping competitor - missing rider or horse ID");
                            error_log("Rider: " . json_encode($rider));
                            error_log("Horse: " . json_encode($horse));
                        }
                    }
                }
                
                if ($debugMode) {
                    error_log("Competition " . $comp['name'] . ": " . count($newPeople) . " new people, " . 
                            count($newHorses) . " new horses, " . count($starts) . " starts" .
                            ($isTeamCompetition ? ", " . count($newTeams) . " teams" : ""));
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
                
                if ($isTeamCompetition) {
                    if (!empty($newClubs)) {
                        $batchData['clubs'] = [
                            'unique_by' => 'foreign_id',
                            'records' => array_values($newClubs)
                        ];
                    }
                    
                    if (!empty($newTeams)) {
                        $batchData['teams'] = [
                            'unique_by' => 'foreign_id',
                            'where' => [
                                'competition' => ['foreign_id' => $competitionForeignId]
                            ],
                            'records' => $newTeams
                        ];
                    }
                }
                
                if (!empty($starts)) {
                    $batchData['starts'] = [
                        'unique_by' => 'foreign_id',
                        'where' => [
                            'competition' => ['foreign_id' => $competitionForeignId]
                        ],
                        'abort_if_any' => ['rid' => true],
                        'replace' => true,
                        'records' => $starts
                    ];
                }
                
                if (!empty($batchData)) {
                    $allBatchData[] = [
                        'competition' => $comp['name'],
                        'competition_foreign_id' => $competitionForeignId,
                        'is_team' => $isTeamCompetition,
                        'data' => $batchData,
                        'details' => [
                            'people' => $newPeople,
                            'horses' => $newHorses,
                            'starts' => $starts,
                            'teams' => $newTeams
                        ]
                    ];
                }
                
                $processedCompetitions[] = [
                    'name' => $comp['name'],
                    'foreign_id' => $competitionForeignId,
                    'people_count' => count($newPeople),
                    'horses_count' => count($newHorses),
                    'starts_count' => count($starts),
                    'teams_count' => count($newTeams),
                    'is_team' => $isTeamCompetition,
                    'people' => array_map(function($p) {
                        return $p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['country'] . ')';
                    }, $newPeople),
                    'horses' => array_map(function($h) {
                        return $h['name'] . ' - ' . ($h['fei_id'] ?? $h['foreign_id']);
                    }, $newHorses),
                    'teams' => array_map(function($t) use ($countryNames) {
                        $nation = str_replace('club_', '', $t['club']['foreign_id']);
                        $teamName = isset($countryNames[$nation]) ? $countryNames[$nation] : $nation;
                        return 'Team ' . $t['lagnr'] . ' - ' . $teamName;
                    }, $newTeams)
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
                $isTeamCompetition = isset($comp['is_team']) && $comp['is_team'];
                
                if ($debugMode) {
                    error_log("Processing results for competition: " . $comp['name'] . " (class_id: " . $classId . ", is_team: " . ($isTeamCompetition ? 'yes' : 'no') . ")");
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
                if (isset($resultsData['CLASS']['TIME3_ALLOWED'])) {
                    $competitionUpdate['omh2t'] = (int)$resultsData['CLASS']['TIME3_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME4_ALLOWED'])) {
                    $competitionUpdate['omg3t'] = (int)$resultsData['CLASS']['TIME4_ALLOWED'];
                }
                if (isset($resultsData['CLASS']['TIME5_ALLOWED'])) {
                    $competitionUpdate['omg4t'] = (int)$resultsData['CLASS']['TIME5_ALLOWED'];
                }
                
                // Si c'est une compétition par équipe, ajouter le flag
                if ($isTeamCompetition) {
                    $competitionUpdate['team'] = true;
                }
                
                $results = [];
                
                // Traiter chaque concurrent
                $competitors = $resultsData['CLASS']['COMPETITORS']['COMPETITOR'];
                
                // S'assurer que c'est un tableau d'arrays
                if (isset($competitors['RIDER'])) {
                    $competitors = [$competitors];
                }
                
                // Trouver le rang des éliminés/retirés
                $eliminatedRank = null;
                foreach ($competitors as $compet) {
                    $compResultTotal = $compet['RESULTTOTAL'][0] ?? [];
                    if (isset($compResultTotal['STATUS']) && $compResultTotal['STATUS'] != 1) {
                        $compStatusText = strtolower($compResultTotal['TEXT'] ?? '');
                        if (($compStatusText == 'eliminated' || $compStatusText == 'retired') && isset($compResultTotal['RANK'])) {
                            $eliminatedRank = (int)$compResultTotal['RANK'];
                            break;
                        }
                    }
                }
                
                // Pour les compétitions par équipe, analyser les résultats par nation pour déterminer les cavaliers exclus
                $ridersByNation = [];
                $ridersToSkip = [];
                
                if ($isTeamCompetition) {
                    if ($debugMode) {
                        error_log("=== TEAM COMPETITION ANALYSIS FOR " . $comp['name'] . " ===");
                        error_log("Competition Foreign ID: " . $competitionForeignId);
                        error_log("Total competitors: " . count($competitors));
                    }
                    // D'abord, regrouper les cavaliers par nation et analyser leurs rounds
                    $idx = 0;
                    foreach ($competitors as $competitor) {
                        $rider = $competitor['RIDER'] ?? [];
                        $nation = $rider['NATION'] ?? '';
                        if ($debugMode && $idx < 5) { // Log les 5 premiers pour debug
                            error_log("Competitor $idx:");
                            error_log("  - RIDER data: " . json_encode($rider));
                            error_log("  - Nation found: '" . $nation . "'");
                        }
                        $idx++;
                        
                        if ($nation) {
                            // Gérer les IDs temporaires pour les cavaliers nationaux
                            $riderFeiId = $rider['RFEI_ID'] ?? null;
                            $horseFeiId = $competitor['HORSE']['HFEI_ID'] ?? null;
                            
                            // Si pas de FEI ID, créer les mêmes IDs temporaires que dans import_startlists
                            if (!$riderFeiId && isset($rider['RNAME'])) {
                                $riderFeiId = 'TEMP_R_' . $eventId . '_' . md5($rider['RNAME']);
                            }
                            if (!$horseFeiId && isset($competitor['HORSE']['HNAME'])) {
                                $horseNumber = $competitor['HORSE']['HNR'] ?? '';
                                $horseFeiId = 'TEMP_H_' . $eventId . '_' . md5($competitor['HORSE']['HNAME'] . '_' . $horseNumber);
                            }
                            
                            $resultDetails = $competitor['RESULT'] ?? [];
                            
                            // Vérifier quels rounds ce cavalier a fait
                            $hasRound2 = false;
                            $hasRound3 = false;
                            foreach ($resultDetails as $roundResult) {
                                $roundNum = $roundResult['ROUND'] ?? 0;
                                if ($roundNum == 2) {
                                    $hasRound2 = true;
                                } elseif ($roundNum == 3) {
                                    $hasRound3 = true;
                                }
                            }
                            
                            if (!isset($ridersByNation[$nation])) {
                                $ridersByNation[$nation] = [];
                            }
                            
                            $ridersByNation[$nation][] = [
                                'rider_fei_id' => $riderFeiId,
                                'horse_fei_id' => $horseFeiId,
                                'has_round2' => $hasRound2,
                                'has_round3' => $hasRound3,
                                'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId
                            ];
                        }
                    }
                    
                    // Pour chaque nation, déterminer qui doit avoir skip_rounds
                    foreach ($ridersByNation as $nation => $riders) {
                        if ($debugMode) {
                            error_log("Nation '$nation' has " . count($riders) . " riders:");
                            foreach ($riders as $r) {
                                error_log("  - Rider " . $r['rider_fei_id'] . " - Round2: " . ($r['has_round2'] ? 'YES' : 'NO') . " - Round3: " . ($r['has_round3'] ? 'YES' : 'NO'));
                            }
                        }
                        if (count($riders) >= 4) {
                            // Compter combien ont un round 2
                            $ridersWithRound2 = array_filter($riders, function($r) {
                                return $r['has_round2'];
                            });
                            
                            // Si exactement 3 ont un round 2, le 4e doit avoir skip_rounds[2]
                            if (count($ridersWithRound2) == 3) {
                                foreach ($riders as $rider) {
                                    if (!$rider['has_round2'] && $rider['foreign_id']) {
                                        if (!isset($ridersToSkip[$rider['foreign_id']])) {
                                            $ridersToSkip[$rider['foreign_id']] = [];
                                        }
                                        $ridersToSkip[$rider['foreign_id']][] = 2;
                                    }
                                }
                            }
                            
                            // Vérifier s'il y a un round 3 (barrage)
                            $ridersWithRound3 = array_filter($riders, function($r) {
                                return $r['has_round3'];
                            });
                            // Si au moins un cavalier a fait le round 3 ET qu'il y a plusieurs cavaliers dans l'équipe
                            if (count($ridersWithRound3) > 0 && count($ridersWithRound3) < count($riders)) {
                                if ($debugMode) {
                                    error_log("Round 3 detected: " . count($ridersWithRound3) . " riders participated out of " . count($riders));
                                }
                                
                                // Si seulement 1 cavalier a fait le round 3, les autres doivent avoir skip_rounds[3]
                                if (count($ridersWithRound3) == 1) {
                                    foreach ($riders as $rider) {
                                        if (!$rider['has_round3'] && $rider['foreign_id']) {
                                            if (!isset($ridersToSkip[$rider['foreign_id']])) {
                                                $ridersToSkip[$rider['foreign_id']] = [];
                                            }
                                            // Ajouter 3 seulement s'il n'est pas déjà présent
                                            if (!in_array(3, $ridersToSkip[$rider['foreign_id']])) {
                                                $ridersToSkip[$rider['foreign_id']][] = 3;
                                            }
                                        }
                                    }
                                }
                            } else if (count($ridersWithRound3) == 0 && $debugMode) {
                                error_log("No round 3 detected for team '$nation' - no skip_rounds[3] needed");
                            }
                        }
                    }
                    
                    if ($debugMode && count($ridersToSkip) > 0) {
                        error_log("Riders with skip rounds: " . json_encode($ridersToSkip));
                    }
                }
                
                foreach ($competitors as $competitor) {
                    $rider = $competitor['RIDER'] ?? [];
                    $horse = $competitor['HORSE'] ?? [];
                    
                    // Gérer les IDs pour les cavaliers nationaux
                    $riderFeiId = $rider['RFEI_ID'] ?? null;
                    $horseFeiId = $horse['HFEI_ID'] ?? null;
                    $riderName = $rider['RNAME'] ?? '';
                    $horseName = $horse['HNAME'] ?? '';
                    $horseNumber = $horse['HNR'] ?? '';
                    
                    // Créer des IDs temporaires si nécessaire (même logique que import_startlists)
                    if (!$riderFeiId && $riderName) {
                        $riderFeiId = 'TEMP_R_' . $eventId . '_' . md5($riderName);
                        if ($debugMode) {
                            error_log("Using temporary rider ID for results: $riderFeiId for $riderName");
                        }
                    }
                    
                    if (!$horseFeiId && $horseName) {
                        $horseFeiId = 'TEMP_H_' . $eventId . '_' . md5($horseName . '_' . $horseNumber);
                        if ($debugMode) {
                            error_log("Using temporary horse ID for results: $horseFeiId for $horseName");
                        }
                    }
                    
                    if (!$riderFeiId || !$horseFeiId) {
                        if ($debugMode) {
                            error_log("Skipping result - missing rider or horse ID after temporary ID generation");
                            error_log("Rider data: " . json_encode($rider));
                            error_log("Horse data: " . json_encode($horse));
                        }
                        continue;
                    }

                    // Préparer le résultat
                    $result = [
                        'foreign_id' => $riderFeiId . '_' . $horseFeiId . '_' . $competitionForeignId,
                        'rider' => ['foreign_id' => $riderFeiId],
                        'horse' => ['foreign_id' => $horseFeiId],
                        'rid' => true,
                        'result_at' => date('Y-m-d H:i:s'),
                        'last_result_at' => date('Y-m-d H:i:s'),
                        'k' => 'H',
                        'av' => 'A'
                    ];
                    
                    // Pour les compétitions par équipe, vérifier si ce cavalier doit skipper des rounds
                    if ($isTeamCompetition && isset($ridersToSkip[$result['foreign_id']])) {
                        $skipRounds = $ridersToSkip[$result['foreign_id']];
                        sort($skipRounds); // S'assurer que les rounds sont dans l'ordre
                        $result['skip_rounds'] = $skipRounds;  // Tableau d'entiers
                        if ($debugMode) {
                            error_log("Adding skip_rounds " . json_encode($skipRounds) . " for rider: " . $result['foreign_id']);
                        }
                    }
                    
                    // Traiter les résultats par round
                    $resultDetails = $competitor['RESULT'] ?? [];
                    $resultTotal = $competitor['RESULTTOTAL'][0] ?? [];
                    
                    // Initialiser les valeurs par défaut
                    $result['ord'] = (int)($competitor['SORTORDER'] ?? 1000);
                    
                    // Mapper les résultats selon les rounds
                    foreach ($resultDetails as $roundResult) {
                        $round = $roundResult['ROUND'] ?? 0;
                        $faults = (float)($roundResult['FAULTS'] ?? 0);
                        $time = (float)($roundResult['TIME'] ?? 0);
                        $timeFaults = (float)($roundResult['TIMEFAULTS'] ?? 0);
                        
                        switch ($round) {
                            case 1:
                                $result['grundf'] = $faults;
                                $result['grundt'] = $time;
                                $result['tfg'] = $timeFaults;
                                break;
                            case 2:
                                $result['omh1f'] = $faults;
                                $result['omh1t'] = $time;
                                $result['tf1'] = $timeFaults;
                                break;
                            case 3:
                                $result['omh2f'] = $faults;
                                $result['omh2t'] = $time;
                                $result['tf2'] = $timeFaults;
                                break;
                            case 4:
                                $result['omg3f'] = $faults;
                                $result['omg3t'] = $time;
                                $result['tf3'] = $timeFaults;
                                break;
                            case 5:
                                $result['omg4f'] = $faults;
                                $result['omg4t'] = $time;
                                $result['tf4'] = $timeFaults;
                                break;
                        }
                    }
                    
                    // Total des fautes
                    $result['totfel'] = (float)($resultTotal['FAULTS'] ?? 0);
                    
                    // Traiter d'abord les états spéciaux (eliminated, retired, etc.)
                    $hasSpecialStatus = false;
                    if (isset($resultTotal['STATUS']) && $resultTotal['STATUS'] != 1) {
                        $statusText = strtolower($resultTotal['TEXT'] ?? '');
                        $roundName = strtolower($resultTotal['NAME'] ?? '');
                        $hasSpecialStatus = true;

                        if ($statusText == 'retired') {
                            $result['or'] = 'U';
                            $result['result_preview'] = 'Ret.';
                            $result['grundf'] = 999;
                            $result['grundt'] = 999;
                            $result['tfg'] = null;
                            $result['re'] = $eliminatedRank;
                        } elseif ($statusText == 'eliminated') {
                            $result['or'] = 'D';
                            $result['result_preview'] = 'El.';
                            $result['grundf'] = 999;
                            $result['grundt'] = 999;
                            $result['tfg'] = null;
                            $result['re'] = $eliminatedRank;
                        } elseif ($statusText == 'disqualified') {
                            $result['or'] = 'S';
                            $result['result_preview'] = 'Dsq.';
                            $result['grundf'] = 999;
                            $result['grundt'] = 999;
                            $result['tfg'] = null;
                            $result['re'] = $eliminatedRank;
                        } elseif ($statusText == 'withdrawn') {
                            if ($roundName == 'jump-off' || strpos($roundName, 'round 2') !== false || strpos($roundName, 'phase 2') !== false) {
                                $result['omh1f'] = 999;
                                $result['omh1t'] = 999;
                                $result['totfel'] = 999;
                                $result['result_preview'] = '0-ABST';
                            } elseif (strpos($roundName, 'round 3') !== false || strpos($roundName, 'phase 3') !== false) {
                                $result['omh2f'] = 999;
                                $result['omh2t'] = 999;
                                $result['totfel'] = 999;
                                $result['result_preview'] = '0-0-ABST';
                            } elseif (strpos($roundName, 'round 4') !== false || strpos($roundName, 'phase 4') !== false) {
                                $result['omg3f'] = 999;
                                $result['omg3t'] = 999;
                                $result['totfel'] = 999;
                                $result['result_preview'] = '0-0-0-ABST';
                            } elseif (strpos($roundName, 'round 5') !== false || strpos($roundName, 'phase 5') !== false) {
                                $result['omg4f'] = 999;
                                $result['omg4t'] = 999;
                                $result['totfel'] = 999;
                                $result['result_preview'] = '0-0-0-0-ABST';
                            } else {
                                $result['a'] = 'Ö';
                                $result['grundf'] = 999;
                                $result['grundt'] = 999;
                                $result['tfg'] = null;
                                $result['result_preview'] = 'ABST';
                            }
                        } elseif ($statusText == 'no show') {
                            $result['a'] = 'U';
                            $result['grundf'] = 999;
                            $result['grundt'] = 999;
                            $result['tfg'] = null;
                            $result['result_preview'] = 'NS';
                        }
                    }
                    
                    // Gérer les flags in_team pour les compétitions par équipe
                    if ($isTeamCompetition) {
                        // Déterminer quels rounds ont été complétés
                        $roundsData = [];
                        foreach ($resultDetails as $roundResult) {
                            $round = $roundResult['ROUND'] ?? 0;
                            if ($round > 0) {
                                $roundsData[$round] = true;
                            }
                        }
                        
                        // Round 1
                        if (isset($result['grundf']) && $result['grundf'] != 999) {
                            $result['round1_in_team'] = true;
                        } else {
                            $result['round1_in_team'] = false;
                        }
                        
                        // Si le cavalier n'a pas de statut spécial (eliminated, retired, etc.)
                        if (!$hasSpecialStatus || ($hasSpecialStatus && $statusText == 'withdrawn')) {
                            // Round 2
                            if (isset($roundsData[1]) && !isset($roundsData[2]) && !$hasSpecialStatus) {
                                // Abstained au round 2
                                $result['omh1f'] = 999;
                                $result['omh1t'] = 999;
                                $result['round2_in_team'] = true;
                                $result['or'] = 'A';
                                
                                if (!isset($result['totfel']) || $result['totfel'] < 999) {
                                    $result['totfel'] = 999;
                                }
                                
                                $result['result_preview'] = (string)($result['grundf'] ?? 0) . '-ABST';
                            } elseif (isset($roundsData[2])) {
                                $result['round2_in_team'] = true;
                            } else {
                                $result['round2_in_team'] = false;
                            }
                            
                            // Round 3
                            if (isset($roundsData[1]) && isset($roundsData[2]) && !isset($roundsData[3]) && !$hasSpecialStatus) {
                                // Abstained au round 3
                                $result['omh2f'] = 999;
                                $result['omh2t'] = 999;
                                $result['round3_in_team'] = true;
                                $result['or'] = 'A';
                                
                                if (!isset($result['totfel']) || $result['totfel'] < 999) {
                                    $result['totfel'] = 999;
                                }
                                
                                $result['result_preview'] = (string)($result['grundf'] ?? 0) . '-' . (string)($result['omh1f'] ?? 0) . '-ABST';
                            } elseif (isset($roundsData[3])) {
                                $result['round3_in_team'] = true;
                            } else {
                                $result['round3_in_team'] = false;
                            }
                            
                            // Rounds 4 et 5 suivent la même logique...
                        } else {
                            // Si éliminé/retiré/disqualifié, pas de flags in_team pour les rounds suivants
                            $result['round2_in_team'] = false;
                            $result['round3_in_team'] = false;
                            $result['round4_in_team'] = false;
                            $result['round5_in_team'] = false;
                        }
                    }
                    
                    // Ajouter le rang si pas déjà défini
                    if (!isset($result['re']) && isset($resultTotal['RANK'])) {
                        $result['re'] = (int)$resultTotal['RANK'];
                    }
                    
                    // Prix
                    if (isset($resultTotal['PRIZE']['MONEY'])) {
                        $result['premie'] = (float)$resultTotal['PRIZE']['MONEY'];
                        $result['premie_show'] = (float)$resultTotal['PRIZE']['MONEY'];
                    }
                    
                    // Prix en nature
                    if (isset($resultTotal['PRIZE']['TEXT']) && !isset($resultTotal['PRIZE']['MONEY'])) {
                        $result['rtxt'] = $resultTotal['PRIZE']['TEXT'];
                        $result['premie'] = 0;
                        $result['premie_show'] = 0;
                    }
                    
                    // Vérifier si pas de résultats du tout
                    if (!isset($resultDetails[0]) || count($resultDetails) == 0) {
                        if (!isset($result['or']) && !isset($result['a'])) {
                            $result['a'] = 'U';
                            $result['grundf'] = 999;
                            $result['grundt'] = 999;
                            $result['tfg'] = null;
                            $result['result_preview'] = 'NS';
                        }
                    }
                    
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
                            'competition' => ['foreign_id' => $competitionForeignId]
                        ],
                        'replace' => true,
                        'records' => $results
                    ];
                }
                
                if (!empty($batchData)) {
                    $allBatchData[] = [
                        'competition' => $comp['name'],
                        'competition_foreign_id' => $competitionForeignId,
                        'is_team' => $isTeamCompetition,
                        'data' => $batchData
                    ];
                }
                
                $processedCompetitions[] = [
                    'name' => $comp['name'],
                    'foreign_id' => $competitionForeignId,
                    'results_count' => count($results),
                    'is_team' => $isTeamCompetition,
                    'time_allowed' => $competitionUpdate['grundt'] ?? null,
                    'time_allowed_jumpoff' => $competitionUpdate['omh1t'] ?? null,
                    'time_allowed_round3' => $competitionUpdate['omh2t'] ?? null,
                    'time_allowed_round4' => $competitionUpdate['omg3t'] ?? null,
                    'time_allowed_round5' => $competitionUpdate['omg4t'] ?? null,
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
       <div style="display: flex; align-items: center; width: 100%; padding: 10px 0; border-bottom: 2px solid #e0e0e0; margin-bottom: 20px; position: relative;">
            <h3 style="margin: 0 auto; display: flex; align-items: center; gap: 15px;">
                <img src="H.png" height="60" class="me-2">
                <i class="fa-solid fa-arrow-right fa-xl me-2" style="color: #0f2f66;"></i>
                <img src="equipe.jpg" height="60">
            </h3>
            <span class="label label-success" style="position: absolute; right: 0;">v<?php echo $_ENV['VERSION'];?></span>
        </div>
        
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
                                <option value="238.2.1">Table A. Against the clock, no jump off (238.2.1.1)</option>
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
        const spinnerStyles = `
            <style>
            .progress-section {
                margin-bottom: 20px;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background-color: #f9f9f9;
            }

            .progress-section h5 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #333;
            }

            .progress-section .text-center {
                margin: 20px 0;
            }

            .progress-section .fa-spinner {
                color: #007bff;
            }

            .progress-item {
                padding: 8px 12px;
                margin: 5px 0;
                border-radius: 3px;
                transition: all 0.3s ease;
            }

            .progress-item.pending {
                background-color: #f0f0f0;
                color: #666;
            }

            .progress-item.success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .progress-item.failed {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            </style>
        `;

        // Ajouter les styles au document
        $(document).ready(function() {
            $('head').append(spinnerStyles);
        });

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

            function displayEventInfo(data) {
                // Construire les URLs possibles du logo
                var currentYear = new Date().getFullYear();
                var logoBaseUrl = 'https://results.hippodata.de/' + currentYear + '/' + data.event.id + '/evt_logo';
                var logoUrlJpg = logoBaseUrl + '.jpg';
                var logoUrlPng = logoBaseUrl + '.png';
                
                // Créer le HTML de base sans logo
                var htmlContent = '<div style="display: flex; gap: 20px; align-items: flex-start;">' +
                    '<div id="logoContainer" style="flex-shrink: 0; display: none;">' +
                        '<img id="eventLogo" alt="Event Logo" style="max-width: 150px; height: auto;">' +
                    '</div>' +
                    '<div>' +
                        '<h4 style="margin-top: 0;">' + data.event.name + '</h4>' +
                        '<p><strong>Event ID:</strong> ' + data.event.id + '</p>' +
                        '<p><strong>Venue:</strong> ' + data.event.venue + '</p>' +
                    '</div>' +
                '</div>';
                
                $('#eventInfo').html(htmlContent);
                
                // Fonction pour essayer de charger une image
                function tryLoadImage(url, onSuccess, onError) {
                    var img = new Image();
                    img.onload = function() {
                        onSuccess(url);
                    };
                    img.onerror = onError;
                    img.src = url;
                }
                
                // Essayer d'abord le JPG
                tryLoadImage(logoUrlJpg, 
                    function(url) {
                        // JPG trouvé
                        $('#eventLogo').attr('src', url);
                        $('#logoContainer').show();
                    },
                    function() {
                        // JPG non trouvé, essayer PNG
                        tryLoadImage(logoUrlPng,
                            function(url) {
                                // PNG trouvé
                                $('#eventLogo').attr('src', url);
                                $('#logoContainer').show();
                            },
                            function() {
                                // Aucun logo trouvé, supprimer le conteneur
                                $('#logoContainer').remove();
                            }
                        );
                    }
                );
                
                // Vider le conteneur des classes
                $('#classesTable').hide();
                $('#classesTableBody').empty();
                
                // Créer un nouveau conteneur pour l'affichage groupé par date
                if ($('#groupedClassesContainer').length === 0) {
                    $('#classesTable').after('<div id="groupedClassesContainer"></div>');
                }
                $('#groupedClassesContainer').empty();
                
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
                        
                        // Grouper les classes par date
                        const classesByDate = {};
                        data.classes.forEach(function(cls, index) {
                            if (!classesByDate[cls.date]) {
                                classesByDate[cls.date] = [];
                            }
                            cls.index = index; // Stocker l'index original
                            classesByDate[cls.date].push(cls);
                        });
                        
                        // Trier les dates
                        const sortedDates = Object.keys(classesByDate).sort();
                        
                        // Créer le HTML pour chaque groupe de date
                        let groupedHtml = '';
                        
                        sortedDates.forEach(function(date) {
                            // Formater la date de YYYY-MM-DD vers DD-MM-YYYY
                            const dateParts = date.split('-');
                            const formattedDate = dateParts[2] + '-' + dateParts[1] + '-' + dateParts[0];
                            
                            groupedHtml += '<div class="date-group" style="margin-bottom: 30px;">';
                            groupedHtml += '<h5 style="background: #f0f0f0; padding: 10px; margin: 20px 0 10px 0; border-left: 4px solid #0f2f66;">Date : ' + formattedDate + '</h5>';
                            groupedHtml += '<div class="classes-list" style="padding-left: 20px;">';
                            
                            classesByDate[date].forEach(function(cls) {
                                const foreignId = cls.id.toString();
                                const isClassImported = imported.classes.includes(foreignId);
                                const isStartlistImported = imported.startlists.includes(foreignId);
                                const isResultImported = imported.results.includes(foreignId);
                                
                                const classNameLower = cls.name.toLowerCase();
                                const teamKeywords = ['team', 'lln', 'nations cup', 'equipe', 'teams'];
                                const isTeamInName = teamKeywords.some(keyword => classNameLower.includes(keyword));
                                const teamIndicator = isTeamInName ? ' <img src="R.webp" width="35" style="vertical-align: middle;">' : '';
                                
                                groupedHtml += '<div class="class-item" style="margin: 10px 0; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">';
                                groupedHtml += '<div style="display: flex; align-items: center; gap: 10px;">';
                                
                                // Checkbox principal pour la classe
                                if (isClassImported) {
                                    groupedHtml += '<i class="fa-solid fa-circle-check fa-lg" style="color:rgb(24, 141, 8); width: 20px;" title="Class already imported"></i>';
                                } else {
                                    groupedHtml += '<input type="checkbox" class="class-checkbox" data-class-id="' + cls.id + '" data-index="' + cls.index + '">';
                                }
                                
                                groupedHtml += '<span style="flex: 1;"><strong>' + cls.nr + '</strong> ' + cls.name + teamIndicator + '</span>';
                                
                                // Indicateurs d'import pour startlist et results
                                groupedHtml += '<div class="import-indicators" style="display: flex; gap: 15px; margin-left: auto;">';
                                
                                // Startlist
                                if (isStartlistImported) {
                                    groupedHtml += '<span title="Startlist imported"><i class="fa-solid fa-users" style="color: rgb(24, 141, 8);"></i></span>';
                                } else {
                                    groupedHtml += '<span title="Import startlist"><input type="checkbox" class="startlist-import-grouped" data-class-id="' + cls.id + '" data-index="' + cls.index + '"> <i class="fa-solid fa-users" style="color: #999;"></i></span>';
                                }
                                
                                // Results
                                if (isResultImported) {
                                    groupedHtml += '<span title="Results imported"><i class="fa-solid fa-trophy" style="color: rgb(24, 141, 8);"></i></span>';
                                } else {
                                    groupedHtml += '<span title="Import results"><input type="checkbox" class="result-import-grouped" data-class-id="' + cls.id + '" data-index="' + cls.index + '"> <i class="fa-solid fa-trophy" style="color: #999;"></i></span>';
                                }
                                
                                // Team checkbox
                                groupedHtml += '<span title="Team competition"><input type="checkbox" class="team-class-grouped" data-class-id="' + cls.id + '" data-index="' + cls.index + '"' + (isTeamInName ? ' checked' : '') + '> <i class="fa-solid fa-people-group" style="color: #666;"></i></span>';
                                
                                // FEI Article dropdown
                                groupedHtml += '<select class="fei-article-grouped" data-class-id="' + cls.id + '" data-index="' + cls.index + '" title="FEI Article">' + $('#selectAllArticle').html() + '</select>';
                                
                                groupedHtml += '</div>';
                                groupedHtml += '</div>';
                                groupedHtml += '</div>';
                            });
                            
                            groupedHtml += '</div>';
                            groupedHtml += '<hr style="border: none; border-top: 2px dashed #ddd; margin: 20px 0;">';
                            groupedHtml += '</div>';
                        });
                        
                        // Ajouter des boutons de sélection globale
                        const selectionButtons = '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px;">' +
                            '<h6>Quick Selection:</h6>' +
                            '<div style="display: flex; gap: 10px; flex-wrap: wrap;">' +
                            '<button type="button" class="btn btn-sm btn-outline-primary" id="selectAllClassesGrouped">Select All Classes</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-info" id="selectAllStartlistsGrouped">Select All Startlists</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-success" id="selectAllResultsGrouped">Select All Results</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-warning" id="selectAllTeamGrouped">Mark All as Team</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" id="clearAllGrouped">Clear All</button>' +
                            '</div>' +
                            '<div style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">' +
                            '<label style="margin: 0;">Set all FEI Articles to:</label>' +
                            '<select id="selectAllArticleGrouped" class="form-control" style="width: 250px;">' + $('#selectAllArticle').html() + '</select>' +
                            '<button type="button" class="btn btn-sm btn-primary" id="applyAllArticlesGrouped">Apply</button>' +
                            '</div>' +
                            '</div>';
                        
                        $('#groupedClassesContainer').html(selectionButtons + groupedHtml);
                        
                        // Gérer les événements des boutons de sélection
                        $('#selectAllClassesGrouped').on('click', function() {
                            $('.class-checkbox').prop('checked', true);
                        });
                        
                        $('#selectAllStartlistsGrouped').on('click', function() {
                            $('.startlist-import-grouped').prop('checked', true);
                        });
                        
                        $('#selectAllResultsGrouped').on('click', function() {
                            $('.result-import-grouped').prop('checked', true);
                        });
                        
                        $('#selectAllTeamGrouped').on('click', function() {
                            $('.team-class-grouped').prop('checked', true);
                        });
                        
                        $('#clearAllGrouped').on('click', function() {
                            $('.class-checkbox, .startlist-import-grouped, .result-import-grouped, .team-class-grouped').prop('checked', false);
                        });
                        
                        // Gérer le bouton Apply pour les articles FEI
                        $('#applyAllArticlesGrouped').on('click', function() {
                            const selectedArticle = $('#selectAllArticleGrouped').val();
                            $('.fei-article-grouped').val(selectedArticle);
                        });
                        
                        // Stocker les données des classes pour référence
                        window.classesData = data.classes;
                    },
                    error: function() {
                        // En cas d'erreur, afficher quand même les classes sans statut d'import
                        // Code similaire mais sans les indicateurs d'import...
                        $('#loading-status').hide();
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
            // Modifier le gestionnaire du bouton import pour gérer le nouvel affichage groupé
            // Remplacer le gestionnaire $('#importSelectedButton').on('click', ...) par :

            $('#importSelectedButton').on('click', function() {
                const selections = [];
                
                // Utiliser les données stockées globalement
                if (window.classesData) {
                    window.classesData.forEach(function(classData, index) {
                        // Récupérer les valeurs depuis le nouvel affichage groupé
                        const classCheckbox = $('.class-checkbox[data-index="' + index + '"]');
                        const startlistCheckbox = $('.startlist-import-grouped[data-index="' + index + '"]');
                        const resultCheckbox = $('.result-import-grouped[data-index="' + index + '"]');
                        const teamCheckbox = $('.team-class-grouped[data-index="' + index + '"]');
                        const feiSelect = $('.fei-article-grouped[data-index="' + index + '"]');
                        
                        const selection = {
                            class_id: classData.id,
                            class_nr: classData.nr,
                            class_name: classData.name,
                            import_class: classCheckbox.length > 0 && classCheckbox.is(':checked'),
                            import_startlist: startlistCheckbox.length > 0 && startlistCheckbox.is(':checked'),
                            import_results: resultCheckbox.length > 0 && resultCheckbox.is(':checked'),
                            team_class: teamCheckbox.is(':checked'),
                            fei_article: feiSelect.val() || ''
                        };
                        
                        // Ajouter seulement si au moins une option est sélectionnée
                        if (selection.import_class || selection.import_startlist || selection.import_results) {
                            selections.push(selection);
                        }
                    });
                }
                
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
                
                // Créer une vraie barre de progression
                $('#importProgress').html(
                    '<div class="progress-container">' +
                    '<h4>Import Progress</h4>' +
                    '<div class="progress-bar-wrapper">' +
                    '<div class="progress-bar" id="mainProgressBar">' +
                    '<div class="progress-bar-fill" style="width: 0%"></div>' +
                    '<span class="progress-text">0%</span>' +
                    '</div>' +
                    '</div>' +
                    '<div class="progress-status" id="progressStatus">Initializing...</div>' +
                    '</div>'
                );
                
                // Déterminer ce qui doit être importé
                importOptions.classes = currentSelections.some(s => s.import_class);
                importOptions.startlists = currentSelections.some(s => s.import_startlist);
                importOptions.results = currentSelections.some(s => s.import_results);
                
                // Calculer le nombre total d'étapes
                let totalSteps = 0;
                let currentStep = 0;
                
                if (importOptions.classes) totalSteps++;
                if (importOptions.startlists) totalSteps++;
                if (importOptions.results) totalSteps++;
                
                // Fonction pour mettre à jour la progression
                function updateProgress(step, status) {
                    currentStep = step;
                    const percentage = Math.round((currentStep / totalSteps) * 100);
                    $('#mainProgressBar .progress-bar-fill').css('width', percentage + '%');
                    $('#mainProgressBar .progress-text').text(percentage + '%');
                    $('#progressStatus').text(status);
                }
                
                // Commencer l'import des classes
                if (importOptions.classes) {
                    updateProgress(0.5, 'Importing classes...');
                    
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
                                updateProgress(1, 'Classes imported successfully');
                                
                                // Ajouter les résultats détaillés sous la barre
                                displayImportResults(response);
                                
                                // Collecter les startlists et résultats à traiter
                                const allStartlistsToProcess = [];
                                const allResultsToProcess = [];
                                
                                currentSelections.forEach(function(sel) {
                                    if (sel.import_startlist) {
                                        allStartlistsToProcess.push({
                                            foreign_id: sel.class_id,
                                            class_id: sel.class_nr,
                                            name: sel.class_name,
                                            is_team: sel.team_class
                                        });
                                    }
                                    if (sel.import_results) {
                                        allResultsToProcess.push({
                                            foreign_id: sel.class_id,
                                            class_id: sel.class_nr,
                                            name: sel.class_name,
                                            is_team: sel.team_class
                                        });
                                    }
                                });
                                
                                // Continuer avec les startlists
                                if (allStartlistsToProcess.length > 0) {
                                    setTimeout(function() {
                                        processStartlistsWithProgress(response.event_id, allStartlistsToProcess, currentStep);
                                    }, 500);
                                } else if (allResultsToProcess.length > 0) {
                                    setTimeout(function() {
                                        processResultsWithProgress(response.event_id, allResultsToProcess, currentStep);
                                    }, 500);
                                }
                            } else {
                                updateProgress(currentStep, 'Error: ' + response.error);
                                $('#importProgress').append('<p class="alert alert-danger mt-3">Error: ' + response.error + '</p>');
                            }
                        },
                        error: function(xhr, status, error) {
                            updateProgress(currentStep, 'Request failed');
                            $('#importProgress').append('<p class="alert alert-danger mt-3">Request failed: ' + error + '</p>');
                        }
                    });
                } else if (importOptions.startlists) {
                    // Si on commence directement par les startlists
                    const startlistsToProcess = currentSelections
                        .filter(s => s.import_startlist)
                        .map(s => ({
                            foreign_id: s.class_id,
                            class_id: s.class_nr,
                            name: s.class_name,
                            is_team: s.team_class
                        }));
                    
                    processStartlistsWithProgress($('#showId').val(), startlistsToProcess, 0);
                } else if (importOptions.results) {
                    // Si on a seulement des résultats
                    const resultsToProcess = currentSelections
                        .filter(s => s.import_results)
                        .map(s => ({
                            foreign_id: s.class_id,
                            class_id: s.class_nr,
                            name: s.class_name,
                            is_team: s.team_class
                        }));
                    
                    processResultsWithProgress($('#showId').val(), resultsToProcess, 0);
                }
            }
            // Nouvelle fonction pour traiter les startlists avec progression
            function processStartlistsWithProgress(eventId, startlistsToProcess, previousStep) {
                const totalSteps = (importOptions.classes ? 1 : 0) + 
                                (importOptions.startlists ? 1 : 0) + 
                                (importOptions.results ? 1 : 0);
                const currentStepBase = previousStep + 0.5;
                
                function updateProgress(status) {
                    const percentage = Math.round((currentStepBase / totalSteps) * 100);
                    $('#mainProgressBar .progress-bar-fill').css('width', percentage + '%');
                    $('#mainProgressBar .progress-text').text(percentage + '%');
                    $('#progressStatus').text(status);
                }
                
                updateProgress('Fetching startlists from Hippodata...');
                
                // Ajouter la section des startlists
                $('#importProgress').append(
                    '<div class="progress-section" id="startlistsSection">' +
                    '<h5>Startlists Import</h5>' +
                    '<div id="startlistProgress"></div>' +
                    '</div>'
                );
                
                const startlistsWithTeamInfo = startlistsToProcess;
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_startlists',
                        event_id: eventId,
                        competitions: JSON.stringify(startlistsWithTeamInfo),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            updateProgress('Processing startlists data...');
                            displayStartlistResults(response);
                            
                            if (response.batchData && response.batchData.length > 0) {
                                updateProgress('Sending startlists to Equipe...');
                                
                                importBatchesToEquipe(response.batchData, function() {
                                    // Mise à jour finale pour les startlists
                                    const finalStep = previousStep + 1;
                                    const percentage = Math.round((finalStep / totalSteps) * 100);
                                    $('#mainProgressBar .progress-bar-fill').css('width', percentage + '%');
                                    $('#mainProgressBar .progress-text').text(percentage + '%');
                                    $('#progressStatus').text('Startlists imported successfully');
                                    
                                    // Continuer avec les résultats si nécessaire
                                    if (importOptions.results) {
                                        const resultsToProcess = currentSelections
                                            .filter(s => s.import_results)
                                            .map(s => ({
                                                foreign_id: s.class_id,
                                                class_id: s.class_nr,
                                                name: s.class_name,
                                                is_team: s.team_class
                                            }));
                                        
                                        if (resultsToProcess.length > 0) {
                                            setTimeout(function() {
                                                processResultsWithProgress(eventId, resultsToProcess, finalStep);
                                            }, 500);
                                        }
                                    } else {
                                        // Import terminé
                                        $('#progressStatus').text('Import completed successfully!');
                                        showFinalSummary();
                                    }
                                });
                            }
                        } else {
                            updateProgress('Error processing startlists');
                            $('#startlistProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        updateProgress('Failed to fetch startlists');
                        $('#startlistProgress').html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
                    }
                });
            }

            // Nouvelle fonction pour traiter les résultats avec progression
            function processResultsWithProgress(eventId, resultsToProcess, previousStep) {
                const totalSteps = (importOptions.classes ? 1 : 0) + 
                                (importOptions.startlists ? 1 : 0) + 
                                (importOptions.results ? 1 : 0);
                const currentStepBase = previousStep + 0.5;
                
                function updateProgress(status) {
                    const percentage = Math.round((currentStepBase / totalSteps) * 100);
                    $('#mainProgressBar .progress-bar-fill').css('width', percentage + '%');
                    $('#mainProgressBar .progress-text').text(percentage + '%');
                    $('#progressStatus').text(status);
                }
                
                updateProgress('Fetching results from Hippodata...');
                
                // Ajouter la section des résultats
                $('#importProgress').append(
                    '<div class="progress-section" id="resultsSection">' +
                    '<h5>Results Import</h5>' +
                    '<div id="resultsProgress"></div>' +
                    '</div>'
                );
                
                const resultsWithTeamInfo = resultsToProcess;
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_results',
                        event_id: eventId,
                        competitions: JSON.stringify(resultsWithTeamInfo),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            updateProgress('Processing results data...');
                            displayResultsProgress(response);
                            
                            if (response.batchData && response.batchData.length > 0) {
                                updateProgress('Sending results to Equipe...');
                                
                                importResultsBatchesToEquipeWithProgress(response.batchData, function() {
                                    // Mise à jour finale
                                    const finalStep = previousStep + 1;
                                    const percentage = Math.round((finalStep / totalSteps) * 100);
                                    $('#mainProgressBar .progress-bar-fill').css('width', percentage + '%');
                                    $('#mainProgressBar .progress-text').text(percentage + '%');
                                    $('#progressStatus').text('Import completed successfully!');
                                    
                                    showFinalSummary();
                                });
                            }
                        } else {
                            updateProgress('Error processing results');
                            $('#resultsProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        updateProgress('Failed to fetch results');
                        $('#resultsProgress').html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
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
                // Ajouter le spinner pour les startlists
                $('#importProgress').append(
                    '<div class="progress-section" id="startlistsSection">' +
                    '<h5>Processing Startlists</h5>' +
                    '<div class="text-center" id="startlistSpinner">' +
                    '<i class="fa fa-spinner fa-spin fa-2x text-primary"></i>' +
                    '<p>Fetching startlists data from Hippodata...</p>' +
                    '</div>' +
                    '<div id="startlistProgress" style="display: none;"></div>' +
                    '</div>'
                );
                
                // Ajouter l'information is_team depuis les sélections originales
                const startlistsWithTeamInfo = startlistsToProcess.map(function(startlist) {
                    const originalSelection = currentSelections.find(s => s.class_id == startlist.foreign_id);
                    return {
                        ...startlist,
                        is_team: originalSelection ? originalSelection.team_class : false
                    };
                });
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_startlists',
                        event_id: eventId,
                        competitions: JSON.stringify(startlistsWithTeamInfo),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        // Masquer le spinner et afficher les résultats
                        $('#startlistSpinner').hide();
                        $('#startlistProgress').show();
                        
                        if (response.success) {
                            displayStartlistResults(response);
                            
                            // Si on a des données à envoyer vers Equipe
                            if (response.batchData && response.batchData.length > 0) {
                                // Ajouter un spinner pour l'envoi vers Equipe
                                $('#startlistProgress').prepend(
                                    '<div class="text-center" id="startlistEquipeSpinner">' +
                                    '<i class="fa fa-spinner fa-spin fa-2x text-primary"></i>' +
                                    '<p>Sending data to Equipe...</p>' +
                                    '</div>'
                                );
                                
                                importBatchesToEquipe(response.batchData, function() {
                                    // Masquer le spinner d'envoi
                                    $('#startlistEquipeSpinner').remove();
                                    
                                    // Après les startlists, traiter les résultats si nécessaire
                                    if (importOptions.results) {
                                        const resultsToProcess = currentSelections
                                            .filter(s => s.import_results)
                                            .map(s => ({
                                                foreign_id: s.class_id,
                                                class_id: s.class_nr,
                                                name: s.class_name,
                                                is_team: s.team_class
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
                        $('#startlistSpinner').hide();
                        $('#startlistProgress').show().html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
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
                        
                        if (comp.is_team) {
                            html += comp.teams_count + ' teams, ';
                        }
                        
                        html += comp.people_count + ' riders, ' + comp.horses_count + ' horses, ' + comp.starts_count + ' starts';
                        html += '</div>';
                    });
                }
                
                $('#startlistProgress').html(html);
            }
            
            // Traiter les résultats
            function processResults(eventId, resultsToProcess) {
                // Ajouter le spinner pour les résultats
                $('#importProgress').append(
                    '<div class="progress-section" id="resultsSection">' +
                    '<h5>Processing Results</h5>' +
                    '<div class="text-center" id="resultsSpinner">' +
                    '<i class="fa fa-spinner fa-spin fa-2x text-primary"></i>' +
                    '<p>Fetching results data from Hippodata...</p>' +
                    '</div>' +
                    '<div id="resultsProgress" style="display: none;"></div>' +
                    '</div>'
                );
                
                // Ajouter l'information is_team depuis les sélections originales
                const resultsWithTeamInfo = resultsToProcess.map(function(result) {
                    const originalSelection = currentSelections.find(s => s.class_id == result.foreign_id);
                    return {
                        ...result,
                        is_team: originalSelection ? originalSelection.team_class : false
                    };
                });
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_results',
                        event_id: eventId,
                        competitions: JSON.stringify(resultsWithTeamInfo),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        // Masquer le spinner et afficher les résultats
                        $('#resultsSpinner').hide();
                        $('#resultsProgress').show();
                        
                        if (response.success) {
                            displayResultsProgress(response);
                            
                            // Si on a des données à envoyer vers Equipe
                            if (response.batchData && response.batchData.length > 0) {
                                // Ajouter un spinner pour l'envoi vers Equipe
                                $('#resultsProgress').prepend(
                                    '<div class="text-center" id="resultsEquipeSpinner">' +
                                    '<i class="fa fa-spinner fa-spin fa-2x text-primary"></i>' +
                                    '<p>Sending results to Equipe...</p>' +
                                    '</div>'
                                );
                                
                                importResultsBatchesToEquipe(response.batchData);
                            }
                        } else {
                            $('#resultsProgress').html('<p class="alert alert-danger">Error: ' + response.error + '</p>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#resultsSpinner').hide();
                        $('#resultsProgress').show().html('<p class="alert alert-danger">Request failed: ' + error + '</p>');
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
                
                // Consolider TOUTES les données de tous les batches
                let allPeople = [];
                let allHorses = [];
                let allClubs = [];
                let competitionStarts = []; // Stocker les starts par compétition avec leurs teams
                
                // Collecter toutes les données
                batchDataArray.forEach(function(batch) {
                    if (batch.data.people && batch.data.people.records) {
                        allPeople = allPeople.concat(batch.data.people.records);
                    }
                    if (batch.data.horses && batch.data.horses.records) {
                        allHorses = allHorses.concat(batch.data.horses.records);
                    }
                    if (batch.data.clubs && batch.data.clubs.records) {
                        allClubs = allClubs.concat(batch.data.clubs.records);
                    }
                    if (batch.data.starts) {
                        competitionStarts.push({
                            competition: batch.competition,
                            competition_foreign_id: batch.competition_foreign_id,
                            is_team: batch.is_team,
                            starts: batch.data.starts,
                            teams: batch.data.teams || null, // Inclure les teams si présentes
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
                
                // Éliminer les doublons pour clubs (par foreign_id)
                const uniqueClubs = [];
                const seenClubsForeignIds = new Set();
                allClubs.forEach(function(club) {
                    if (!seenClubsForeignIds.has(club.foreign_id)) {
                        seenClubsForeignIds.add(club.foreign_id);
                        uniqueClubs.push(club);
                    }
                });
                
                debugLog('Total unique people to import:', uniquePeople.length);
                debugLog('Total unique horses to import:', uniqueHorses.length);
                debugLog('Total unique clubs to import:', uniqueClubs.length);
                
                // Étape 1: Importer d'abord tous les clubs, people et horses
                const transactionUuid1 = generateUuid();
                const consolidatedBatch = {};
                
                // IMPORTANT: L'ordre est important - clubs en premier
                if (uniqueClubs.length > 0) {
                    consolidatedBatch.clubs = {
                        unique_by: 'foreign_id',
                        records: uniqueClubs
                    };
                }
                
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
                
                // Si on a des données à importer
                if (Object.keys(consolidatedBatch).length > 0) {
                    let importMessage = 'Importing ';
                    const parts = [];
                    if (uniqueClubs.length > 0) parts.push('clubs');
                    if (uniquePeople.length > 0) parts.push('riders');
                    if (uniqueHorses.length > 0) parts.push('horses');
                    importMessage += parts.join(', ') + '...';
                    
                    $('#startlistProgress').prepend('<div class="progress-item pending">' + importMessage + '</div>');
                    
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
                                debugLog('Clubs, people and horses imported successfully');
                                let successMessage = '';
                                const successParts = [];
                                if (uniqueClubs.length > 0) successParts.push(uniqueClubs.length + ' clubs');
                                if (uniquePeople.length > 0) successParts.push(uniquePeople.length + ' riders');
                                if (uniqueHorses.length > 0) successParts.push(uniqueHorses.length + ' horses');
                                successMessage = successParts.join(', ') + ' imported successfully';
                                
                                $('#startlistProgress .progress-item:first').removeClass('pending').addClass('success').html(successMessage);
                                
                                // Étape 2: Importer les teams et starts pour chaque compétition
                                setTimeout(importTeamsAndStartlists, 500);
                            } else {
                                $('#startlistProgress .progress-item:first').removeClass('pending').addClass('failed').html('Failed to import basic data');
                                debugLog('Failed to import:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#startlistProgress .progress-item:first').removeClass('pending').addClass('failed').html('Failed to import basic data');
                            debugLog('Request failed:', error);
                        }
                    });
                } else {
                    // Si pas de clubs/people/horses à importer, passer directement aux teams et starts
                    importTeamsAndStartlists();
                }
                
                // Fonction pour importer les teams et startlists
                function importTeamsAndStartlists() {
                    let successCount = 0;
                    let failCount = 0;
                    let processed = 0;
                    const total = competitionStarts.length;
                    
                    competitionStarts.forEach(function(compStarts) {
                        const transactionUuid = generateUuid();
                        const batchData = {};
                        
                        // Pour les compétitions par équipe, inclure les teams
                        if (compStarts.is_team && compStarts.teams) {
                            batchData.teams = compStarts.teams;
                        }
                        
                        // Ajouter les starts
                        batchData.starts = compStarts.starts;
                        
                        debugLog('Sending batch for:', compStarts.competition, 'with teams:', compStarts.teams ? 'yes' : 'no');
                        
                        // Utiliser l'action proxy pour éviter CORS
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                action: 'send_batch_to_equipe',
                                batch_data: JSON.stringify(batchData),
                                api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                                meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                                transaction_uuid: transactionUuid
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    successCount++;
                                    $('#startlist-' + compStarts.competition_foreign_id).removeClass('pending').addClass('success');
                                    if (compStarts.teams && compStarts.teams.records) {
                                        $('#startlist-' + compStarts.competition_foreign_id).append(' <small>(' + compStarts.teams.records.length + ' teams)</small>');
                                    }
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
            // Modifier importResultsBatchesToEquipe pour accepter un callback
            function importResultsBatchesToEquipeWithProgress(batchDataArray, onCompleteCallback) {
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
                        if (typeof onCompleteCallback === 'function') {
                            onCompleteCallback();
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
                        // Masquer le spinner des résultats
                        $('#resultsEquipeSpinner').remove();
                        
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