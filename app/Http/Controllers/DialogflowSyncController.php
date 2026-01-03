<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
use Google\Cloud\Dialogflow\V2\Intent\TrainingPhrase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DialogflowSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        // Set CORS headers
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
            'Access-Control-Allow-Credentials' => 'true'
        ];
        
        // ✅ CRITICAL: Handle OPTIONS preflight request
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200)->withHeaders($headers);
        }
        
        try {
            // Get credentials
            $credentials = $this->getDialogflowCredentials();
            
            // If no credentials, return mock data
            if (!$credentials) {
                \Log::warning('DialogflowSync: No credentials found, returning mock data');
                return response()->json([
                    'success' => true,
                    'data' => [
                        'intents_synced' => 6,
                        'intents' => [
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-1',
                                'display_name' => 'EmployeeDevelopment_StudyGrantC_016',
                                'training_phrases' => 5,
                                'responses' => 2
                            ],
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-2', 
                                'display_name' => 'EmployeeDevelopment_Seminars_003',
                                'training_phrases' => 5,
                                'responses' => 3
                            ],
                            [
                                'id' => 'projects/aihra-472311/agent/intents/mock-3',
                                'display_name' => 'Ranking_and_Promotion_Points_CES_224',
                                'training_phrases' => 4,
                                'responses' => 2
                            ]
                        ],
                        'is_mock_data' => true,
                        'message' => 'Dialogflow credentials not configured. Using mock data.'
                    ],
                    'message' => 'Using mock data - configure Dialogflow credentials'
                ])->withHeaders($headers);
            }
            
            // Get project ID
            $projectId = env('DIALOGFLOW_PROJECT_ID', 'aihra-472311');
            
            // Debug: Log connection details
            \Log::info('DialogflowSync: Attempting to connect', [
                'project_id' => $projectId,
                'client_email' => $credentials['client_email'] ?? 'unknown'
            ]);

            // Create Dialogflow client
            $client = new IntentsClient([
                'credentials' => $credentials,
                'projectId' => $projectId
            ]);

            // List intents with language code - IMPORTANT: Use correct format
            $parent = "projects/{$projectId}/agent";
            $requestObj = new ListIntentsRequest();
            $requestObj->setParent($parent);
            $requestObj->setLanguageCode('en'); // Ensure this matches your Dialogflow language
            $requestObj->setPageSize(100);
            
            \Log::info('DialogflowSync: Making API request', [
                'parent' => $parent,
                'language_code' => 'en'
            ]);

            $response = $client->listIntents($requestObj);
            $intents = [];
            $intentCount = 0;
            
            foreach ($response->iterateAllElements() as $intent) {
                $intentCount++;
                
                // Get training phrases count - FIXED METHOD
                $trainingPhrasesCount = 0;
                
                // Try multiple ways to get training phrases
                $trainingPhrases = $intent->getTrainingPhrases();
                
                if ($trainingPhrases) {
                    // Method 1: Check if it's iterable
                    if (is_iterable($trainingPhrases)) {
                        foreach ($trainingPhrases as $phrase) {
                            if ($phrase instanceof TrainingPhrase) {
                                $parts = $phrase->getParts();
                                if ($parts && (is_countable($parts) || is_iterable($parts))) {
                                    $trainingPhrasesCount++;
                                }
                            } else {
                                $trainingPhrasesCount++;
                            }
                        }
                    }
                    
                    // Method 2: If still 0, try to serialize and check
                    if ($trainingPhrasesCount === 0) {
                        $serialized = serialize($trainingPhrases);
                        if (strpos($serialized, 'TrainingPhrase') !== false) {
                            // Count occurrences of TrainingPhrase in serialized data
                            $trainingPhrasesCount = substr_count($serialized, 'TrainingPhrase');
                        }
                    }
                }
                
                // Get responses count
                $responsesCount = 0;
                $messages = $intent->getMessages();
                
                if ($messages) {
                    if (is_iterable($messages)) {
                        $responsesCount = iterator_count($messages);
                    } elseif (is_countable($messages)) {
                        $responsesCount = count($messages);
                    }
                }
                
                // Get intent details
                $intentData = [
                    'id' => $intent->getName(),
                    'display_name' => $intent->getDisplayName(),
                    'training_phrases' => $trainingPhrasesCount,
                    'responses' => $responsesCount,
                    'webhook_state' => method_exists($intent, 'getWebhookState') ? $intent->getWebhookState() : 0,
                    'is_fallback' => method_exists($intent, 'getIsFallback') ? $intent->getIsFallback() : false,
                ];
                
                $intents[] = $intentData;
                
                // Log details for debugging (first 5 intents only)
                if ($intentCount <= 5) {
                    \Log::info("DialogflowSync: Intent #{$intentCount}", [
                        'display_name' => $intent->getDisplayName(),
                        'training_phrases_count' => $trainingPhrasesCount,
                        'responses_count' => $responsesCount,
                        'id' => $intent->getName(),
                        'has_webhook' => $intentData['webhook_state'] > 0
                    ]);
                    
                    // Debug: Try to get raw training phrases data
                    if ($trainingPhrasesCount === 0 && $trainingPhrases) {
                        \Log::debug("DialogflowSync: Training phrases debug for {$intent->getDisplayName()}", [
                            'training_phrases_type' => gettype($trainingPhrases),
                            'training_phrases_class' => get_class($trainingPhrases),
                            'is_iterable' => is_iterable($trainingPhrases),
                            'is_countable' => is_countable($trainingPhrases),
                            'methods' => get_class_methods($trainingPhrases)
                        ]);
                    }
                }
            }

            \Log::info('DialogflowSync: API call completed', [
                'total_intents_fetched' => count($intents),
                'first_intent_sample' => count($intents) > 0 ? $intents[0] : null
            ]);

            // If all training phrases are 0, try alternative API approach
            $totalPhrases = array_sum(array_column($intents, 'training_phrases'));
            if ($totalPhrases === 0) {
                \Log::warning('DialogflowSync: All training phrases returned as 0, trying alternative approach');
                
                // Try without language code (get all languages)
                $requestObjNoLang = new ListIntentsRequest();
                $requestObjNoLang->setParent($parent);
                $requestObjNoLang->setPageSize(10); // Just get a few for testing
                
                $responseNoLang = $client->listIntents($requestObjNoLang);
                
                foreach ($responseNoLang->iterateAllElements() as $testIntent) {
                    if ($testIntent->getDisplayName() === $intents[0]['display_name'] ?? '') {
                        $testPhrases = $testIntent->getTrainingPhrases();
                        \Log::info('DialogflowSync: Test intent without language code', [
                            'display_name' => $testIntent->getDisplayName(),
                            'phrases_data' => $this->inspectTrainingPhrases($testPhrases)
                        ]);
                        break;
                    }
                }
            }

            // Return success with real data
            return response()->json([
                'success' => true,
                'data' => [
                    'intents_synced' => count($intents),
                    'intents' => $intents,
                    'is_mock_data' => false,
                    'message' => 'Successfully fetched ' . count($intents) . ' intents from Dialogflow',
                    'debug_info' => [
                        'project_id' => $projectId,
                        'language_code' => 'en',
                        'total_training_phrases' => $totalPhrases,
                        'api_version' => 'V2',
                        'note' => $totalPhrases === 0 ? 'Training phrases may be in a different language or format' : 'OK'
                    ]
                ],
                'message' => 'Sync successful'
            ])->withHeaders($headers);

        } catch (\Throwable $e) {
            \Log::error('DialogflowSync: Error occurred', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Dialogflow sync failed: ' . $e->getMessage(),
                'data' => [
                    'intents_synced' => 0,
                    'intents' => [],
                    'is_mock_data' => true,
                    'error' => $e->getMessage()
                ]
            ], 500)->withHeaders($headers);
        }
    }
    
    private function inspectTrainingPhrases($trainingPhrases)
    {
        if (!$trainingPhrases) {
            return 'null';
        }
        
        $result = [
            'type' => gettype($trainingPhrases),
            'class' => get_class($trainingPhrases),
            'is_iterable' => is_iterable($trainingPhrases),
            'is_countable' => is_countable($trainingPhrases),
        ];
        
        if (is_iterable($trainingPhrases)) {
            $count = 0;
            $sample = [];
            foreach ($trainingPhrases as $phrase) {
                $count++;
                if ($count <= 3) {
                    if ($phrase instanceof TrainingPhrase) {
                        $parts = $phrase->getParts();
                        $text = '';
                        if ($parts) {
                            foreach ($parts as $part) {
                                if (method_exists($part, 'getText')) {
                                    $text .= $part->getText();
                                }
                            }
                        }
                        $sample[] = [
                            'type' => 'TrainingPhrase',
                            'text' => $text
                        ];
                    } else {
                        $sample[] = [
                            'type' => gettype($phrase),
                            'value' => $phrase
                        ];
                    }
                }
                if ($count > 10) break;
            }
            $result['count'] = $count;
            $result['sample'] = $sample;
        }
        
        return $result;
    }
    
    private function getDialogflowCredentials()
    {
        \Log::info('DialogflowSync: Getting credentials');
        
        // Try to get full JSON first
        $fullJson = env('DIALOGFLOW_CREDENTIALS_JSON');
        
        if ($fullJson) {
            \Log::info('DialogflowSync: Found DIALOGFLOW_CREDENTIALS_JSON env variable');
            $credentials = json_decode($fullJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                \Log::info('DialogflowSync: Successfully parsed JSON credentials', [
                    'client_email' => $credentials['client_email'] ?? 'unknown',
                    'project_id' => $credentials['project_id'] ?? 'unknown'
                ]);
                return $credentials;
            } else {
                \Log::error('DialogflowSync: Failed to parse JSON credentials', [
                    'json_error' => json_last_error_msg()
                ]);
            }
        }
        
        // If no full JSON, try to build from pieces
        $privateKey = env('DIALOGFLOW_PRIVATE_KEY');
        $clientEmail = env('DIALOGFLOW_CLIENT_EMAIL');
        
        if ($privateKey && $clientEmail) {
            \Log::info('DialogflowSync: Building credentials from env variables', [
                'client_email' => $clientEmail,
                'has_private_key' => !empty($privateKey)
            ]);
            
            // Build credentials array from pieces
            return [
                'type' => 'service_account',
                'project_id' => env('DIALOGFLOW_PROJECT_ID', 'aihra-472311'),
                'private_key_id' => env('DIALOGFLOW_PRIVATE_KEY_ID', '24cbcd0644cf59089189291679ee9463f1c8c9a9'),
                'private_key' => $this->formatPrivateKey($privateKey),
                'client_email' => $clientEmail,
                'client_id' => env('DIALOGFLOW_CLIENT_ID', '116880942476929227852'),
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => env('DIALOGFLOW_CLIENT_X509_CERT_URL', 'https://www.googleapis.com/robot/v1/metadata/x509/aihra-dialogflow%40aihra-472311.iam.gserviceaccount.com')
            ];
        }
        
        // Last resort: check for file
        $filePath = storage_path('app/dialogflow.json');
        if (file_exists($filePath)) {
            \Log::info('DialogflowSync: Found credentials file', ['path' => $filePath]);
            return $filePath;
        }
        
        \Log::warning('DialogflowSync: No credentials found');
        // Instead of throwing exception, return null for mock data
        return null;
    }
    
    private function formatPrivateKey($privateKey)
    {
        \Log::debug('DialogflowSync: Formatting private key');
        
        if (empty($privateKey)) {
            \Log::error('DialogflowSync: Private key is empty');
            return '';
        }
        
        // Ensure the private key has proper line breaks
        $key = str_replace(['\n', '\\n'], "\n", $privateKey);
        
        // Add BEGIN/END markers if missing
        if (!str_contains($key, 'BEGIN PRIVATE KEY')) {
            $key = "-----BEGIN PRIVATE KEY-----\n" . $key . "\n-----END PRIVATE KEY-----\n";
        }
        
        return $key;
    }
}
