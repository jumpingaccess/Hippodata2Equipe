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
    
    if ($_POST['action'] === 'import_classes') {
        $feiId = $_POST['fei_id'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $meetingUrl = $_POST['meeting_url'] ?? '';
        
        if (empty($feiId)) {
            echo json_encode(['success' => false, 'error' => 'FEI ID is required']);
            exit;
        }
        
        try {
            // 1. Récupérer les données depuis Hippodata
            $hippodataUrl = "https://api.hippo-server.net/scoring/event/{$feiId}";
            
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
            
            // 2. Transformer les données pour Equipe
            $competitions = [];
            $counter = 1; // Compteur pour clabb
            
            foreach ($hippodataData['CLASSES']['CLASS'] ?? [] as $class) {
                $name = !empty($class['NAME']) ? $class['NAME'] : $class['SPONSOR'];
                
                $competitions[] = [
                    'foreign_id' => (string)$class['ID'], // Convertir en string
                    'clabb' => 'HD-' . $counter, // Compteur d'épreuve avec préfixe HD-
                    'klass' => $name, // Renommé de 'name' vers 'klass'
                    'oeverskr1' => $name, // Même valeur que klass
                    'datum' => $class['DATE'], // Renommé de 'starts_on' vers 'datum'
                    'tavlingspl' => $class['CATEGORY'] ?? '', // Renommé de 'arena' vers 'tavlingspl'
                    'z' => 'H',
                    'x' => 'I',
                    'premie_curr' => $class['PRIZE']['CURRENCY'] ?? 'EUR',
                    'prsum1' => $class['PRIZE']['MONEY'] ?? 0
                ];
                
                $counter++;
            }
            
            // 3. Envoyer vers Equipe via Batch API
            $batchData = [
                'competitions' => [
                    'unique_by' => 'foreign_id',
                    'skip_user_changed' => true,
                    'records' => $competitions
                ]
            ];
            
            // Générer un UUID pour la transaction
            $transactionUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            
            $batchUrl = $meetingUrl . '/batch';
            
            // JSON qui sera envoyé à Equipe
            $jsonToSend = json_encode($batchData);
            
            $ch = curl_init($batchUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonToSend);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Api-Key: {$apiKey}",
                "X-Transaction-Uuid: {$transactionUuid}",
                "Accept: application/json",
                "Content-Type: application/json"
            ]);
            
            $equipeResponse = curl_exec($ch);
            $equipeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($equipeHttpCode !== 200 && $equipeHttpCode !== 201) {
                // En cas d'erreur, on retourne quand même le JSON envoyé pour debug
                $competitionsWithIds = [];
                foreach ($competitions as $index => $comp) {
                    $hippodataClass = $hippodataData['CLASSES']['CLASS'][$index] ?? null;
                    $competitionsWithIds[] = [
                        'foreign_id' => $comp['foreign_id'],
                        'class_id' => $hippodataClass['NR'] ?? $hippodataClass['ID'] ?? '', // Utiliser NR en priorité
                        'hippodata_id' => $hippodataClass['ID'] ?? '',
                        'name' => $comp['klass']
                    ];
                }
                echo json_encode([
                    'success' => false, 
                    'error' => "Failed to send to Equipe (HTTP {$equipeHttpCode})" . ($curlError ? " - {$curlError}" : ""),
                    'eventId' => $hippodataData['EVENT']['ID'] ?? $feiId,
 
                    'competitions' => $competitionsWithIds,
                    'transactionUuid' => $transactionUuid,
                    'equipeResponse' => json_decode($equipeResponse, true) ?: $equipeResponse,
                    'httpCode' => $equipeHttpCode,
                    'batchUrl' => $batchUrl
                ]);
                exit;
            }
            
            // Préparer la réponse avec les IDs corrects
            $competitionsWithIds = [];
            foreach ($competitions as $index => $comp) {
                $hippodataClass = $hippodataData['CLASSES']['CLASS'][$index] ?? null;
                $competitionsWithIds[] = [
                    'foreign_id' => $comp['foreign_id'],
                    'class_id' => $hippodataClass['NR'] ?? $hippodataClass['ID'] ?? '', // Utiliser NR si disponible
                    'name' => $comp['klass']
                ];
            }
            
            echo json_encode([
                'success' => true, 
                'message' => count($competitions) . ' competitions imported successfully',
                'eventId' => $hippodataData['EVENT']['ID'] ?? $feiId,
                'competitions' => $competitionsWithIds,
                'transactionUuid' => $transactionUuid,
                'equipeResponse' => json_decode($equipeResponse, true)
            ]);
            
        } catch (Exception $e) {
            // En cas d'exception, essayer de retourner le maximum d'infos pour debug
            $response = ['success' => false, 'error' => $e->getMessage()];
            
            // Si on a des competitions, les inclure même en cas d'erreur
            if (isset($competitions)) {
                $response['competitions'] = $competitions;
            }
            
            // Si on a le batchData, l'inclure même en cas d'erreur
            if (isset($batchData)) {
                $response['jsonSent'] = $batchData;
            }
            
            echo json_encode($response);
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
    
    // Nouvelle action pour importer les résultats
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
    
    // Nouvelle action pour importer les startlists
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
    <link rel="stylesheet" href="css/custom.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

</head>
<body class="extension">
    <div class="import-container">
        <h3>Import Classes from Hippodata</h3>
        
        <div id="alertMessage" style="display: none;"></div>
        
        <form id="importForm">
            <div class="form-group">
                <label for="feiId">FEI ID:</label>
                <input type="text" id="feiId" name="fei_id" class="form-control" placeholder="Enter FEI Event ID" required>
            </div>
            
            <div class="form-group">
                <label>Import options:</label>
                <div style="margin-left: 20px;">
                    <div style="margin-bottom: 10px;">
                        <label style="font-weight: normal;">
                            <input type="checkbox" id="importClasses" name="import_classes" checked>
                            Import classes (competitions)
                        </label>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="font-weight: normal;">
                            <input type="checkbox" id="importStartlists" name="import_startlists">
                            Import startlists (riders & horses)
                        </label>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="font-weight: normal;">
                            <input type="checkbox" id="importResults" name="import_results">
                            Import results
                        </label>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" id="importButton">
                Start Import Process
            </button>
        </form>
        
        <div id="results" class="result-list" style="display: none;">
            <h4>Imported Competitions:</h4>
            <ul id="competitionList"></ul>
            
            <button type="button" class="btn btn-primary" id="importStartlistsButton" style="display: none; margin-top: 20px;">
                Import Startlists for All Competitions
            </button>
        </div>
        
        <div id="startlistResults" class="result-list" style="display: none; margin-top: 20px;">
            <h4>Startlist Import Results:</h4>
            <ul id="startlistResultList"></ul>
            
            <?php if ($debugMode): ?>
            <div id="detailedResults" style="display: none; margin-top: 20px;">
                <h5>Detailed Import Information:</h5>
                <div id="detailedContent"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Debug area -->
        <?php if ($debugMode): ?>
        <div id="debugArea" style="margin-top: 20px; padding: 10px; background: #f0f0f0;">
            <h5>Debug Info:</h5>
            <pre id="debugContent"></pre>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        let savedEventId = null;
        let savedCompetitions = [];
        const debugMode = <?php echo $debugMode ? 'true' : 'false'; ?>;
        
        // Fonction pour log conditionnel
        function debugLog(...args) {
            if (debugMode) {
                console.log(...args);
            }
        }
        
        $(document).ready(function() {
            // Variables globales pour stocker les options sélectionnées
            let importOptions = {
                classes: false,
                startlists: false,
                results: false
            };
            
            $('#importForm').on('submit', function(e) {
                e.preventDefault();
                
                // Récupérer les options cochées
                importOptions.classes = $('#importClasses').is(':checked');
                importOptions.startlists = $('#importStartlists').is(':checked');
                importOptions.results = $('#importResults').is(':checked');
                
                // Vérifier qu'au moins une option est cochée
                if (!importOptions.classes && !importOptions.startlists && !importOptions.results) {
                    const alertDiv = $('#alertMessage');
                    alertDiv.removeClass('alert-success alert-info').addClass('alert alert-danger');
                    alertDiv.text('Please select at least one import option.').show();
                    return;
                }
                
                const feiId = $('#feiId').val();
                const button = $('#importButton');
                const alertDiv = $('#alertMessage');
                const resultsDiv = $('#results');
                
                // Reset UI
                alertDiv.hide();
                resultsDiv.hide();
                $('#startlistResults').hide();
                
                // Disable button and show loading
                button.prop('disabled', true).text('Processing...');
                
                // Show info message
                alertDiv.removeClass('alert-success alert-danger').addClass('alert alert-info');
                alertDiv.text('Connecting to Hippodata...').show();
                
                // Si on veut importer les classes
                if (importOptions.classes) {
                    // Make AJAX request pour importer les classes
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'import_classes',
                            fei_id: feiId,
                            api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                            meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                        },
                        dataType: 'json',
                        success: function(response) {
                            debugLog('Import classes response:', response);
                            
                            // Afficher les infos de debug si mode debug activé
                            if (debugMode && $('#debugArea').length) {
                                $('#debugContent').text(JSON.stringify(response, null, 2));
                            }
                            
                            if (response.success || (response.eventId && response.competitions)) {
                                // Si on a récupéré les données même en cas d'erreur
                                savedEventId = response.eventId;
                                savedCompetitions = response.competitions;
                                
                                if (response.success) {
                                    alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                                    alertDiv.text(response.message).show();
                                } else {
                                    alertDiv.removeClass('alert-info alert-success').addClass('alert-warning');
                                    alertDiv.text('Failed to import to Equipe, but data retrieved from Hippodata').show();
                                }
                                
                                // Afficher la liste des compétitions
                                const competitionList = $('#competitionList');
                                competitionList.empty();
                                
                                if (response.competitions && response.competitions.length > 0) {
                                    response.competitions.forEach(function(comp) {
                                        competitionList.append(
                                            '<li>' + comp.name + '</li>'
                                        );
                                    });
                                } else {
                                    competitionList.append('<li>No competition details available</li>');
                                }
                                
                                resultsDiv.show();
                                
                                // Si on doit aussi importer les startlists
                                if (importOptions.startlists && savedEventId && savedCompetitions.length > 0) {
                                    // Importer automatiquement les startlists
                                    setTimeout(function() {
                                        importStartlistsAutomatically();
                                    }, 1000);
                                } else if (importOptions.results && savedEventId && savedCompetitions.length > 0) {
                                    // Si on doit importer seulement les résultats (sans startlists)
                                    setTimeout(function() {
                                        importResultsAutomatically();
                                    }, 1000);
                                } else {
                                    // Sinon, afficher le bouton pour import manuel
                                    $('#importStartlistsButton').show();
                                    button.prop('disabled', false).text('Start Import Process');
                                }
                                
                            } else {
                                alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                                alertDiv.text('Error: ' + response.error).show();
                                button.prop('disabled', false).text('Start Import Process');
                            }
                        },
                        error: function(xhr, status, error) {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                            alertDiv.text('Request failed: ' + error).show();
                            button.prop('disabled', false).text('Start Import Process');
                        }
                    });
                } else if (importOptions.startlists || importOptions.results) {
                    // Si on veut importer les startlists et/ou résultats sans les classes
                    // Il faut d'abord récupérer les données depuis Hippodata
                    alertDiv.text('Fetching competition data from Hippodata...').show();
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            action: 'import_classes',
                            fei_id: feiId,
                            api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                            meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>',
                            skip_equipe_import: true // Flag pour ne pas envoyer à Equipe
                        },
                        dataType: 'json',
                        success: function(response) {
                            if ((response.success || response.competitions) && response.eventId) {
                                savedEventId = response.eventId;
                                savedCompetitions = response.competitions;
                                
                                alertDiv.removeClass('alert-danger').addClass('alert-info');
                                
                                if (importOptions.startlists) {
                                    alertDiv.text('Competition data retrieved. Fetching startlists...').show();
                                    // Importer directement les startlists
                                    importStartlistsAutomatically();
                                } else if (importOptions.results) {
                                    alertDiv.text('Competition data retrieved. Fetching results...').show();
                                    // Importer directement les résultats
                                    importResultsAutomatically();
                                }
                            } else {
                                alertDiv.removeClass('alert-info').addClass('alert-danger');
                                alertDiv.text('Error fetching competition data: ' + (response.error || 'Unknown error')).show();
                                button.prop('disabled', false).text('Start Import Process');
                            }
                        },
                        error: function(xhr, status, error) {
                            alertDiv.removeClass('alert-info').addClass('alert-danger');
                            alertDiv.text('Request failed: ' + error).show();
                            button.prop('disabled', false).text('Start Import Process');
                        }
                    });
                }
            });
            
            // Fonction pour importer automatiquement les startlists
           function importStartlistsAutomatically() {
                const alertDiv = $('#alertMessage');
                const startlistResultsDiv = $('#startlistResults');
                
                alertDiv.removeClass('alert-success alert-danger').addClass('alert alert-info');
                alertDiv.text('Fetching startlists from Hippodata...').show();
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_startlists',
                        event_id: savedEventId,
                        competitions: JSON.stringify(savedCompetitions),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                            alertDiv.text('Startlists fetched successfully. Importing to Equipe...').show();
                            
                            // Show results
                            const resultList = $('#startlistResultList');
                            resultList.empty();
                            
                            if (response.processedCompetitions) {
                                let detailedHtml = '';
                                
                                response.processedCompetitions.forEach(function(comp) {
                                    let statusText = comp.people_count + ' new riders, ' +
                                        comp.horses_count + ' new horses, ' +
                                        comp.starts_count + ' starts';
                                    
                                    resultList.append(
                                        '<li><strong>' + comp.name + '</strong>: ' + statusText +
                                        '<span class="import-status status-pending" id="status-' + comp.foreign_id + '">Pending</span></li>'
                                    );
                                    
                                    // Build detailed view
                                    detailedHtml += '<div class="competition-details">';
                                    detailedHtml += '<h6>' + comp.name + '</h6>';
                                    
                                    if (comp.people_count > 0) {
                                        detailedHtml += '<div class="detail-section">';
                                        detailedHtml += '<strong>New Riders (' + comp.people_count + '):</strong>';
                                        detailedHtml += '<ul>';
                                        comp.people.forEach(function(person) {
                                            detailedHtml += '<li>' + person + '</li>';
                                        });
                                        detailedHtml += '</ul></div>';
                                    }
                                    
                                    if (comp.horses_count > 0) {
                                        detailedHtml += '<div class="detail-section">';
                                        detailedHtml += '<strong>New Horses (' + comp.horses_count + '):</strong>';
                                        detailedHtml += '<ul>';
                                        comp.horses.forEach(function(horse) {
                                            detailedHtml += '<li>' + horse + '</li>';
                                        });
                                        detailedHtml += '</ul></div>';
                                    }
                                    
                                    detailedHtml += '<div class="detail-section">';
                                    detailedHtml += '<strong>Total Starts: ' + comp.starts_count + '</strong>';
                                    detailedHtml += '</div>';
                                    
                                    detailedHtml += '</div>';
                                });
                                
                                if (debugMode && $('#detailedContent').length) {
                                    $('#detailedContent').html(detailedHtml);
                                    $('#detailedResults').show();
                                }
                                startlistResultsDiv.show();
                                
                                // Now send each batch to Equipe
                                if (response.batchData && response.batchData.length > 0) {
                                    // Modifier pour vérifier si on doit aussi importer les résultats
                                    importBatchesToEquipe(response.batchData, function() {
                                        // Callback après l'import des startlists
                                        if (importOptions.results) {
                                            // Si l'option results est cochée, importer les résultats
                                            setTimeout(function() {
                                                importResultsAutomatically();
                                            }, 1000);
                                        }
                                    });
                                } else {
                                    $('#importButton').prop('disabled', false).text('Start Import Process');
                                }
                            }
                        } else {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                            alertDiv.text('Error: ' + response.error).show();
                            $('#importButton').prop('disabled', false).text('Start Import Process');
                        }
                    },
                    error: function(xhr, status, error) {
                        alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                        alertDiv.text('Request failed: ' + error).show();
                        $('#importButton').prop('disabled', false).text('Start Import Process');
                    }
                });
            }
            function importResultsAutomatically() {
                const alertDiv = $('#alertMessage');
                
                alertDiv.removeClass('alert-success alert-danger').addClass('alert alert-info');
                alertDiv.text('Fetching results from Hippodata...').show();
                
                // Créer une nouvelle section pour les résultats
                if ($('#resultsImportSection').length === 0) {
                    $('#startlistResults').after(
                        '<div id="resultsImportSection" class="result-list" style="margin-top: 20px;">' +
                        '<h4>Results Import:</h4>' +
                        '<ul id="resultsImportList"></ul>' +
                        '</div>'
                    );
                }
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_results',
                        event_id: savedEventId,
                        competitions: JSON.stringify(savedCompetitions),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        debugLog('Import results response:', response);
                        
                        if (response.success) {
                            alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                            alertDiv.text('Results fetched successfully. Importing to Equipe...').show();
                            
                            // Show results summary
                            const resultsList = $('#resultsImportList');
                            resultsList.empty();
                            
                            if (response.processedCompetitions) {
                                response.processedCompetitions.forEach(function(comp) {
                                    let statusText = comp.results_count + ' results';
                                    
                                    // Ajouter les temps autorisés
                                    let timeInfo = [];
                                    if (comp.time_allowed) {
                                        timeInfo.push('R1: ' + comp.time_allowed + 's');
                                    }
                                    if (comp.time_allowed_jumpoff) {
                                        timeInfo.push('JO: ' + comp.time_allowed_jumpoff + 's');
                                    }
                                    if (comp.time_allowed_round3) {
                                        timeInfo.push('R3: ' + comp.time_allowed_round3 + 's');
                                    }
                                    if (comp.time_allowed_round4) {
                                        timeInfo.push('R4: ' + comp.time_allowed_round4 + 's');
                                    }
                                    if (comp.time_allowed_round5) {
                                        timeInfo.push('R5: ' + comp.time_allowed_round5 + 's');
                                    }
                                    if (comp.time_allowed_round6) {
                                        timeInfo.push('R6: ' + comp.time_allowed_round6 + 's');
                                    }
                                    
                                    if (timeInfo.length > 0) {
                                        statusText += ', Time allowed: ' + timeInfo.join(' / ');
                                    }
                                    
                                    // Ajouter le statut de la compétition
                                    if (comp.status) {
                                        statusText += ' [' + comp.status + ']';
                                    }
                                    
                                    resultsList.append(
                                        '<li><strong>' + comp.name + '</strong>: ' + statusText +
                                        '<span class="import-status status-pending" id="result-status-' + comp.foreign_id + '">Pending</span></li>'
                                    );
                                });
                            }
                            
                            // Import results to Equipe
                            if (response.batchData && response.batchData.length > 0) {
                                importResultsBatchesToEquipe(response.batchData);
                            } else {
                                alertDiv.text('No results to import.').show();
                                $('#importButton').prop('disabled', false).text('Start Import Process');
                            }
                        } else {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                            alertDiv.text('Error fetching results: ' + response.error).show();
                            $('#importButton').prop('disabled', false).text('Start Import Process');
                        }
                    },
                    error: function(xhr, status, error) {
                        alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                        alertDiv.text('Failed to fetch results: ' + error).show();
                        $('#importButton').prop('disabled', false).text('Start Import Process');
                    }
                });
            }

            // 3. Ajouter la fonction pour envoyer les résultats vers Equipe
            function importResultsBatchesToEquipe(batchDataArray) {
                const alertDiv = $('#alertMessage');
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
                                $('#result-status-' + batch.competition_foreign_id)
                                    .removeClass('status-pending status-error')
                                    .addClass('status-success')
                                    .text('Success');
                            } else {
                                failCount++;
                                $('#result-status-' + batch.competition_foreign_id)
                                    .removeClass('status-pending status-success')
                                    .addClass('status-error')
                                    .text('Failed');
                                
                                debugLog('Results import failed for:', batch.competition);
                                debugLog('Response:', response);
                            }
                            
                            processed++;
                            checkComplete();
                        },
                        error: function(xhr, status, error) {
                            failCount++;
                            $('#result-status-' + batch.competition_foreign_id)
                                .removeClass('status-pending status-success')
                                .addClass('status-error')
                                .text('Failed');
                            
                            debugLog('Request failed for results:', batch.competition);
                            debugLog('Error:', error);
                            
                            processed++;
                            checkComplete();
                        }
                    });
                });
                
                function checkComplete() {
                    if (processed === total) {
                        // Show final summary
                        let summaryHtml = '<div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">';
                        summaryHtml += '<h5>Results Import Summary</h5>';
                        
                        if (failCount === 0) {
                            alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                            alertDiv.text('All results imported successfully!').show();
                            summaryHtml += '<p class="text-success">✓ All ' + successCount + ' competition results imported successfully!</p>';
                        } else {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-warning');
                            alertDiv.text(successCount + ' results imported successfully, ' + failCount + ' failed.').show();
                            summaryHtml += '<p class="text-warning">⚠ ' + successCount + ' competition results imported successfully, ' + failCount + ' failed.</p>';
                        }
                        
                        // Count total results imported
                        let totalResults = 0;
                        batchDataArray.forEach(function(batch) {
                            if (batch.data.starts && batch.data.starts.records) {
                                totalResults += batch.data.starts.records.length;
                            }
                        });
                        
                        summaryHtml += '<div><strong>Total imported:</strong></div>';
                        summaryHtml += '<ul>';
                        summaryHtml += '<li>' + totalResults + ' individual results</li>';
                        summaryHtml += '<li>' + successCount + ' competitions updated with results</li>';
                        summaryHtml += '</ul>';
                        
                        summaryHtml += '</div>';
                        
                        // Ajouter le résumé après la liste des résultats
                        $('#resultsImportList').after(summaryHtml);
                        
                        // Réactiver le bouton principal
                        $('#importButton').prop('disabled', false).text('Start Import Process');
                    }
                }
            }
            
            // Import startlists button handler (pour import manuel)
            $('#importStartlistsButton').on('click', function() {
                debugLog('Import startlists clicked');
                debugLog('Event ID:', savedEventId);
                debugLog('Competitions:', savedCompetitions);
                
                const button = $(this);
                const alertDiv = $('#alertMessage');
                const startlistResultsDiv = $('#startlistResults');
                
                if (!savedEventId || !savedCompetitions || savedCompetitions.length === 0) {
                    alertDiv.removeClass('alert-success alert-info').addClass('alert alert-danger');
                    alertDiv.text('Error: No event ID or competitions found. Please import classes first.').show();
                    return;
                }
                
                button.prop('disabled', true).text('Importing startlists...');
                
                alertDiv.removeClass('alert-success alert-danger').addClass('alert alert-info');
                alertDiv.text('Fetching startlists from Hippodata...').show();
                
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'import_startlists',
                        event_id: savedEventId,
                        competitions: JSON.stringify(savedCompetitions),
                        api_key: '<?php echo $decoded->api_key ?? ''; ?>',
                        meeting_url: '<?php echo $decoded->payload->meeting_url ?? ''; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                            alertDiv.text('Startlists fetched successfully. Ready to import to Equipe.').show();
                            
                            // Show results
                            const resultList = $('#startlistResultList');
                            resultList.empty();
                            
                            if (response.processedCompetitions) {
                                let detailedHtml = '';
                                
                                response.processedCompetitions.forEach(function(comp) {
                                    let statusText = comp.people_count + ' new riders, ' +
                                        comp.horses_count + ' new horses, ' +
                                        comp.starts_count + ' starts';
                                    
                                    // Ajouter debug info si disponible
                                    if (comp.debug) {
                                        statusText += ' (Total: ' + comp.debug.total_competitors + ' competitors)';
                                    }
                                    
                                    resultList.append(
                                        '<li><strong>' + comp.name + '</strong>: ' + statusText +
                                        '<span class="import-status status-pending" id="status-' + comp.foreign_id + '">Pending</span></li>'
                                    );
                                    
                                    // Log debug info si disponible
                                    if (comp.debug) {
                                        debugLog('Competition:', comp.name);
                                        debugLog('Debug info:', comp.debug);
                                    }
                                    
                                    // Build detailed view
                                    detailedHtml += '<div class="competition-details">';
                                    detailedHtml += '<h6>' + comp.name + '</h6>';
                                    
                                    if (comp.people_count > 0) {
                                        detailedHtml += '<div class="detail-section">';
                                        detailedHtml += '<strong>New Riders (' + comp.people_count + '):</strong>';
                                        detailedHtml += '<ul>';
                                        comp.people.forEach(function(person) {
                                            detailedHtml += '<li>' + person + '</li>';
                                        });
                                        detailedHtml += '</ul></div>';
                                    }
                                    
                                    if (comp.horses_count > 0) {
                                        detailedHtml += '<div class="detail-section">';
                                        detailedHtml += '<strong>New Horses (' + comp.horses_count + '):</strong>';
                                        detailedHtml += '<ul>';
                                        comp.horses.forEach(function(horse) {
                                            detailedHtml += '<li>' + horse + '</li>';
                                        });
                                        detailedHtml += '</ul></div>';
                                    }
                                    
                                    detailedHtml += '<div class="detail-section">';
                                    detailedHtml += '<strong>Total Starts: ' + comp.starts_count + '</strong>';
                                    detailedHtml += '</div>';
                                    
                                    detailedHtml += '</div>';
                                });
                                
                                if (debugMode && $('#detailedContent').length) {
                                    $('#detailedContent').html(detailedHtml);
                                    $('#detailedResults').show();
                                }
                                startlistResultsDiv.show();
                                
                                // Now send each batch to Equipe
                                if (response.batchData && response.batchData.length > 0) {
                                    importBatchesToEquipe(response.batchData);
                                }
                            }
                        } else {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                            alertDiv.text('Error: ' + response.error).show();
                        }
                    },
                    error: function(xhr, status, error) {
                        alertDiv.removeClass('alert-info alert-success').addClass('alert-danger');
                        alertDiv.text('Request failed: ' + error).show();
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Import Startlists for All Competitions');
                    }
                });
            });
        });
        
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
            
            console.log('Total unique people to import:', uniquePeople.length);
            console.log('Total unique horses to import:', uniqueHorses.length);
            
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
                alertDiv.removeClass('alert-success alert-danger').addClass('alert alert-info');
                alertDiv.text('Importing all riders and horses...').show();
                
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
                            alertDiv.text('Riders and horses imported. Now importing startlists...').show();
                            
                            // Étape 2: Importer les starts pour chaque compétition
                            importStartlists();
                        } else {
                            alertDiv.removeClass('alert-info').addClass('alert-danger');
                            alertDiv.text('Failed to import riders and horses: ' + response.error).show();
                            debugLog('Failed to import people and horses:', response);
                        }
                    },
                    error: function(xhr, status, error) {
                        alertDiv.removeClass('alert-info').addClass('alert-danger');
                        alertDiv.text('Failed to import riders and horses: ' + error).show();
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
                    
                    // Log pour debug
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
                                processed++;
                                $('#status-' + compStarts.competition_foreign_id).removeClass('status-pending status-error').addClass('status-success').text('Success');
                                
                                importResults.push({
                                    competition: compStarts.competition,
                                    status: 'success',
                                    transactionUuid: transactionUuid,
                                    details: compStarts.details,
                                    response: response
                                });
                            } else {
                                failCount++;
                                processed++;
                                $('#status-' + compStarts.competition_foreign_id).removeClass('status-pending status-success').addClass('status-error').text('Failed');
                                
                                debugLog('Import failed for:', compStarts.competition);
                                debugLog('Response:', response);
                                
                                importResults.push({
                                    competition: compStarts.competition,
                                    status: 'failed',
                                    transactionUuid: transactionUuid,
                                    details: compStarts.details,
                                    error: response.error
                                });
                            }
                            
                            checkComplete();
                        },
                        error: function(xhr, status, error) {
                            failCount++;
                            processed++;
                            $('#status-' + compStarts.competition_foreign_id).removeClass('status-pending status-success').addClass('status-error').text('Failed');
                            
                            debugLog('Request failed for:', compStarts.competition);
                            debugLog('Error:', error);
                            
                            importResults.push({
                                competition: compStarts.competition,
                                status: 'failed',
                                transactionUuid: transactionUuid,
                                details: compStarts.details,
                                error: error
                            });
                            
                            checkComplete();
                        }
                    });
                });
                
                function checkComplete() {
                    if (processed === total) {
                        // Show final summary
                        let summaryHtml = '<div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">';
                        summaryHtml += '<h5>Import Summary</h5>';
                        
                        if (failCount === 0) {
                            alertDiv.removeClass('alert-info alert-danger').addClass('alert-success');
                            alertDiv.text('All startlists imported successfully!').show();
                            summaryHtml += '<p class="text-success">✓ All ' + successCount + ' competitions imported successfully!</p>';
                        } else {
                            alertDiv.removeClass('alert-info alert-success').addClass('alert-warning');
                            alertDiv.text(successCount + ' startlists imported successfully, ' + failCount + ' failed.').show();
                            summaryHtml += '<p class="text-warning">⚠ ' + successCount + ' competitions imported successfully, ' + failCount + ' failed.</p>';
                        }
                        
                        // Count totals
                        summaryHtml += '<div><strong>Total imported:</strong></div>';
                        summaryHtml += '<ul>';
                        summaryHtml += '<li>' + uniquePeople.length + ' unique riders</li>';
                        summaryHtml += '<li>' + uniqueHorses.length + ' unique horses</li>';
                        summaryHtml += '<li>' + importResults.reduce((acc, r) => acc + (r.status === 'success' ? r.details.starts.length : 0), 0) + ' starts</li>';
                        summaryHtml += '</ul>';
                        
                        // Transaction UUIDs - only in debug mode
                        if (debugMode) {
                            summaryHtml += '<div style="margin-top: 10px;"><strong>Transaction UUIDs (for rollback if needed):</strong></div>';
                            summaryHtml += '<ul style="font-size: 12px;">';
                            if (uniquePeople.length > 0 || uniqueHorses.length > 0) {
                                summaryHtml += '<li>People & Horses: <code>' + transactionUuid1 + '</code></li>';
                            }
                            importResults.forEach(function(result) {
                                if (result.status === 'success') {
                                    summaryHtml += '<li>' + result.competition + ' starts: <code>' + result.transactionUuid + '</code></li>';
                                }
                            });
                            summaryHtml += '</ul>';
                        }
                        
                        summaryHtml += '</div>';
                        
                        if (debugMode && $('#detailedContent').length) {
                            $('#detailedContent').append(summaryHtml);
                        } else {
                            // Si pas en mode debug, ajouter juste le résumé sans les UUIDs
                            let simpleSummaryHtml = '<div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">';
                            simpleSummaryHtml += '<h5>Import Summary</h5>';
                            if (failCount === 0) {
                                simpleSummaryHtml += '<p class="text-success">✓ All ' + successCount + ' competitions imported successfully!</p>';
                            } else {
                                simpleSummaryHtml += '<p class="text-warning">⚠ ' + successCount + ' competitions imported successfully, ' + failCount + ' failed.</p>';
                            }
                            simpleSummaryHtml += '</div>';
                            $('#startlistResultList').after(simpleSummaryHtml);
                        }
                        
                        // Appeler le callback si fourni
                        if (typeof onCompleteCallback === 'function') {
                            onCompleteCallback();
                        } else {
                            // Réactiver le bouton principal si pas de callback
                            $('#importButton').prop('disabled', false).text('Start Import Process');
                        }
                    }
                }
            }
        }
        
        function generateUuid() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0,
                    v = c == 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }
    </script>
</body>
</html>
<?php
    }
}
?>