<?php

namespace App\Http\Controllers;

use Google\Cloud\Dialogflow\V2\Client\IntentsClient;
use Google\Cloud\Dialogflow\V2\ListIntentsRequest;
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
                                'training_phrases' => 3,
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

            // List intents with language code
            $parent = "projects/{$projectId}/agent";
            $requestObj = new ListIntentsRequest();
            $requestObj->setParent($parent);
            $requestObj->setLanguageCode('en'); // Set language code
            $requestObj->setPageSize(100); // Set reasonable page size
            
            \Log::info('DialogflowSync: Making API request', [
                'parent' => $parent,
                'language_code' => 'en'
            ]);

            $response = $client->listIntents($requestObj);
            $intents = [];
            $intentCount = 0;
            
            foreach ($response->iterateAllElements() as $intent) {
                $intentCount++;
                
                // Get training phrases count
                $trainingPhrasesList = $intent->getTrainingPhrases();
                $trainingPhrasesCount = 0;
                
                if ($trainingPhrasesList) {
                    // For older API versions, it might be a repeated field
                    $trainingPhrasesCount = iterator_count($trainingPhrasesList);
                    
                    // Alternative method: convert to array and count
                    // $trainingPhrasesArray = iterator_to_array($trainingPhrasesList);
                    // $trainingPhrasesCount = count($trainingPhrasesArray);
                }
                
                // Get responses count
                $responsesList = $intent->getMessages();
                $responsesCount = 0;
                
                if ($responsesList) {
                    $responsesCount = iterator_count($responsesList);
                }
                
                // Get webhook state
                $webhookState = $intent->getWebhookState();
                $isFallback = false;
                
                // Try to get isFallback if method exists
                if (method_exists($intent, 'getIsFallback')) {
                    $isFallback = $intent->getIsFallback();
                }
                
                $intentData = [
                    'id' => $intent->getName(),
                    'display_name' => $intent->getDisplayName(),
                    'training_phrases' => $trainingPhrasesCount,
                    'responses' => $responsesCount,
                    'webhook_state' => $webhookState,
                    'is_fallback' => $isFallback,
                ];
                
                $intents[] = $intentData;
                
                // Log detailed info for first 3 intents
                if ($intentCount <= 3) {
                    \Log::info("DialogflowSync: Intent #{$intentCount}", [
                        'display_name' => $intent->getDisplayName(),
                        'training_phrases' => $trainingPhrasesCount,
                        'responses' => $responsesCount,
                        'id' => $intent->getName(),
                        'has_training_phrases' => $trainingPhrasesCount > 0
                    ]);
                }
            }

            \Log::info('DialogflowSync: API call completed', [
                'total_intents_fetched' => count($intents),
                'sample_count' => $trainingPhrasesCount
            ]);

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
                        'sample_intent_data' => count($intents) > 0 ? [
                            'first_intent_name' => $intents[0]['display_name'],
                            'first_intent_phrases' => $intents[0]['training_phrases']
                        ] : null
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
